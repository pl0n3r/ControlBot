#!/usr/bin/env python3
"""#1200: behavior-first synthetic regression for External API mobile contracts.

The test invokes the *real PHP modules* in committed fixture scenarios, and
independently exercises PushNotification and ReadProjection via PHP stdin.
No production, database, network, client tokens, user records or matrix reads.
CB-0254 and CB-0266 are architectural / real-world boundaries (class C);
their absence from local behavior credit is explicit, not a fabricated success.
"""
from __future__ import annotations

import copy
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "push": "tests/external_api_push_notification_scenarios.php",
    "projection": "tests/external_api_read_projection_scenarios.php",
    "gate": "tests/external_api_request_gate_scenarios.php",
    "source": "tests/external_api_session_source_scenarios.php",
}
ROWS = frozenset(f"CB-{n:04d}" for n in range(254, 274))
CLASSIFIED = {
    "CB-0254": ("C", "A mobile notification reaching a real device and a real "
                "authenticated detail fetch require iOS/transport evidence; "
                "the local contract only builds an envelope."),
    "CB-0266": ("C", "No separate RBAC/token/runner is a documented architecture "
                "boundary; the gate's actual authorization is exercised "
                "independently by CB-0267 through CB-0270."),
}
OFFLINE = ROWS - CLASSIFIED.keys()

PUSH_PHP = r"""
require 'src/ExternalApiContract.php';
require 'src/ExternalApiPushNotification.php';
use ControlBot\ExternalApi\ExternalApiPushNotification;
$raw=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
    $out=['ok'=>true,'value'=>ExternalApiPushNotification::payload($raw)];
} catch (InvalidArgumentException $e) {
    $out=['ok'=>false,'error'=>$e->getMessage()];
}
echo json_encode($out,JSON_THROW_ON_ERROR);
"""

PROJECTION_PHP = r"""
require 'src/ExternalApiContract.php';
require 'src/ExternalApiReadProjection.php';
use ControlBot\ExternalApi\ExternalApiReadProjection;
$raw=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
    $out=['ok'=>true,'value'=>ExternalApiReadProjection::cockpit(
        $raw['meta'],$raw['ventures']
    )];
} catch (InvalidArgumentException $e) {
    $out=['ok'=>false,'error'=>$e->getMessage()];
}
echo json_encode($out,JSON_THROW_ON_ERROR);
"""


def php(*args: str, payload: dict | None = None) -> dict:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI missing, cannot claim coverage")
    proc = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", *args],
        cwd=ROOT, text=True,
        input=json.dumps(payload) if payload is not None else None,
        capture_output=True, timeout=35, check=False,
    )
    if proc.returncode or proc.stderr.strip():
        raise AssertionError(
            f"PHP {args[-1] if args else 'unknown'} failed "
            f"({proc.returncode}): {proc.stderr[-350:]}"
        )
    return json.loads(proc.stdout)


def scenario(area: str, mode: str) -> dict:
    return php(str(ROOT / SCRIPTS[area]), mode)


def probe(program: str, data: dict) -> dict:
    return php("-r", program, payload=data)


class CoberturaControlbot17Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Exercise 18 distinct runtime requirements, not matrix text."""
        self.assertEqual(len(ROWS), 20)
        self.assertEqual(len(OFFLINE), 18)
        self.assertEqual(OFFLINE | CLASSIFIED.keys(), ROWS)
        self.assertEqual(set(CLASSIFIED), {"CB-0254", "CB-0266"})

        # CB-0255: actual allowlisted push envelope and opaque identities.
        p = scenario("push", "minimal")["payload"]
        self.assertEqual(set(p), {
            "version", "notification_ref", "category", "severity", "entity_ref",
            "detail_operation_id", "localization_key", "issued_at",
            "expires_at", "dedupe_key", "requires_authenticated_detail",
        })
        self.assertEqual(p["version"], 1)
        self.assertEqual(p["category"], "critical_incident")
        self.assertEqual(p["severity"], "critical")
        self.assertRegex(p["notification_ref"], r"^notification:[a-f0-9]{32}$")
        self.assertRegex(p["dedupe_key"], r"^dedupe:[a-f0-9]{32}$")
        self.assertEqual(p["entity_ref"], "controlbot:incident/incident-alpha")
        self.assertLess(p["issued_at"], p["expires_at"])

        # CB-0257: operation resolved from the actual OpenAPI catalog.
        detail = scenario("push", "detail")
        self.assertEqual(detail["operation"], "owner_inbox.read")
        self.assertTrue(detail["is_public_get"])
        self.assertTrue(detail["requires_authenticated_detail"])
        self.assertTrue(detail["post_operation"])
        self.assertTrue(detail["unknown_operation"])

        # CB-0258 / 0259: actual deterministic, pure delivery decisions.
        policy = scenario("push", "policy")
        self.assertEqual(policy["deliver"]["decision"], "deliver")
        self.assertEqual(policy["deliver"]["reasons"], [])
        self.assertEqual(policy["category_off"]["reasons"], ["preference_disabled"])
        self.assertEqual(policy["default_quiet"]["decision"], "suppress")
        self.assertFalse(policy["default_quiet"]["preference_enabled"])
        for kind, reason in (
            ("expired", "expired"),
            ("duplicate", "duplicate"),
            ("rate_limited", "rate_limited"),
        ):
            with self.subTest(policy=kind):
                self.assertEqual(policy[kind]["decision"], "suppress")
                self.assertEqual(policy[kind]["reasons"], [reason])
        self.assertFalse(policy["dedupe_exact_window"]["duplicate"])
        self.assertEqual(policy["dedupe_exact_window"]["decision"], "deliver")
        self.assertEqual(policy["dedupe_window_elapsed"]["decision"], "deliver")
        self.assertEqual(policy["rate_window_elapsed"]["decision"], "deliver")

        # CB-0260, 0261, 0262, 0265: real public ResponseMeta validation.
        f = scenario("projection", "freshness")
        for name in ("current", "stale", "unknown"):
            self.assertEqual(f[name]["meta"]["freshness"]["state"], name)
        self.assertIsNone(f["unknown"]["meta"]["freshness"]["observed_at"])
        self.assertIsNone(f["unknown"]["meta"]["freshness"]["source_ref"])
        self.assertTrue(f["unknown_with_source"])

        # CB-0263: cockpit exposes precisely venture state and counters.
        cockpit = scenario("projection", "cockpit")
        self.assertEqual(set(cockpit["data"]), {"ventures"})
        v = cockpit["data"]["ventures"][0]
        self.assertEqual(set(v), {
            "venture_ref", "business_state", "technical_state",
            "pending_decisions", "critical_events",
        })
        self.assertEqual(v["venture_ref"], "controlbot:venture/condor")
        self.assertEqual((v["business_state"], v["technical_state"]),
                         ("current", "stale"))
        self.assertEqual((v["pending_decisions"], v["critical_events"]), (2, 1))

        # CB-0264: inbox projects public fields, not internal decisions.
        inbox = scenario("projection", "inbox")
        self.assertEqual(set(inbox["data"]), {"entries"})
        entries = inbox["data"]["entries"]
        self.assertEqual([e["kind"] for e in entries], ["DECISION", "WATCH"])
        self.assertEqual(set(entries[0]), {
            "entry_id", "kind", "venture_ref", "title", "summary",
            "decision_id", "deadline_at",
        })
        self.assertIsNone(entries[1]["decision_id"])
        self.assertIsNone(entries[1]["venture_ref"])

        # CB-0267, CB-0268: server-verified session and access stay bound;
        # step-up never transforms an access DENY into ALLOW.
        read = scenario("gate", "read")
        self.assertEqual(read["valid"]["decision"], "allow")
        self.assertEqual(read["valid"]["capability"], "owner.cockpit.read")
        self.assertFalse(read["valid"]["mutation"])
        self.assertIsNone(read["valid"]["step_up_ref"])
        denied = scenario("gate", "deny")
        self.assertEqual(denied["decision"], "deny")
        self.assertIn("capability_not_granted", denied["reasons"])

        # CB-0269: mutation needs the same device/session/scope step-up.
        mutation = scenario("gate", "mutation")
        self.assertEqual(mutation["without"]["decision"], "step_up_required")
        self.assertIsNone(mutation["without"]["step_up_ref"])
        self.assertEqual(mutation["with"]["decision"], "allow")
        self.assertEqual(mutation["with"]["reasons"], ["authorized_with_step_up"])

        # CB-0270: output carries audit-safe refs, no caller-provided policy.
        self.assertEqual(set(mutation["with"]), {
            "decision", "reasons", "operation_id", "capability", "scope",
            "mutation", "session_ref", "device_ref", "step_up_ref",
        })
        self.assertTrue(mutation["with"]["mutation"])
        self.assertRegex(mutation["with"]["session_ref"],
                         r"^session:[a-f0-9]{32}$")
        self.assertRegex(mutation["with"]["device_ref"],
                         r"^device:[a-f0-9]{32}$")
        self.assertRegex(mutation["with"]["step_up_ref"],
                         r"^stepup:[a-f0-9]{32}$")

        # CB-0271, 0272, 0273: genuinely resolve once through server source.
        source = scenario("source", "source")
        self.assertEqual(source["calls"], 1)
        summary = source["summary"]
        self.assertEqual(summary["source_ref"], "controlbot:session-source/primary")
        for ref, field in (("device_ref", "device"),
                           ("session_ref", "session"),
                           ("step_up_ref", "step")):
            self.assertEqual(summary[ref], source[field][ref])
        self.assertEqual(summary["freshness"], "fresh")
        minted = scenario("source", "mint")
        self.assertTrue(minted["constructor_private"])
        self.assertTrue(minted["serialize_rejected"])
        self.assertEqual(minted["public_methods"], [
            "__serialize", "__unserialize", "device", "fromSource",
            "safeSummary", "session", "stepUp",
        ])

    def test_falla_cerrado(self) -> None:
        """Reject real PHP bad inputs, expired sessions, secret-bearing extras."""
        # CB-0255/0256/0257: independent stdin probes exercise the PHP code
        # directly, including forbidden envelope fields and operation IDs.
        p = scenario("push", "minimal")["payload"]
        p.pop("requires_authenticated_detail")
        self.assertTrue(probe(PUSH_PHP, p)["ok"])
        attempts = {
            "title": "untrusted free text",
            "body": "untrusted body",
            "device_token": "not-an-opaque-reference",
            "email": "synthetic@example.invalid",
            "provider_credential": "not-a-real-secret",
            "authority": "owner",
            "options": [],
        }
        for key, value in attempts.items():
            with self.subTest(extra=key):
                mutated = dict(p, **{key: value})
                self.assertFalse(probe(PUSH_PHP, mutated)["ok"])
        for key, val in (
            ("detail_operation_id", "owner_decision.decide"),
            ("detail_operation_id", "unknown.get"),
            ("entity_ref", "controlbot:incident/password.value"),
            ("category", "marketing"),
            ("severity", "emergency"),
            ("localization_key", "push.unknown"),
            ("expires_at", p["issued_at"]),
            ("version", 2),
        ):
            with self.subTest(invalid=key, value=str(val)):
                self.assertFalse(probe(PUSH_PHP, dict(p, **{key: val}))["ok"])
        self.assertTrue(all(scenario("push", "closed").values()))

        # CB-0260–0265: fresh metadata, public-only projections and denial
        # of state upgrades, added keys, invalid timestamps and internal refs.
        c = scenario("projection", "cockpit")
        raw_meta = dict(c["meta"])
        raw_meta.pop("version")
        raw = {"meta": raw_meta, "ventures": c["data"]["ventures"]}
        self.assertTrue(probe(PROJECTION_PHP, raw)["ok"])
        invalid = scenario("projection", "invalid")
        self.assertTrue(all(invalid.values()), invalid)
        attempts = [
            {"meta": dict(raw_meta, extra_internal="not-public"),
             "ventures": raw["ventures"]},
            {"meta": dict(raw_meta, freshness={
                "state": "unknown", "observed_at": 1900,
                "source_ref": "controlbot:cockpit/group-health",
             }), "ventures": raw["ventures"]},
            {"meta": raw_meta,
             "ventures": [dict(raw["ventures"][0],
                               venture_ref="controlbot:venture/api_key.value")]},
            {"meta": raw_meta,
             "ventures": [dict(raw["ventures"][0], business_state="healthy")]},
        ]
        for index, payload in enumerate(attempts):
            with self.subTest(projection=index):
                self.assertFalse(probe(PROJECTION_PHP, payload)["ok"])

        # CB-0267–0270: an expired/revoked/wrong-session authority cannot
        # be rescued by step-up, and raw client arrays cannot mint access.
        self.assertTrue(scenario("gate", "read")["revoked_device"])
        self.assertTrue(all(scenario("gate", "auth_fail").values()))
        self.assertTrue(all(scenario("gate", "client").values()))
        self.assertTrue(all(scenario("gate", "trusted_mismatch").values()))

        # CB-0271–0273: query freshness, identity/scope/step-up provenance,
        # revocation and expiration are validated from the real PHP source.
        self.assertTrue(all(scenario("source", "fail_closed").values()))

    def test_sin_datos_reales(self) -> None:
        """Check the actual output boundary, not a claim about live state."""
        envelope = scenario("push", "minimal")["payload"]
        self.assertTrue(envelope["requires_authenticated_detail"])
        for name in (
            "title", "body", "amount", "email", "device_token", "provider",
            "credential", "token", "password", "authority", "capability",
        ):
            self.assertNotIn(name, envelope)
        no_leak = scenario("projection", "no_leak")
        self.assertTrue(no_leak["blocked_internal"])
        keys = set()

        def collect(node: object) -> None:
            if isinstance(node, dict):
                for key, value in node.items():
                    keys.add(key.lower())
                    collect(value)
            elif isinstance(node, list):
                for value in node:
                    collect(value)

        collect(no_leak["cockpit"])
        collect(no_leak["inbox"])
        for forbidden in (
            "internal_owner_email", "password", "api_key",
            "provider_credential", "device_token", "authorization",
        ):
            self.assertNotIn(forbidden, keys)

        secret = scenario("source", "secrets")
        self.assertTrue(all(secret["bad"].values()))
        for forbidden in (
            "access_token", "cookie", "otp", "secret", "credential",
            "private_key", "public_key", "capability", "authority_level",
            "policy_ref", "decision",
        ):
            self.assertNotIn(forbidden,
                             json.dumps(secret["summary"]).lower())
        self.assertEqual(scenario("source", "boundary"), secret["summary"])

    def test_validar_exigido(self) -> None:
        """Executable AC-04 prerequisite; GitHub CI validates its own HEAD."""
        # The CI gate itself must still finish terminal-success; no unit test
        # can assert that an unfinished GitHub workflow succeeded.
        expected = {
            "push": "src/ExternalApiPushNotification.php",
            "projection": "src/ExternalApiReadProjection.php",
            "gate": "src/ExternalApiRequestGate.php",
            "source": "src/ExternalApiSessionSource.php",
        }
        for area, module in expected.items():
            with self.subTest(area=area):
                self.assertTrue((ROOT / module).is_file())
                self.assertTrue((ROOT / SCRIPTS[area]).is_file())
        self.assertEqual(scenario("push", "policy")["default_quiet"]["decision"],
                         "suppress")
        self.assertEqual(scenario("projection", "freshness")
                         ["unknown"]["meta"]["freshness"]["state"], "unknown")
        self.assertEqual(scenario("gate", "deny")["decision"], "deny")
        self.assertEqual(scenario("source", "source")["calls"], 1)


if __name__ == "__main__":
    unittest.main()

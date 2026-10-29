"""#1223: LEX contracts exercised against real PHP using synthetic fixtures only.

Historical legal provenance and UI operation are not asserted by local tests.
No network, external providers, secrets, real customers or legal approval.
"""
from __future__ import annotations

import copy
import hashlib
import json
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCOPE = "venture:fixture"
NOW = 1500

PHP = r"""
foreach (['LexCore', 'LexJurisdiction', 'LexGate', 'LexWatch', 'LexRuntime'] as $name) {
    require 'src/' . $name . '.php';
}
$raw = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    $payload = $raw['payload'];
    $now = $raw['now'] ?? 1500;
    $value = match ($raw['operation']) {
        'runtime' => \ControlBot\Legal\LexRuntime::evaluate($payload, $now),
        'core' => \ControlBot\Legal\LexCore::registry($payload, $now),
        'gate' => \ControlBot\Legal\LexGate::evaluate($payload, $now),
        'batch' => \ControlBot\Legal\LexGate::evaluateBatch($payload, $now),
        'pack' => \ControlBot\Legal\LexJurisdiction::normalize($payload, $now),
        'watch' => \ControlBot\Legal\LexWatch::normalize($payload, $now),
        default => throw new \InvalidArgumentException('operation unsupported'),
    };
    echo json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR), "\n";
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e),
        'detail' => $e->getMessage()], JSON_THROW_ON_ERROR), "\n";
}
"""


def ref(value: str) -> str:
    return "controlbot:fixture/" + value


def pack(jurisdiction="country:MX", kind="country", identity="mx"):
    return {
        "schema_version": 1, "pack_id": "pack-" + identity + "-fixture",
        "jurisdiction": jurisdiction, "kind": kind, "pack_version": "1.0.0",
        "state": "active", "freshness": "fresh", "reviewed_at": 900,
        "expires_at": 3000, "responsible_refs": [ref("role/legal")],
        "sources": [{"source_id": "official-fixture", "authority": "Fictional authority",
                     "title": "Fixture document", "uri": "https://example.invalid/fictional",
                     "reviewed_at": 900}],
        "controls": [{"control_id": "fixture-control", "domain": "privacy",
                      "requirement_ref": ref("requirement/privacy"),
                      "source_refs": ["official-fixture"],
                      "human_review_required": True}],
        "assumption_refs": [], "exclusion_refs": [],
        "compatibility": {"lex_core_contract": 1, "deprecated_by": None},
    }


def obligation(state="gap", freshness="fresh"):
    return {
        "obligation_id": "obligation-fixture", "scope": SCOPE,
        "market_id": "market-fixture", "jurisdiction_pack_id": "pack-mx-fixture",
        "domain": "privacy", "requirement_ref": ref("requirement/privacy"),
        "reported_status": state, "source_refs": [ref("source/fixture")],
        "evidence_refs": [ref("evidence/fixture")] if state == "compliant" else [],
        "observed_at": 1000, "freshness": freshness,
        "responsible_ref": ref("role/legal"), "reviewed_at": 1100,
        "expires_at": 2900, "next_review_at": 2500,
        "severity": "medium", "human_review_required": True,
        "justification_ref": ref("justification/fixture") if state == "not_applicable" else None,
        "assumption_refs": [],
    }


def registry(state="gap", freshness="fresh"):
    return {"version": 1, "scope": SCOPE,
            "obligations": [obligation(state, freshness)]}


def gate(kind="executable_gap", freshness="fresh"):
    return {
        "version": 1, "kind": kind, "gap_id": "fixture-gap", "scope": SCOPE,
        "question": "Que prueba falta para esta obligacion?", "freshness": freshness,
        "severity": "high", "policy_ref": ref("policy/fixture"),
        "evidence_refs": [ref("evidence/fixture")], "observed_at": 1200,
        "work_type": "compliance_review", "requested_capabilities": ["legal_review"],
        "required_roles": ["legal_privacidad"], "group_id": "fixture-group",
        "venture_id": "fixture", "project_id": "fixture",
        "repository_ref": "example/fixture", "authority_level": "standard",
        "producer_ref": ref("producer/lex"),
    }


def signal(source_state="verified", freshness="fresh"):
    verified = source_state == "verified"
    return {
        "signal_id": "signal-fixture", "jurisdiction": "MX",
        "regulation_ref": ref("regulation/example"), "change_ref": ref("change/example"),
        "source_state": source_state, "source_refs": [ref("source/example")],
        "evidence_refs": [ref("evidence/example")] if verified else [],
        "impact_state": "plausible", "impact_refs": [ref("venture/fixture")],
        "published_at": 1000 if verified else None,
        "effective_at": 1100 if verified else None,
        "observed_at": 1300, "freshness": freshness,
    }


def runtime():
    return {
        "version": 1, "scope": SCOPE,
        "market": {"market_id": "market-fixture", "mode": "country",
                   "jurisdictions": ["country:MX"], "source_ref": ref("market/fixture")},
        "packs": [pack()], "registry_before": registry("gap"),
        "gate": gate(), "registry_after": registry("compliant"),
        "watch": {"version": 1, "signals": [signal()]},
    }


def run_php(operation: str, payload: object, now=NOW) -> dict:
    for source in ("LexCore.php", "LexJurisdiction.php", "LexGate.php",
                   "LexWatch.php", "LexRuntime.php"):
        if not (ROOT / "src" / source).is_file():
            raise AssertionError("LEX production source missing: " + source)
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP absent: real behavior unverified")
    allowed = ("PATH", "LANG", "LC_ALL", "CONTROLBOT_REAL_PHP",
               "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP", "CONTROLBOT_PHP_COVERAGE_DIR",
               "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE")
    env = {k: os.environ[k] for k in allowed if k in os.environ}
    child = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP], cwd=ROOT, env=env,
        input=json.dumps({"operation": operation, "payload": payload, "now": now}),
        text=True, capture_output=True, timeout=20, check=False,
    )
    if child.returncode:
        raise AssertionError("local PHP did not run: " + child.stderr[:300])
    return json.loads(child.stdout)


class CoberturaControlbot40Tests(unittest.TestCase):
    def ok(self, operation, payload, now=NOW):
        result = run_php(operation, payload, now)
        self.assertTrue(result["ok"], result)
        return result["value"]

    def rejected(self, operation, payload, now=NOW):
        result = run_php(operation, payload, now)
        self.assertFalse(result["ok"], "invalid input accepted")
        self.assertIn(result["error"], ("InvalidArgumentException", "TypeError"))

    def test_contrato_principal(self):
        # CB-0646/47: real composition, deterministic and read-only.
        data = runtime()
        first = self.ok("runtime", data)
        self.assertEqual(first, self.ok("runtime", copy.deepcopy(data)))
        self.assertEqual(first["before"]["summary"]["counts"]["gap"], 1)
        self.assertEqual(first["after"]["summary"]["counts"]["compliant"], 1)
        self.assertEqual(first["reevaluation_state"], "compliant")
        self.assertFalse(first["provider_executed"])
        self.assertEqual((first["factory_authority"], first["authority_effect"]),
                         ("unchanged", "none"))
        self.assertEqual(first["gate"]["work_item"]["origin_system"], "controlbot")
        self.assertEqual(first["watch"]["review_candidates"][0]["kind"], "legal_review")

        # CB-0648/0660: global must select concrete packs; generic jurisdictions.
        both = runtime()
        both["market"]["mode"] = "global"
        both["market"]["jurisdictions"] = ["region:LATAM", "country:MX"]
        both["packs"].append(pack("region:LATAM", "region", "region"))
        covered = self.ok("runtime", both)
        self.assertEqual(covered["market"]["mode"], "global")
        self.assertEqual(covered["jurisdiction_state"], "covered")
        self.assertEqual([p["jurisdiction"] for p in covered["selected_packs"]],
                         ["country:MX", "region:LATAM"])
        self.assertEqual(self.ok("pack", pack("supranational:EU", "supranational", "eu"))["kind"],
                         "supranational")

        # CB-0649/0652: four legal statuses and no compliance without evidence.
        for state in ("compliant", "gap", "unknown", "not_applicable"):
            with self.subTest(legal_state=state):
                core = self.ok("core", registry(state))
                self.assertEqual(core["obligations"][0]["status"], state)
                self.assertEqual(core["summary"]["counts"][state], 1)
        stale = self.ok("core", registry("compliant", "stale"))
        self.assertEqual(stale["obligations"][0]["status"], "unknown")
        self.assertEqual(stale["summary"]["counts"]["compliant"], 0)

        # CB-0651: rumors, stale or unknown sources never produce trusted reviews.
        self.assertTrue(self.ok("watch", {"version": 1, "signals": [signal()]})
                        ["review_candidates"])
        for origin, freshness in (("rumor", "fresh"), ("unknown", "fresh"),
                                  ("verified", "stale"), ("verified", "unknown")):
            with self.subTest(origin=origin, freshness=freshness):
                watch = self.ok("watch", {"version": 1, "signals": [signal(origin, freshness)]})
                self.assertEqual(watch["review_candidates"], [])
                self.assertEqual(watch["signals"][0]["publication_state"], "unknown")
                self.assertEqual(watch["signals"][0]["effective_state"], "unknown")

        # CB-0653/54/58: legal human gate scoped, default B, pending not approval.
        human = self.ok("gate", gate("material_uncertainty"))
        gate_result = human["human_gate"]
        self.assertEqual(gate_result["title_simple"], gate()["question"])
        self.assertEqual(gate_result["blocks"], SCOPE)
        self.assertEqual((gate_result["safe_default"], gate_result["recommendation"]),
                         ("B", "B"))
        self.assertEqual((human["ready_hint"], human["auto_execute"],
                          human["authority_effect"]), (False, False, "none"))
        self.assertIsNone(human["work_item"])

        # CB-0655/56/57: stable idempotency and freshness are hints, not rights.
        work = self.ok("gate", gate())
        item = work["work_item"]
        expected = "lex-gap:" + hashlib.sha256(
            "\n".join((SCOPE, "fixture-gap", "compliance_review", ref("policy/fixture")))
            .encode("utf-8")
        ).hexdigest()
        self.assertEqual(item["idempotency_key"], expected)
        self.assertEqual(len(self.ok("batch", [gate(), gate()])), 1)
        self.assertTrue(work["ready_hint"])
        self.assertEqual((work["authority_effect"], work["auto_execute"]),
                         ("none", False))
        self.assertEqual(item["origin_mode"], "automatic")
        self.assertEqual(item["claims"], [SCOPE])
        for freshness in ("stale", "unknown"):
            with self.subTest(gate_freshness=freshness):
                self.assertFalse(self.ok("gate", gate(freshness=freshness))["ready_hint"])
        self.assertFalse(self.ok("gate", gate("material_uncertainty", "fresh"))["ready_hint"])

        # CB-0659/61/63: technical pack state is NOT legal approval.
        normalized = self.ok("pack", pack())
        self.assertEqual(normalized["evidence_state"], "current")
        self.assertEqual(normalized["controls"][0]["source_refs"], ["official-fixture"])
        self.assertTrue(normalized["controls"][0]["human_review_required"])
        self.assertNotIn("compliant", normalized)
        self.assertNotIn("market_activation", normalized)
        for change, reason in (({"freshness": "stale"}, "freshness_stale"),
                               ({"freshness": "unknown"}, "freshness_unknown"),
                               ({"compatibility": {"lex_core_contract": 2,
                                                    "deprecated_by": None}},
                                "lex_core_contract_incompatible"),
                               ({"state": "deprecated"}, "pack_deprecated")):
            altered = pack(); altered.update(change)
            result = self.ok("pack", altered)
            self.assertEqual(result["evidence_state"], "not_current")
            self.assertIn(reason, result["reasons"])
        expired = self.ok("pack", pack(), now=3000)
        self.assertEqual(expired["evidence_state"], "not_current")
        self.assertIn("pack_expired", expired["reasons"])

        # CB-0648/0652: missing current pack or human gate blocks reevaluation.
        unprotected = runtime(); unprotected["packs"] = []
        self.assertEqual(self.ok("runtime", unprotected)["reevaluation_state"], "unknown")
        unknown = runtime(); unknown["gate"] = gate("material_uncertainty")
        self.assertEqual(self.ok("runtime", unknown)["reevaluation_state"], "unknown")

    def test_falla_cerrado(self):
        # AC-02: versions, missing scopes, invalid evidence and authority.
        bad = runtime(); bad["version"] = 2
        self.rejected("runtime", bad)
        bad = runtime(); bad["market"]["jurisdictions"] = []
        self.rejected("runtime", bad)
        bad = runtime(); bad["market"]["jurisdictions"] = ["country:CO"]
        self.assertEqual(self.ok("runtime", bad)["jurisdiction_state"], "unknown")
        bad = runtime(); bad["registry_after"]["obligations"][0]["evidence_refs"] = []
        self.rejected("runtime", bad)
        bad = runtime(); bad["gate"]["scope"] = "venture:other"
        self.rejected("runtime", bad)
        bad = runtime(); bad["packs"][0]["kind"] = "region"
        self.rejected("runtime", bad)
        bad = runtime(); bad["registry_after"]["obligations"][0]["freshness"] = "unknown"
        self.assertEqual(self.ok("runtime", bad)["reevaluation_state"], "unknown")

        bad = gate("material_uncertainty"); bad["question"] = "   "
        self.rejected("gate", bad)
        bad = gate(); bad["policy_ref"] = "controlbot:secret/value"
        self.rejected("gate", bad)
        bad = gate(); bad["required_roles"] = "legal_privacidad"
        self.rejected("gate", bad)
        bad = gate(); bad["kind"] = "auto_authorize"
        self.rejected("gate", bad)
        bad = gate(); bad["observed_at"] = NOW + 1
        self.rejected("gate", bad)
        bad = gate(); bad["scope"] = "venture:outside"
        self.assertEqual(self.ok("gate", bad)["work_item"]["claims"], ["venture:outside"])

        # CB-0663: closed schema, no URL credentials, reject sensitive IDs.
        bad = pack(); del bad["sources"]
        self.rejected("pack", bad)
        bad = pack(); bad["sources"][0]["uri"] = "http://example.invalid/not-https"
        self.rejected("pack", bad)
        bad = pack(); bad["sources"][0]["uri"] = "https://user:pass@example.invalid/path"
        self.rejected("pack", bad)
        bad = pack(); bad["pack_id"] = "secret-key"
        self.rejected("pack", bad)
        bad = pack(); bad["controls"][0]["source_refs"] = ["unknown-fixture"]
        self.rejected("pack", bad)
        bad = pack(); bad["reviewed_at"] = NOW + 1
        self.rejected("pack", bad)
        bad = pack(); bad["sources"].append(copy.deepcopy(bad["sources"][0]))
        self.assertEqual(len(self.ok("pack", bad)["sources"]), 1)
        bad = pack(); bad["sources"].append(copy.deepcopy(bad["sources"][0]))
        bad["sources"][1]["title"] = "conflict"
        self.rejected("pack", bad)
        bad = pack(); bad["controls"].append(copy.deepcopy(bad["controls"][0]))
        self.assertEqual(len(self.ok("pack", bad)["controls"]), 1)
        bad = pack(); bad["controls"].append(copy.deepcopy(bad["controls"][0]))
        bad["controls"][1]["domain"] = "consumer"
        self.rejected("pack", bad)

        bad = registry("compliant"); bad["obligations"][0]["evidence_refs"] = []
        self.rejected("core", bad)
        bad = {"version": 1, "signals": [signal()]}
        bad["signals"][0]["source_state"] = "unknown"
        self.rejected("watch", bad)
        bad = runtime(); bad["market"]["mode"] = "multi_country"
        bad["market"]["jurisdictions"] = ["country:MX", "country:AR"]
        self.assertEqual(self.ok("runtime", bad)["reevaluation_state"], "unknown")

    def test_sin_datos_reales(self):
        # AC-03: reserved .invalid domain and fictional references only.
        payload = runtime()
        self.assertEqual(payload["scope"], SCOPE)
        self.assertEqual(payload["market"]["jurisdictions"], ["country:MX"])
        self.assertEqual(payload["packs"][0]["sources"][0]["uri"],
                         "https://example.invalid/fictional")
        serialized = json.dumps(payload).lower()
        for forbidden in ("password", "bearer ", "api_key", "private_key", "@", ".com"):
            self.assertNotIn(forbidden, serialized)
        data = self.ok("runtime", payload)
        self.assertFalse(data["provider_executed"])
        self.assertEqual(data["factory_authority"], "unchanged")
        self.assertEqual(data["authority_effect"], "none")
        self.assertFalse(data["gate"]["auto_execute"])
        self.assertEqual(data["after"]["obligations"][0]["evidence_refs"],
                         [ref("evidence/fixture")])


if __name__ == "__main__":
    unittest.main()

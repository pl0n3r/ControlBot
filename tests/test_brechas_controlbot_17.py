#!/usr/bin/env python3
"""ControlBot #1024: behavioral contrasts CB-0242..CB-0256, offline only.

Existing PHP contract scenarios are synthetic; passing is not evidence of
production availability, mobile delivery, or an authorized external action.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{number:04d}" for number in range(242, 257))
UNKNOWN = "UNKNOWN"
SCRIPTS = {
    "owner": "external_api_owner_decision_read_scenarios.php",
    "e2e": "external_api_owner_e2e_scenarios.php",
    "audit": "external_api_mobile_audit_scenarios.php",
    "mobile": "external_api_mobile_state_scenarios.php",
    "envelope": "external_api_push_envelope_scenarios.php",
    "push": "external_api_push_notification_scenarios.php",
}


@lru_cache(maxsize=32)
def scenario(domain: str, case: str) -> dict:
    if domain not in SCRIPTS:
        raise AssertionError("Fixture not in the offline allowlist")
    result = subprocess.run(
        ["php", str(ROOT / "tests" / SCRIPTS[domain]), case],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=35,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError(
            f"PHP scenario {domain}/{case} failed: {result.stderr[:250]}"
        )
    try:
        data = json.loads(result.stdout)
    except (json.JSONDecodeError, TypeError) as error:
        raise AssertionError(f"Ambiguous fixture {domain}/{case}") from error
    if not isinstance(data, dict):
        raise AssertionError(f"Non-object fixture {domain}/{case}")
    return data


def matrix_states(source: str) -> dict[str, str]:
    """Sparse/duplicated/invalid rows cannot be promoted to verified."""
    rows: dict[str, list[list[str]]] = {item: [] for item in IDS}
    for line in source.splitlines():
        if not line.startswith("| CB-"):
            continue
        cells = [part.strip() for part in line.strip("|").split("|")]
        if cells and cells[0] in rows:
            rows[cells[0]].append(cells)
    states = {}
    for item, found in rows.items():
        if (len(found) == 1 and len(found[0]) == 6
                and found[0][3] == "NO_VERIFICABLE"
                and "UNKNOWN" in found[0][4]):
            states[item] = "NO_VERIFICABLE"
        else:
            states[item] = UNKNOWN
    return states


def operational_state(_item: str, _evidence: object = None) -> str:
    """Offline fixtures never establish live/mobile delivery readiness."""
    return UNKNOWN


class BrechasControlbot17Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each clause has an independent executable assertion."""
        decision = scenario("owner", "contract")["valid"]
        unknown_decision = scenario("owner", "privacy")["unknown"]
        read_auth = scenario("owner", "invalid_auth")
        flow = scenario("e2e", "flow")
        invalid = scenario("e2e", "invalid_state")
        audit = scenario("audit", "refs")
        mobile = scenario("mobile", "mutation")
        envelope = scenario("envelope", "minimal")
        deep_links = scenario("envelope", "deep_link")
        freshness = scenario("envelope", "freshness")
        push = scenario("push", "minimal")
        detail = scenario("push", "detail")
        required_detail = {
            "decision_id", "category", "title", "question",
            "options", "state", "deadline_at",
        }
        allowed_notification = {
            "version", "notification_ref", "category", "severity",
            "entity_ref", "detail_operation_id", "localization_key",
            "issued_at", "expires_at", "dedupe_key",
            "requires_authenticated_detail",
        }
        trace = flow["request_ids"]
        verdicts = {
            "CB-0242": (
                set(decision) == {"meta", "data"}
                and set(decision["data"]) == required_detail
                and decision["data"]["decision_id"] == "decision-alpha"
                and decision["data"]["state"] == "pending"
            ),
            "CB-0243": (
                decision["meta"]["freshness"]["state"] == "current"
                and unknown_decision["meta"]["freshness"] == {
                    "state": "unknown", "observed_at": None, "source_ref": None
                }
                and all(read_auth.values())
            ),
            "CB-0244": (
                audit["handoff"]["work_item_ref"] == "controlbot:work/item-abc"
                and audit["verified"]["result_ref"] == "controlbot:result/abc"
                and flow["approved"]["audit"]["factory_handoff"]["request_id"]
                    == trace["request_id"]
                and flow["verification"]["correlation_id"]
                    == trace["correlation_id"]
                and flow["verification"]["work_item_ref"]
                    == flow["approved"]["work_item_ref"]
            ),
            "CB-0245": (
                mobile["stale"]["freshness_gate"] == "fail"
                and mobile["unknown"]["freshness_gate"] == "fail"
                and mobile["fresh"]["freshness_gate"] == "pass"
                and not mobile["unknown"]["queueable"]
            ),
            "CB-0246": (
                all(
                    invalid[state]["policy"]["freshness_gate"] == "fail"
                    and not invalid[state]["policy"]["queueable"]
                    and invalid[state]["origin"] is None
                    for state in ("stale", "unknown")
                )
                and invalid["no_step"] is True
                and invalid["revoked"] is True
            ),
            "CB-0247": (
                flow["result_push"]["category"] == "decision_result"
                and flow["result_push"]["requires_authenticated_detail"] is True
                and flow["result_push"]["detail_operation_id"]
                    == "owner_decision.read"
                and flow["verification"]["work_item_ref"]
                    == flow["approved"]["work_item_ref"]
            ),
            "CB-0248": (
                envelope["payload"]["type"] == "strategic_decision"
                and envelope["payload"]["target_ref"]
                    == "controlbot:owner-inbox/entry-alpha"
                and "title" not in envelope["payload"]
                and "body" not in envelope["payload"]
                and scenario("envelope", "closed")["bad_type"] is True
            ),
            "CB-0249": (
                all(envelope["extra"].values())
                and envelope["sensitive_target"] is True
                and envelope["sensitive_source"] is True
                and not any(
                    field in envelope["payload"]
                    for field in ("title", "body", "amount", "device_token",
                                  "provider_credential", "external_url")
                )
            ),
            "CB-0250": (
                set(deep_links) == {"inbox", "decision", "incident"}
                and all(
                    set(item) == {
                        "route", "target_ref",
                        "requires_authenticated_api_fetch",
                    }
                    and item["requires_authenticated_api_fetch"] is True
                    for item in deep_links.values()
                )
                and deep_links["decision"]["route"]
                    == "owner_decision_detail"
            ),
            "CB-0251": (
                all(
                    isinstance(freshness[state]["occurred_at"], int)
                    and freshness[state]["source_ref"]
                    for state in ("current", "stale")
                )
                and freshness["current_missing_source"] is True
                and freshness["stale_missing_time"] is True
            ),
            "CB-0252": (
                freshness["unknown"]["freshness"] == "unknown"
                and freshness["unknown"]["occurred_at"] is None
                and freshness["unknown"]["source_ref"] is None
                and freshness["unknown_source"] is True
                and freshness["unknown_time"] is True
            ),
            "CB-0253": (
                freshness["stale"]["freshness"] == "stale"
                and freshness["stale"]["occurred_at"]
                    == freshness["current"]["occurred_at"]
                and freshness["stale"]["source_ref"]
                    == freshness["current"]["source_ref"]
            ),
            "CB-0254": (
                push["payload"]["requires_authenticated_detail"] is True
                and detail["operation"] == "owner_inbox.read"
                and detail["is_public_get"] is True
                and detail["post_operation"] is True
                and detail["unknown_operation"] is True
            ),
            "CB-0255": (
                set(push["payload"]) == allowed_notification
                and push["payload"]["version"] == 1
                and push["payload"]["notification_ref"].startswith(
                    "notification:"
                )
                and push["payload"]["dedupe_key"].startswith("dedupe:")
                and push["payload"]["expires_at"]
                    > push["payload"]["issued_at"]
            ),
            "CB-0256": (
                all(push["extra"].values())
                and push["sensitive_entity"] is True
                and push["external_entity"] is True
                and not any(
                    field in push["payload"]
                    for field in (
                        "title", "body", "decision", "options", "amount",
                        "email", "device_token", "provider_credential", "url",
                    )
                )
            ),
        }
        self.assertEqual(set(verdicts), set(IDS))
        for clause, verified in verdicts.items():
            with self.subTest(clause=clause):
                self.assertIs(verified, True, clause)

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid auth, provenance, refs and unknown operations deny."""
        negatives = {
            "owner-options": scenario("owner", "contract"),
            "owner-mismatch": scenario("owner", "mismatch"),
            "owner-auth": scenario("owner", "invalid_auth"),
            "owner-privacy": scenario("owner", "privacy"),
            "mobile-audit": scenario("audit", "refs"),
            "mobile-auth": scenario("audit", "authz"),
            "push-envelope": scenario("envelope", "minimal"),
            "push-notification": scenario("push", "closed"),
        }
        for name, keys in (
            ("owner-options", ("duplicate_option", "invalid_state",
                               "invalid_deadline", "extra_field")),
            ("owner-mismatch", ("requested", "server")),
            ("owner-auth", ("wrong_scope", "revoked", "wrong_capability")),
            ("owner-privacy", ("email_pii", "phone_pii", "secret",
                               "dsn", "invalid_utf8")),
            ("mobile-audit", ("missing_decision", "missing_work",
                              "missing_result", "handoff_with_result")),
            ("push-envelope", ("bad_version", "bad_notification_ref",
                                "bad_correlation_id", "bad_venture_ref")),
            ("push-notification", ("bad_category", "bad_severity",
                                    "bad_localization", "bad_issued",
                                    "bad_expiry")),
        ):
            for key in keys:
                with self.subTest(domain=name, case=key):
                    self.assertIs(negatives[name][key], True)
        self.assertIn("mismatch", negatives["mobile-auth"]["horizontal"])
        self.assertIn("requires authorized", negatives["mobile-auth"]["vertical"])
        self.assertTrue(scenario("e2e", "invalid_state")["revoked"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic local adapters, no provider/PII payload transport."""
        for domain, case in (
            ("owner", "authorized"), ("e2e", "result_push"),
            ("envelope", "minimal"), ("push", "minimal"),
            ("audit", "sensitive"),
        ):
            with self.subTest(domain=domain):
                data = scenario(domain, case)
                payload = data.get("payload", data.get("event", data))
                if domain == "envelope":
                    payload = data["payload"]
                if domain == "push":
                    payload = data["payload"]
                for forbidden in (
                    "access_token", "password", "provider_credential",
                    "device_token", "api_secret", "request_payload",
                    "response_payload", "raw_payload",
                ):
                    self.assertNotIn(forbidden, json.dumps(payload).lower())
        self.assertTrue(scenario("audit", "sensitive")["extra"])
        self.assertTrue(scenario("push", "minimal")["extra"]["email"])
        self.assertTrue(scenario("envelope", "minimal")["extra"]["body"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: a green local fixture cannot promote sparse matrix or live."""
        states = matrix_states(
            (ROOT / "docs" / "matriz-cobertura.md").read_text(encoding="utf-8")
        )
        self.assertEqual(set(states), set(IDS))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertIn(states[clause], (UNKNOWN, "NO_VERIFICABLE"))
                for assertion in (None, {}, "success", "GREEN", True,
                                  {"ci": "success"}, {"push": "delivered"}):
                    self.assertEqual(operational_state(clause, assertion), UNKNOWN)
        self.assertEqual(
            scenario("owner", "privacy")["unknown"]["meta"]["freshness"],
            {"state": "unknown", "observed_at": None, "source_ref": None},
        )
        self.assertIsNone(
            scenario("mobile", "states")["unknown"]["age_seconds"]
        )
        self.assertFalse(
            scenario("e2e", "invalid_state")["unknown"]["policy"]["queueable"]
        )
        self.assertIsNone(scenario("envelope", "freshness")["unknown"]["source_ref"])


if __name__ == "__main__":
    unittest.main()

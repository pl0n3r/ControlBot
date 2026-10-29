"""#1199: verified offline PHP behavior for ControlBot external-owner API.

No matrix assertion, no live delivery, no credentials, no production effect.
Two B rows require actual mobile client / push transport (CB-0247, CB-0250).
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CASES = {
    "owner": "tests/external_api_owner_decision_read_scenarios.php",
    "e2e": "tests/external_api_owner_e2e_scenarios.php",
    "push": "tests/external_api_push_envelope_scenarios.php",
}
OFFLINE = frozenset({
    "CB-0239", "CB-0240", "CB-0241", "CB-0242", "CB-0243",
    "CB-0244", "CB-0245", "CB-0246", "CB-0248", "CB-0249",
    "CB-0251", "CB-0252", "CB-0253",
})
NON_OFFLINE = {
    "CB-0247": ("B", "Result push delivery to a real client needs transport, not only a PHP payload."),
    "CB-0250": ("B", "Installed mobile client opening and authenticated detail fetch require live mobile runtime."),
}


@lru_cache(maxsize=None)
def scenario(area: str, mode: str) -> dict:
    """Run the checked-in PHP domain scenario, never a Python model."""
    path = ROOT / CASES[area]
    if not path.is_file():
        raise AssertionError(f"UNKNOWN: missing PHP fixture {path.name}")
    exe = shutil.which("php")
    if exe is None:
        raise AssertionError("UNKNOWN: PHP CLI is not available")
    proc = subprocess.run(
        [exe, str(path), mode], cwd=ROOT, capture_output=True,
        text=True, check=False, timeout=35,
    )
    if proc.returncode or proc.stderr.strip():
        raise AssertionError(f"UNKNOWN: real PHP {area}/{mode} failed: {proc.stderr[-400:]}")
    try:
        return json.loads(proc.stdout)
    except json.JSONDecodeError as err:
        raise AssertionError("UNKNOWN: real PHP emitted invalid JSON") from err


class CoberturaControlbot16Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(15, len(OFFLINE | NON_OFFLINE.keys()))
        self.assertFalse(OFFLINE & NON_OFFLINE.keys())

        # CB-0239/0240: GET-only request gate with owner.decision.read and auth.
        authorized = scenario("owner", "authorized")
        self.assertEqual("decision-alpha", authorized["data"]["decision_id"])
        self.assertEqual("pending", authorized["data"]["state"])
        self.assertEqual("current", authorized["meta"]["freshness"]["state"])
        self.assertEqual("decision-alpha", scenario("owner", "contract")["valid"]["data"]["decision_id"])

        # CB-0241: requested ID must match server-projected ID.
        self.assertEqual({"requested": True, "server": True}, scenario("owner", "mismatch"))

        # CB-0242: exact OwnerDecisionEnvelope schema, 2 unique option keys.
        contract = scenario("owner", "contract")
        self.assertEqual({"meta", "data"}, set(contract["valid"]))
        self.assertEqual({"decision_id", "category", "title", "question",
                          "options", "state", "deadline_at"}, set(contract["valid"]["data"]))
        self.assertEqual(["A", "B"], [o["key"] for o in contract["valid"]["data"]["options"]])

        # CB-0243: responseMeta retains UNKNOWN without fake provenance.
        unknown = scenario("owner", "privacy")["unknown"]["meta"]["freshness"]
        self.assertEqual({"state": "unknown", "observed_at": None, "source_ref": None}, unknown)

        # CB-0244: MobileAudit contains matching handoff and verification refs.
        e2e = scenario("e2e", "flow")
        ids = e2e["request_ids"]
        audit = e2e["approved"]["audit"]["factory_handoff"]
        self.assertEqual("factory_handoff", audit["outcome"])
        self.assertEqual(ids["request_id"], audit["request_id"])
        self.assertEqual(ids["correlation_id"], audit["correlation_id"])
        self.assertEqual("verification_succeeded", e2e["verification"]["outcome"])
        self.assertEqual(e2e["approved"]["work_item_ref"], e2e["verification"]["work_item_ref"])

        # CB-0245/0246: stale/unknown mobile snapshot cannot authorize mutation.
        invalid_state = scenario("e2e", "invalid_state")
        for state in ("stale", "unknown"):
            self.assertEqual("fail", invalid_state[state]["policy"]["freshness_gate"])
            self.assertFalse(invalid_state[state]["policy"]["queueable"])
            self.assertIsNone(invalid_state[state]["origin"])

        # CB-0248: notification is only a minimal pointer, not authority or truth.
        envelope = scenario("push", "minimal")["payload"]
        self.assertEqual("strategic_decision", envelope["type"])
        self.assertEqual("controlbot:owner-inbox/entry-alpha", envelope["target_ref"])
        for k in ("title", "body", "amount", "approval", "authority", "evidence", "decision_state"):
            self.assertNotIn(k, envelope)

        # CB-0249: free copy, device identifiers and sensitive refs rejected.
        minimal = scenario("push", "minimal")
        self.assertTrue(all(minimal["extra"].values()))
        self.assertTrue(minimal["sensitive_target"])
        self.assertTrue(minimal["sensitive_source"])
        self.assertTrue(minimal["token_hyphen_target"])
        self.assertTrue(minimal["token_dot_target"])

        # CB-0251/0252/0253: freshness, provenance requirements and stale state.
        freshness = scenario("push", "freshness")
        self.assertEqual("current", freshness["current"]["freshness"])
        self.assertEqual("stale", freshness["stale"]["freshness"])
        self.assertIsInstance(freshness["stale"]["occurred_at"], int)
        self.assertTrue(freshness["stale"]["source_ref"])
        self.assertEqual("unknown", freshness["unknown"]["freshness"])
        self.assertIsNone(freshness["unknown"]["source_ref"])
        self.assertIsNone(freshness["unknown"]["occurred_at"])
        self.assertTrue(all(freshness[k] for k in (
            "unknown_source", "unknown_time", "current_missing_source", "stale_missing_time"
        )))

    def test_falla_cerrado(self) -> None:
        # CB-0239/0240/0241: scope, capability, revoked session and ID mismatch.
        self.assertTrue(all(scenario("owner", "invalid_auth").values()))
        self.assertTrue(all(scenario("owner", "mismatch").values()))
        contract = scenario("owner", "contract")
        for key in ("duplicate_option", "invalid_state", "invalid_deadline", "extra_field"):
            self.assertIs(contract[key], True, key)

        # CB-0245/0246: missing step-up and revoked mobile session deny origin.
        invalid = scenario("e2e", "invalid_state")
        self.assertIs(invalid["no_step"], True)
        self.assertIs(invalid["revoked"], True)
        for key in ("stale", "unknown"):
            self.assertIsNone(invalid[key]["origin"])

        # CB-0248/0249: malformed or sensitive push rejected.
        closed = scenario("push", "closed")
        self.assertIs(closed["bad_type"], True)
        self.assertIs(closed["external_target"], True)
        payload = scenario("push", "minimal")
        for key in ("bad_version", "bad_notification_ref", "bad_correlation_id",
                    "bad_venture_ref", "bad_occurred_type", "bad_occurred_zero", "bad_copy_key"):
            self.assertIs(payload[key], True, key)
        for key in ("email_pii", "phone_pii", "secret", "dsn", "invalid_utf8"):
            self.assertIs(scenario("owner", "privacy")[key], True, key)

        # CB-0251/0252: never infer freshness from missing provenance.
        provenance = scenario("push", "freshness")
        for key in ("unknown_source", "unknown_time", "current_missing_source", "stale_missing_time"):
            self.assertIs(provenance[key], True, key)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(13, len(OFFLINE))
        self.assertEqual(2, len(NON_OFFLINE))
        self.assertTrue(all(kind == "B" and reason for kind, reason in NON_OFFLINE.values()))
        self.assertTrue(all((ROOT / p).is_file() for p in CASES.values()))
        owner = scenario("owner", "privacy")["unknown"]
        serialized = json.dumps(owner, sort_keys=True).lower()
        for key in ("access_token", "refresh_token", "authorization", "session_ref",
                    "device_ref", "step_up_ref", "grant_id", "policy_ref", "authority_level"):
            self.assertNotIn(key, serialized)
        result = scenario("e2e", "result_push")
        self.assertTrue(result["requires_authenticated_detail"])
        self.assertEqual("owner_decision.read", result["detail_operation_id"])
        for key in ("title", "body", "email", "amount", "device_token", "provider_credential"):
            self.assertNotIn(key, result)
        # This tests a descriptor, NOT a real mobile client or push transport.
        for descriptor in scenario("push", "deep_link").values():
            self.assertEqual({"route", "target_ref", "requires_authenticated_api_fetch"}, set(descriptor))
            self.assertIs(descriptor["requires_authenticated_api_fetch"], True)


if __name__ == "__main__":
    unittest.main()

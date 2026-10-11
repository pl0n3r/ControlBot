"""AC-01..04: local deterministic fake grant consumption. No network or secrets."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "capability_grant_revoker_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=15,
    )
    return json.loads(result.stdout)

class CapabilityGrantRevokerTests(unittest.TestCase):
    def test_grant_revoked_after_success_and_failure(self):
        data = scenario("finish")
        self.assertEqual(data["success"]["status"], "executed")
        self.assertEqual(data["failed"]["status"], "failed")
        self.assertEqual(data["ambiguous"]["status"], "ambiguous")
        self.assertEqual(data["ambiguous"]["error_code"], "fake_execution_unconfirmed")
        self.assertEqual(data["ambiguous_after"], {"authorized": False, "reason": "grant_consumed"})
        self.assertEqual(data["failed"]["error_code"], "fake_execution_failed")
        self.assertEqual(data["success"]["revoked_at"], "2026-09-28T08:10:00Z")
        self.assertEqual(data["failed"]["revoked_at"], "2026-09-28T08:10:00Z")
        self.assertEqual(data["after"], {"authorized": False, "reason": "grant_consumed"})
        self.assertEqual(data["failed_after"], {"authorized": False, "reason": "grant_consumed"})
        # Equivalent policy and reordered grant fields must replay one receipt.
        self.assertEqual(data["replay"], data["success"], "same grant fields regardless of order")
        self.assertEqual(data["restricted_replay"]["reason"], "idempotency_collision")
        self.assertEqual(data["calls"], 1)
    def test_expired_consumed_and_reentrant_grants_are_denied(self):
        data = scenario("deny")
        self.assertEqual(data["expired"]["reason"], "grant_expired")
        self.assertEqual(data["revoked"]["reason"], "grant_revoked")
        self.assertEqual(data["reentrant"]["reason"], "grant_in_flight")
        self.assertEqual(data["collision"]["reason"], "idempotency_collision")
        self.assertEqual(data["scope_collision"]["reason"], "idempotency_collision")
        self.assertEqual(data["consumed"]["reason"], "grant_consumed")
        self.assertEqual(data["success"]["status"], "executed")
        self.assertEqual(data["calls"], 1)
        # Distinct grant IDs with the same idempotency key cannot re-enter.
        nested = scenario("inflight_key")
        self.assertEqual(nested["a"]["status"], "executed")
        self.assertEqual(nested["b_inflight"]["status"], "rejected")
        self.assertEqual(nested["b_inflight"]["reason"], "idempotency_collision")
        self.assertEqual(nested["b_authorize"], {"authorized": False, "reason": "idempotency_collision"})
        self.assertEqual(nested["b_after"]["reason"], "idempotency_collision")
        self.assertEqual(nested["separate"]["status"], "executed")
        self.assertEqual(nested["calls"], 2)  # A and independent C; never B
        for name in ("expired", "revoked", "reentrant", "collision", "scope_collision"):
            self.assertEqual(data[name]["status"], "rejected")

    def test_audit_omits_sensitive_material(self):
        data = scenario("privacy")
        self.assertEqual(data["failed"]["error_code"], "fake_execution_failed")
        serialized = json.dumps(data).lower()
        for fragment in ("private", "password", "cookie", "hidden", "bearer", "token=", "stack"):
            self.assertNotIn(fragment, serialized)
        self.assertEqual(set(data["failed"]["audit"]), {"event", "status", "at"})
    def test_existing_contract_is_preserved(self):
        data = scenario("existing")
        self.assertTrue(data["read"]["authorized"])
        self.assertEqual(data["guard_read"], data["read"])
        self.assertEqual(data["guard_strict"], {"authorized": False, "reason": "policy_denied"})
        self.assertEqual(data["fake_strict"]["reason"], "policy_denied")
        self.assertEqual(data["fake_calls"], 0)
        self.assertEqual(data["restricted"], {"authorized": False, "reason": "grant_expired"})
        self.assertEqual(data["scope_wrong"], {"authorized": False, "reason": "project_mismatch"})
        self.assertIsNone(data["original_revoked_at"])
if __name__ == "__main__":
    unittest.main()

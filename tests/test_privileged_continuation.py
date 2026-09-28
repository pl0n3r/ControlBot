import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "privileged_continuation_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class PrivilegedContinuationTests(unittest.TestCase):
    def test_approval_emits_single_scoped_grant_and_resumes_run(self):
        data = scenario("approve")

        self.assertEqual(data["status"], "approved")
        self.assertEqual(data["execution"], "resumed")
        self.assertEqual(data["grant"]["capability"], "database.destructive")
        self.assertEqual(data["grant"]["project"], "brvtal")
        self.assertEqual(data["grant"]["environment"], "production")
        self.assertEqual(data["grant"]["resource"], "database:primary")
        self.assertEqual(data["grant"]["operation"], "delete_rows")
        self.assertEqual(data["grant"]["run_id"], data["continuation"]["run_id"])
        self.assertIsNotNone(data["continuation"]["consumed_at"])
        self.assertEqual(
            data["continuation"]["resumed_at"],
            data["continuation"]["consumed_at"],
        )

    def test_rejection_records_evidence_without_execution(self):
        data = scenario("reject")

        self.assertEqual(data["status"], "rejected")
        self.assertEqual(data["execution"], "not-performed")
        self.assertIsNone(data["grant"])
        self.assertIsNotNone(data["continuation"]["consumed_at"])
        self.assertIsNone(data["continuation"]["resumed_at"])

    def test_replay_or_double_click_is_idempotent(self):
        data = scenario("replay")

        self.assertTrue(data["same"])
        self.assertEqual(
            data["first"]["grant"]["grant_id"],
            "22222222-3333-4444-8555-666666666666",
        )
        self.assertEqual(data["first"], data["second"])

    def test_sha_or_operation_change_invalidates_approval(self):
        data = scenario("drift")

        for field in ("source_sha", "operation", "plan_digest", "risk_digest"):
            self.assertNotEqual(data[field], "accepted")
            self.assertIn("drift", data[field].lower())

    def test_owner_surface_never_exposes_secret(self):
        data = scenario("surface")

        self.assertIn("material no permitido", data["unsafe"])
        serialized = json.dumps(data["surface"], sort_keys=True).lower()
        for forbidden in (
            "secret_ref",
            "password=",
            "token=",
            "bearer ",
            "private key",
            "operation_payload",
        ):
            self.assertNotIn(forbidden, serialized)

    def test_non_human_gated_operation_does_not_interrupt_owner(self):
        data = scenario("automatic")

        self.assertFalse(data["interrupted"])
        self.assertEqual(data["decision"], "automatic")
        self.assertIsNone(data["owner_action"])
        self.assertIsNone(data["continuation"])


if __name__ == "__main__":
    unittest.main()

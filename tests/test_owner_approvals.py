import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/owner_approval_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class OwnerApprovalTests(unittest.TestCase):
    def test_factory_release_executes_verified_steps(self):
        data = scenario("release")
        self.assertEqual(
            [call[0] for call in data["calls"]],
            ["mainSha", "commentIssue", "closeIssue", "moveTag", "dispatchWorkflow"],
        )
        self.assertEqual(
            data["calls"][3],
            ["moveTag", "pl0n3r/factory", "v1", "a" * 40],
        )
        self.assertEqual(data["result"]["sha"], "a" * 40)
        self.assertIn("tag", data["result"]["evidence"])
        self.assertIn("run", data["result"]["evidence"])

    def test_initial_release_does_not_move_major_tag(self):
        data = scenario("initial-release")
        self.assertEqual(
            [call[0] for call in data["calls"]],
            ["commentIssue", "closeIssue"],
        )
        self.assertIsNone(data["result"]["sha"])
        self.assertNotIn("tag", data["result"]["evidence"])
        self.assertNotIn("run", data["result"]["evidence"])

    def test_stale_sha_fails_before_mutation(self):
        data = scenario("stale")
        self.assertIn("main cambió", data["error"])
        self.assertEqual(data["calls"], ["mainSha"])

    def test_owner_reauthentication_and_audit_are_required(self):
        data = scenario("reauth")
        self.assertIn("reautenticación", data["stale"])
        self.assertEqual(data["calls"], ["commentIssue", "closeIssue"])
        self.assertGreaterEqual(len(data["audit"]), 2)
        first = json.loads(data["audit"][0])
        self.assertEqual(first["actor"], "pl0n3r")
        self.assertEqual(first["option"], "A")

    def test_money_gate_never_executes_payment(self):
        data = scenario("money")
        self.assertEqual(data["calls"], ["commentIssue", "closeIssue"])
        self.assertNotIn("moveTag", data["calls"])
        self.assertNotIn("dispatchWorkflow", data["calls"])

    def test_factory_gate_contract_is_fail_closed(self):
        data = scenario("invalid")
        self.assertIn("inválid", data["error"].lower())

    def test_gate_rejects_non_string_option_and_selector_fields(self):
        data = scenario("invalid-types")
        self.assertIn("inválid", data["error"].lower())

    def test_failed_github_step_is_audited_before_rethrow(self):
        data = scenario("failed-step")
        self.assertIn("fallo simulado", data["error"])
        self.assertEqual(data["audit"][-1]["action"], "move-v1")
        self.assertEqual(data["audit"][-1]["result"], "failed")
        self.assertIsNone(data["audit"][-1]["evidence"])


if __name__ == "__main__":
    unittest.main()

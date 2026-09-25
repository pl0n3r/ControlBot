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
            data["calls"],
            ["mainSha", "commentIssue", "closeIssue", "moveTag", "dispatchWorkflow"],
        )
        self.assertEqual(data["result"]["sha"], "a" * 40)
        self.assertIn("tag", data["result"]["evidence"])
        self.assertIn("run", data["result"]["evidence"])

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


if __name__ == "__main__":
    unittest.main()

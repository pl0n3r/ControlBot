"""Executable acceptance contract for the isolated PHP owner-approval service."""

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests" / "fixtures" / "approval_core.php"


class OwnerApprovalCoreTests(unittest.TestCase):
    def scenario(self, name: str) -> dict:
        result = subprocess.run(
            ["php", str(FIXTURE), name],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertEqual(
            result.returncode, 0,
            f"PHP fixture failure for {name}: {result.stderr}",
        )
        self.assertNotIn("Fatal error", result.stdout)
        return json.loads(result.stdout)

    def test_factory_release_executes_verified_steps(self) -> None:
        result = self.scenario("happy")
        self.assertFalse(result["denied"])
        self.assertEqual(["approval", "move", "dispatch"], result["mutations"])
        self.assertEqual("a" * 40, result["channel"])
        self.assertEqual(
            ["prepared:option:A:" + "a" * 40, "dispatched:option:A:" + "a" * 40],
            [event["result"] for event in result["audits"]],
        )
        self.assertEqual("pl0n3r", result["audits"][0]["actor"])
        self.assertEqual("factory-release", result["outcome"]["category"])

    def test_stale_unauthorized_and_audit_fail_closed(self) -> None:
        for name in (
            "stale", "other_actor", "expired", "not_authenticated",
            "audit_failure",
        ):
            with self.subTest(name=name):
                result = self.scenario(name)
                self.assertTrue(result["denied"])
                self.assertEqual([], result["mutations"])
                self.assertNotEqual("a" * 40, result["channel"])
        changed = self.scenario("changed_after_approval")
        self.assertTrue(changed["denied"])
        self.assertEqual(["approval"], changed["mutations"])
        self.assertEqual(
            ["prepared:option:A:" + "a" * 40, "reconcile-required:option:A:" + "a" * 40],
            [event["result"] for event in changed["audits"]],
        )

    def test_money_never_calls_mutation(self) -> None:
        result = self.scenario("money")
        self.assertFalse(result["denied"])
        self.assertEqual([], result["mutations"])
        self.assertEqual("b" * 40, result["channel"])
        self.assertEqual(
            ["money-decision"],
            [event["action"] for event in result["audits"]],
        )
        self.assertEqual("recorded", result["outcome"]["status"])
        decline = self.scenario("decline")
        self.assertFalse(decline["denied"])
        self.assertEqual([], decline["mutations"])
        self.assertEqual(["factory-release-declined"], [x["action"] for x in decline["audits"]])

    def test_contract_negative_cases(self) -> None:
        for name in (
            "invalid_sha", "invalid_option", "untrusted_gate",
            "malformed_gate", "go_live", "money_sha",
            "missing_approval_mapping", "invalid_approval_mapping",
        ):
            with self.subTest(name=name):
                result = self.scenario(name)
                self.assertTrue(result["denied"])
                self.assertEqual([], result["mutations"])
        failed_move = self.scenario("tag_move_failure")
        self.assertTrue(failed_move["denied"])
        self.assertEqual(["approval"], failed_move["mutations"])
        self.assertEqual(
            ["prepared:option:A:" + "a" * 40, "reconcile-required:option:A:" + "a" * 40],
            [event["result"] for event in failed_move["audits"]],
        )
        for result in (failed_move, self.scenario("audit_failure")):
            self.assertNotIn("adapter failed", json.dumps(result))
            self.assertNotIn("audit unavailable", json.dumps(result))


if __name__ == "__main__":
    unittest.main()

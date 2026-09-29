import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "scheduler_assignment_commit_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class SchedulerAssignmentCommitTests(unittest.TestCase):
    def test_commit_requires_exact_current_cas_state(self):
        data = scenario("cas")
        self.assertEqual(data["valid"]["version"], 1)
        for key in ("fabricated", "work_drift", "session_drift", "agent_drift", "clock_stale"):
            self.assertTrue(data[key], (key, data))

    def test_new_ownership_or_assignment_fails_closed_without_takeover(self):
        data = scenario("ownership")
        self.assertTrue(all(data.values()), data)

    def test_commit_set_binds_all_records_to_same_session_generation_and_source(self):
        data = scenario("binding")
        writes = data["writes"]
        self.assertEqual(writes["reservation"]["owner_session_id"], writes["session"]["session_id"])
        self.assertEqual(writes["reservation"]["generation"], writes["work_item"]["generation"])
        self.assertEqual(writes["work_item"]["assigned_session_id"], writes["session"]["session_id"])
        self.assertEqual(writes["assignment"]["session_id"], writes["session"]["session_id"])
        self.assertEqual(writes["assignment"]["source_ref"], writes["work_item"]["source_ref"])
        self.assertEqual(writes["assignment"]["project_id"], writes["work_item"]["project_id"])

    def test_commit_set_has_no_partial_success_or_intermediate_writes(self):
        data = scenario("atomic")
        self.assertEqual(data["write_keys"], ["reservation", "work_item", "session", "assignment"])
        self.assertFalse(data["has_partial"])
        self.assertEqual(data["top_keys"], [
            "version", "policy_ref", "expected", "writes", "plan_fingerprint", "commit_fingerprint"
        ])

    def test_commit_set_and_fingerprint_are_deterministic_and_state_bound(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["same_fingerprint"])
        self.assertTrue(data["state_bound"])

    def test_commit_core_has_no_persistence_runner_or_generation_mutation(self):
        data = scenario("pure")
        self.assertTrue(data["pure"], data)
        self.assertEqual(data["hits"], [])
        self.assertTrue(data["generation_preserved"])
        self.assertTrue(data["attempt_preserved"])


if __name__ == "__main__":
    unittest.main()

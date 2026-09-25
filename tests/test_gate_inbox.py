import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario_raw(name: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["php", str(ROOT / "tests/gate_inbox_scenarios.php"), name],
        cwd=ROOT,
        check=False,
        text=True,
        capture_output=True,
    )


def scenario(name: str) -> dict:
    result = scenario_raw(name)
    result.check_returncode()
    return json.loads(result.stdout)


class GateInboxTests(unittest.TestCase):
    def test_only_trusted_open_gates_are_listed(self):
        data = scenario("trusted")
        decisions = data["decisions"]
        self.assertEqual([item["issue"] for item in decisions], [5, 10])
        self.assertEqual(decisions[0]["context"], "Puerta confiable.")
        self.assertEqual(decisions[0]["blocks"], "Bloquea un flujo.")
        self.assertEqual(decisions[0]["title_simple"], "¿Continuamos?")
        self.assertNotIn(6, [item["issue"] for item in decisions])
        self.assertNotIn(7, [item["issue"] for item in decisions])
        self.assertNotIn(8, [item["issue"] for item in decisions])
        self.assertNotIn(9, [item["issue"] for item in decisions])

    def test_gate_after_first_page_is_listed(self):
        data = scenario("pagination")
        self.assertEqual([item["issue"] for item in data["decisions"]], [2201])
        self.assertEqual(data["decisions"][0]["context"], "Puerta en página dos.")
        self.assertTrue(any("page=2" in url for _, url in data["seen"]))

    def test_pagination_failure_does_not_return_partial_inbox(self):
        result = scenario_raw("pagination-failure")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, "")

    def test_factory_release_contains_sha_ci_and_evidence(self):
        data = scenario("release")
        self.assertEqual(len(data["decisions"]), 1)
        decision = data["decisions"][0]
        self.assertEqual(decision["sha"], "a" * 40)
        self.assertEqual(decision["ci"]["state"], "success")
        self.assertEqual(decision["ci"]["total"], 2)
        self.assertEqual(len(decision["ci"]["evidence"]), 2)
        self.assertEqual(decision["commit_url"], f"https://github.com/pl0n3r/factory/commit/{'a' * 40}")
        self.assertIn("/commits/" + "a" * 40 + "/check-runs", json.dumps(data["seen"]))

    def test_dispatched_run_is_correlated_to_terminal_result(self):
        success = scenario("tracker-success")["result"]
        failure = scenario("tracker-failure")["result"]
        self.assertEqual(success["state"], "success")
        self.assertTrue(success["terminal"])
        self.assertEqual(success["run_id"], 4)
        self.assertEqual(success["run_url"], "https://github.com/run/4")
        self.assertEqual(failure["state"], "failure")
        self.assertTrue(failure["terminal"])
        self.assertEqual(failure["conclusion"], "failure")

    def test_empty_inbox_has_no_synthetic_items(self):
        data = scenario("empty")
        self.assertEqual(data["decisions"], [])


if __name__ == "__main__":
    unittest.main()

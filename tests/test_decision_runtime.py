import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def raw(name: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["php", str(ROOT / "tests/decision_runtime_scenarios.php"), name],
        cwd=ROOT, check=False, text=True, capture_output=True,
    )


def scenario(name: str) -> dict:
    result = raw(name)
    result.check_returncode()
    return json.loads(result.stdout)


class DecisionRuntimeTests(unittest.TestCase):
    def test_runtime_loads_real_gate_inbox(self):
        data = scenario("render")
        self.assertEqual(data["response"]["status"], 200)
        self.assertIn("¿Publicamos Factory?", data["response"]["body"])
        self.assertIn('name="_csrf"', data["response"]["body"])
        self.assertTrue(any("/issues?state=open&per_page=100&page=1" in row[1] for row in data["seen"]))
        self.assertNotIn("fixture-server-value", json.dumps(data))

    def test_release_dispatch_is_followed_to_terminal_evidence(self):
        data = scenario("flow")
        self.assertEqual(data["approval"]["release"]["state"], "pending")
        self.assertEqual(data["status"]["state"], "success")
        self.assertTrue(data["status"]["terminal"])
        self.assertEqual(data["status"]["run_url"], "https://github.com/run/12")
        self.assertNotIn("_controlbot_release_tracking", data["session_keys"])
        self.assertNotIn("fixture-client-value", json.dumps(data))

    def test_ambiguous_runtime_tracking_is_blocked_without_attribution(self):
        data = scenario("ambiguous")
        self.assertEqual(data["approval"]["release"]["state"], "blocked")
        self.assertEqual(data["status"]["state"], "blocked")
        self.assertIsNone(data["status"]["run_url"])

    def test_repository_allowlist_is_server_side(self):
        result = raw("untrusted-repo")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, "")


if __name__ == "__main__":
    unittest.main()

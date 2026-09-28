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

    def test_runtime_exposes_owner_history(self):
        data = scenario("history")
        self.assertEqual(data["response"]["status"], 200)
        payload = json.loads(data["response"]["body"])
        self.assertEqual(len(payload["history"]), 1)
        self.assertEqual(payload["history"][0]["repository"], "pl0n3r/factory")
        self.assertEqual(payload["history"][0]["category"], "factory-release")
        self.assertEqual(payload["history"][0]["actions"], ["comment", "close-issue"])

    def test_venture_owner_required_materializes_existing_decision_flow(self):
        d=scenario("venture-materialize"); self.assertEqual(d["result"]["status"],"owner_decision_required"); self.assertTrue(d["result"]["owner_decision"]["created"]); self.assertEqual(d["created_issues"],1); self.assertEqual(d["result"]["owner_decision"]["issue"],d["materialized"][0]["number"]); self.assertIn("factory-human-gate",d["materialized"][0]["labels"]); self.assertTrue(any("labels=factory-human-gate" in r[1] for r in d["seen"]))

    def test_materialized_venture_gate_is_visible_in_inbox(self):
        d=scenario("venture-materialize"); self.assertIn("Venture access escalation",d["page"]["body"]); self.assertIn("factory-human-gate",d["materialized"][0]["body"]); self.assertEqual(d["materialized"][0]["state"],"open")

    def test_venture_gate_materialization_is_idempotent(self):
        d=scenario("venture-replay"); self.assertEqual(d["created_issues"],1); self.assertEqual(d["first"]["owner_decision"]["issue"],d["second"]["owner_decision"]["issue"]); self.assertTrue(d["first"]["owner_decision"]["created"]); self.assertFalse(d["second"]["owner_decision"]["created"])

    def test_untrusted_issue_cannot_capture_venture_idempotency(self):
        d=scenario("venture-untrusted-recovery"); self.assertEqual(d["created_issues"],1); self.assertNotEqual(d["result"]["owner_decision"]["issue"],199); self.assertTrue(d["result"]["owner_decision"]["created"])

    def test_response_loss_recovers_from_durable_pending_without_repost(self):
        d=scenario("venture-response-lost"); self.assertTrue(d["first_failed"]); self.assertEqual(d["created_issues"],1); self.assertFalse(d["second"]["owner_decision"]["created"]); self.assertEqual(d["second"]["owner_decision"]["issue"],d["issues"][0]["number"])

    def test_client_cannot_supply_writer_or_repository_authority(self):
        d=scenario("venture-client-authority"); self.assertTrue(d["extra_blocked"] and d["repo_blocked"]); self.assertEqual(d["created_issues"],0); self.assertNotIn("fixture-client-value",json.dumps(d))

    def test_missing_authorized_writer_fails_closed(self):
        d=scenario("venture-no-writer"); self.assertTrue(d["blocked"]); self.assertEqual(d["created_issues"],0); self.assertEqual(d["seen"],[])

    def test_owner_approval_does_not_reexecute_original_lifecycle_command(self):
        d=scenario("venture-approve"); self.assertEqual((d["approval"]["category"],d["approval"]["option"]),("product-direction","A")); self.assertEqual(d["created_issues"],1); self.assertEqual(d["issue_state"],"closed"); self.assertEqual(len([r for r in d["seen"] if r[0]=="POST" and r[1].endswith("/issues")]),1)

    def test_repository_allowlist_is_server_side(self):
        result = raw("untrusted-repo")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, "")


if __name__ == "__main__":
    unittest.main()

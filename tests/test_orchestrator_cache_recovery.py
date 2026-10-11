"""Offline PHP regressions for a corrupt, derived orchestrator cache (#769)."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests/orchestrator_cache_recovery_scenarios.php"


def scenario(name):
    proc = subprocess.run(["php", str(SCENARIOS), name], cwd=ROOT,
                          text=True, capture_output=True, check=True, timeout=30)
    return json.loads(proc.stdout)


class OrchestratorCacheRecoveryTests(unittest.TestCase):
    def test_corrupt_json_recovers_from_valid_source(self):
        result = scenario("corrupt-json")
        self.assertEqual(["refreshed", "fresh"], result["statuses"])
        self.assertEqual(1, result["calls"])
        self.assertEqual("UNKNOWN", result["payload"])
        self.assertTrue(result["persisted"])

    def test_invalid_cache_and_invalid_source_fail_closed(self):
        result = scenario("invalid-sources")
        self.assertEqual({"invalid-json": True, "bad-shape": True,
                          "forged-fingerprint": True, "consistent-partial": True,
                          "impossible-central": True, "mismatched-time": True,
                          "reversed-window": True}, result["rejected"])
        self.assertEqual("refreshed", result["recovered"])
        self.assertEqual("refreshed", result["future"])
        self.assertEqual(9, result["calls"])
        self.assertRegex(result["fingerprint"], r"^[0-9a-f]{64}$")
        projection = scenario("decision-contract")
        self.assertEqual(["refreshed", "fresh", "refreshed"], projection["statuses"])
        self.assertEqual(["structured", "legacy"], projection["formats"])
        self.assertTrue(projection["blocked"])
        self.assertTrue(projection["expiry_blocked"])
        self.assertTrue(projection["issue_blocked"])
        self.assertEqual({"pros": True, "cons": True, "cost": True,
                          "explain_simple": True, "decision_explain": True,
                          "effect_length": True, "pros_length": True, "pros_control": True},
                         projection["invalid_options"])
        agent = scenario("agent-parity")
        self.assertTrue(agent["rejected"])
        self.assertEqual("refreshed", agent["valid"])
        compat = scenario("interop-766")
        self.assertEqual({"signal_summary": ["refreshed", "fresh"],
                          "collector_failure": ["refreshed", "fresh"]}, compat["valid"])
        self.assertEqual({"signal_summary": True, "collector_failure": True, "collector_time": True}, compat["invalid"])

    def test_valid_ttl_stale_and_atomic_replacement_unchanged(self):
        result = scenario("ttl-stale")
        self.assertEqual(["refreshed", "fresh", "stale", "stale", "stale"], result["statuses"])
        self.assertEqual(2, result["calls"])
        self.assertEqual(21, result["age"])
        self.assertEqual(0, result["temp"])
        self.assertTrue(result["read_only"])

    def test_two_requests_recover_without_false_green(self):
        result = scenario("two-requests")
        self.assertEqual(["refreshed", "fresh"], result["statuses"])
        self.assertEqual(["UNKNOWN", "UNKNOWN"], result["activity"])
        self.assertEqual(1, result["calls"])
        self.assertEqual(3, result["age"])
        self.assertEqual("refreshed", result["link_valid"])
        self.assertTrue(result["link_rejected"])


if __name__ == "__main__":
    unittest.main()

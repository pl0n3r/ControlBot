import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests/factory_orchestrator_live_runtime_scenarios.php"
ENDPOINT = ROOT / "src/FactoryOrchestratorLiveEndpoint.php"


def scenario(name):
    run = subprocess.run(["php", str(SCENARIOS), name], cwd=ROOT, text=True, capture_output=True, check=True, timeout=30)
    return json.loads(run.stdout)


class FactoryOrchestratorLiveRuntimeTests(unittest.TestCase):
    def test_runtime_adapter_serves_owner_only_read_only_view_and_json(self):
        html = scenario("html")
        self.assertEqual(html["status"], 200)
        self.assertIn("Orquestador en vivo", html["body"])
        self.assertIn("solo lectura", html["body"].lower())
        response = scenario("json")
        self.assertEqual(response["status"], 200)
        payload = json.loads(response["body"])
        self.assertTrue(payload["read_only"])
        self.assertTrue(payload["snapshot"]["read_only"])
        self.assertRegex(payload["snapshot"]["fingerprint"], r"^[0-9a-f]{64}$")
        self.assertTrue((ROOT / "public" / "index.php").is_file())

    def test_cache_accepts_bounded_blocker_explanations(self):
        outcomes = scenario("blocker-schema")
        for name in ("empty", "unknown", "declared", "short_declared"):
            with self.subTest(name=name):
                self.assertTrue(outcomes[name], f"Expected safe cache projection: {name}")

    def test_cache_rejects_forged_or_invalid_blocker_explanations(self):
        outcomes = scenario("blocker-schema")
        for name in ("bad_type", "too_many", "wrong_source", "cross_repo",
                     "future", "wrong_age", "sensitive", "forged_unknown",
                     "extra_key", "duplicated"):
            with self.subTest(name=name):
                self.assertFalse(outcomes[name], f"Unsafe cache projection accepted: {name}")

    def test_cache_ttl_and_stale_fallback_bound_refresh_rate_without_daemon(self):
        result = scenario("cache")
        self.assertEqual(result["statuses"], ["refreshed","fresh","stale","stale","stale"])
        self.assertEqual(result["calls"], 2)
        self.assertEqual(result["age"], 21)

    def test_missing_auth_configuration_fails_closed_without_leaking_data(self):
        result = scenario("auth")
        self.assertEqual(result["calls"], 0)
        self.assertEqual(result["missing"]["status"], 503)
        self.assertEqual(result["denied"]["status"], 403)
        for row in (result["missing"], result["denied"]):
            self.assertNotIn("fingerprint", row["body"].lower())
            self.assertNotIn("token", row["body"].lower())

    def test_client_polling_uses_local_endpoint_without_websocket_or_direct_github_calls(self):
        html = scenario("html")["body"]
        self.assertIn('endpoint="/api/orchestrator-live"', html)
        self.assertIn("setInterval", html)
        self.assertIn('credentials:"same-origin"', html)
        source = ENDPOINT.read_text().lower()
        for forbidden in ("api.github.com","github.com/","websocket","curl_","workflow_dispatch","/tomar","/decidir"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

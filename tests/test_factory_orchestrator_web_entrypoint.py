import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "factory_orchestrator_web_entrypoint_scenarios.php"
ENTRYPOINT = ROOT / "src" / "FactoryOrchestratorWebEntrypoint.php"
PUBLIC_INDEX = ROOT / "public" / "index.php"
RUNBOOK = ROOT / "docs" / "public-orchestrator-entrypoint.md"


def scenario(name):
    run = subprocess.run(
        ["php", str(SCENARIOS), name],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=True,
        timeout=30,
    )
    return json.loads(run.stdout)


class FactoryOrchestratorWebEntrypointTests(unittest.TestCase):
    def test_entrypoint_serves_owner_html_and_json_from_local_snapshot_only(self):
        html = scenario("owner")
        self.assertEqual(html["status"], 200)
        self.assertIn("Orquestador en vivo", html["body"])
        self.assertIn("reserved", html["body"])

        response = scenario("json")
        self.assertEqual(response["status"], 200)
        payload = json.loads(response["body"])
        self.assertTrue(payload["read_only"])
        self.assertEqual(payload["snapshot"]["fronts"][0]["status"], "reserved")
        self.assertEqual(
            payload["snapshot"]["fronts"][0]["repository_ref"],
            "pl0n3r/ControlBot",
        )

        source = ENTRYPOINT.read_text(encoding="utf-8").lower()
        for forbidden in (
            "api.github.com",
            "github.com/",
            "curl_",
            "workflow_dispatch",
            "shell_exec",
            "proc_open",
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("file_get_contents($path)", source)

    def test_missing_auth_wrong_owner_or_missing_config_fails_closed_without_data(self):
        result = scenario("auth")
        self.assertEqual(result["missing"]["status"], 503)
        self.assertEqual(result["wrong"]["status"], 403)
        self.assertEqual(result["config"]["status"], 503)
        for row in result.values():
            body = row["body"].lower()
            self.assertNotIn("reserved", body)
            self.assertNotIn("fingerprint", body)
            self.assertNotIn("github:", body)

    def test_missing_or_invalid_local_snapshot_is_explicit_unknown_without_github_io(self):
        result = scenario("unknown")
        for name, row in result.items():
            with self.subTest(name=name):
                self.assertEqual(row["status"], 200)
                payload = json.loads(row["body"])
                self.assertTrue(payload["read_only"])
                self.assertEqual(
                    payload["snapshot"]["central"]["activity_state"],
                    "UNKNOWN",
                )
                self.assertEqual(payload["snapshot"]["fronts"], [])
                self.assertEqual(payload["snapshot"]["owner_decisions"], [])

        source = ENTRYPOINT.read_text(encoding="utf-8")
        self.assertIn("SNAPSHOT_MAX_BYTES = 2_000_000", source)
        self.assertIn("SNAPSHOT_MAX_AGE_SECONDS = 300", source)
        self.assertIn("unknownSnapshot", source)

    def test_public_bootstrap_and_runbook_preserve_d059_and_merge_order(self):
        bootstrap = PUBLIC_INDEX.read_text(encoding="utf-8")
        guide = RUNBOOK.read_text(encoding="utf-8")

        self.assertIn("FactoryOrchestratorWebEntrypoint::handle", bootstrap)
        self.assertIn("CONTROLBOT_OWNER_LOGIN", bootstrap)
        self.assertIn("$_SERVER", bootstrap)
        self.assertNotIn("DEPLOY_ENABLED", bootstrap)
        self.assertNotIn("DOMAIN", bootstrap)

        for value in (
            "D-059",
            "PR #630",
            "#630 → este PR",
            "var/orchestrator-live.json",
            "300 segundos",
            "/src/",
            "/config/",
            "403/404",
        ):
            self.assertIn(value, guide)
        self.assertIn("no se fusiona", guide.lower())
        self.assertNotIn("password=", guide.lower())
        self.assertNotIn("authorization:", guide.lower())


if __name__ == "__main__":
    unittest.main()

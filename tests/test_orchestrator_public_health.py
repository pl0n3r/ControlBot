"""Read-only production-health cards with fully synthetic DNS and HTTP probes."""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests/factory_public_health_scenarios.php"
OBSERVER = ROOT / "src/FactoryPublicHealthObserver.php"
UI = ROOT / "src/FactoryPublicHealthUi.php"
ORCHESTRATOR = ROOT / "src/FactoryOrchestratorLiveUi.php"
REPOS = ["pl0n3r/Condor", "pl0n3r/brvtal", "pl0n3r/GrindFlow", "pl0n3r/ControlBot"]


def scenario(name: str) -> dict:
    if not shutil.which("php"):
        raise unittest.SkipTest("php CLI unavailable")
    result = subprocess.run(
        ["php", str(SCENARIOS), name], cwd=ROOT,
        text=True, capture_output=True, timeout=15, check=False,
    )
    if result.returncode != 0:
        raise AssertionError(f"PHP fixture {name} failed: {result.stderr[:900]}")
    return json.loads(result.stdout)


class OrchestratorPublicHealthTests(unittest.TestCase):
    def test_four_repos_show_grounded_code_latency_timestamp_and_freshness(self):
        result = scenario("normal")
        rows = result["rows"]
        self.assertEqual([x["repository_ref"] for x in rows], REPOS)
        self.assertEqual([x["status"] for x in rows],
                         ["reachable", "unreachable", "unreachable", "reachable"])
        self.assertEqual([x["http_status"] for x in rows], [200, 403, 500, 200])
        self.assertEqual(result["requests"], 4)
        self.assertEqual(result["dns_calls"], 4)
        for row in rows:
            self.assertEqual(row["latency_ms"], 25)
            self.assertEqual(row["observed_at"], 1800000000)
            self.assertEqual(row["source_age_seconds"], 0)
            self.assertNotIn("production_green", row)
        missing = scenario("unconfigured")
        self.assertEqual(missing["requests"], 0)
        self.assertEqual(missing["dns_calls"], 0)
        self.assertEqual([x["reason"] for x in missing["rows"]],
                         ["no_public_endpoint"] * 4)
        self.assertTrue(all(x["status"] == "unknown" for x in missing["rows"]))
        self.assertEqual(result["has_cards"], 4)

    def test_http_errors_timeout_and_stale_fail_closed_without_crashing_ui(self):
        result = scenario("normal")
        self.assertEqual(result["rows"][1]["reason"], "http_non_success")
        self.assertEqual(result["rows"][2]["status"], "unreachable")
        for name in ("timeout", "oversize", "redirect"):
            with self.subTest(name=name):
                result = scenario(name)
                self.assertEqual(result["rows"][0]["status"], "unknown")
                self.assertEqual(result["rows"][0]["reason"], "probe_failed")
                self.assertIsNone(result["rows"][0]["http_status"])
                self.assertIsNone(result["rows"][0]["latency_ms"])
                self.assertEqual(result["has_cards"], 4)
        stale = scenario("stale")
        self.assertTrue(all(x["status"] == "stale" for x in stale["rows"]))
        self.assertTrue(all(x["reason"] == "evidence_stale" for x in stale["rows"]))
        self.assertTrue(all(x["source_age_seconds"] == 500 for x in stale["rows"]))
        self.assertEqual(scenario("future_invalid")["has_cards"], 4)

    def test_http_transport_rejects_ssrf_redirect_credentials_and_unbounded_io(self):
        hostile = scenario("hostile")
        self.assertEqual(hostile["requests"], 0)
        self.assertEqual(hostile["dns_calls"], 0)
        self.assertEqual(hostile["rows"][0]["reason"], "endpoint_not_allowlisted")
        private = scenario("private_dns")
        self.assertEqual(private["requests"], 0)
        self.assertEqual(private["rows"][0]["reason"], "probe_failed")
        self.assertTrue(scenario("redirect")["rows"][0]["status"] == "unknown")
        self.assertTrue(scenario("oversize")["rows"][0]["status"] == "unknown")
        source = OBSERVER.read_text(encoding="utf-8")
        for guard in (
            "FILTER_FLAG_NO_PRIV_RANGE", "FILTER_FLAG_NO_RES_RANGE",
            "CURLOPT_RESOLVE", "CURLOPT_FOLLOWLOCATION => false",
            "CURLOPT_SSL_VERIFYPEER => true", "CURLOPT_PROXY => ''",
            "CURLOPT_MAXREDIRS => 0", "CURLOPT_WRITEFUNCTION",
            "'send_credentials' => false", "'max_bytes' => self::MAX_BYTES",
        ):
            with self.subTest(guard=guard):
                self.assertIn(guard, source)
        self.assertNotIn("CURLOPT_POST => true", source)
        self.assertNotIn("CURLOPT_SSL_VERIFYPEER => false", source)
        self.assertNotIn("curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true)", source)

    def test_read_only_private_ui_does_not_persist_body_cookies_or_secrets(self):
        result = scenario("ui_escape")
        self.assertEqual(result["has_cards"], 4)
        self.assertTrue(result["has_unknown_gate"])
        self.assertTrue(result["is_escaped"])
        self.assertTrue(result["has_script_text"])
        self.assertTrue(result["has_no_actions"])
        self.assertNotIn("<script>alert(1)</script>", result["raw_html"])
        self.assertNotIn("<button", result["raw_html"])
        self.assertNotIn("<form", result["raw_html"])
        self.assertNotIn("production_green", result["raw_html"])
        response = scenario("timeout")
        self.assertNotIn("CREDENTIAL_SECRET_NEVER_PERSIST", json.dumps(response))
        self.assertNotIn("body", json.dumps(response["rows"]))
        self.assertNotIn("cookie", json.dumps(response["rows"]))
        self.assertIn("FactoryPublicHealthUi::renderSection", ORCHESTRATOR.read_text())
        self.assertIn("data-section=\"public_health\"", UI.read_text())
        for source in (OBSERVER, UI, ORCHESTRATOR, SCENARIOS):
            if not shutil.which("php"):
                break
            with self.subTest(php_lint=str(source)):
                lint = subprocess.run(["php", "-l", str(source)], cwd=ROOT,
                                      text=True, capture_output=True, timeout=10)
                self.assertEqual(lint.returncode, 0, lint.stderr)


if __name__ == "__main__":
    unittest.main()

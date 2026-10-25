"""Owner-only Recovery portfolio is declarative; operational evidence remains UNKNOWN."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(script, name):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / script), name], cwd=ROOT,
        text=True, capture_output=True, check=False, timeout=30,
    )
    if run.returncode:
        raise AssertionError(run.stderr or run.stdout)
    return json.loads(run.stdout)


class RecoveryPortfolioTests(unittest.TestCase):
    def test_seven_projects_and_unknown_operational(self):
        view = scenario("recovery_portfolio_scenarios.php", "baseline")
        self.assertIs(view["execution"], False)
        self.assertEqual(view["actions"], [])
        self.assertEqual([p["project"] for p in view["projects"]], [
            "autofactory", "brvtal", "condor", "controlbot",
            "factory", "factoryrunner", "grindflow",
        ])
        for item in view["projects"]:
            self.assertEqual(item["dr_status"], "UNKNOWN")
            self.assertNotIn("observed_rpo", item)
            self.assertNotIn("backup_receipt", item)
        runner = view["projects"][5]
        self.assertEqual(runner["profile_status"], "unknown")
        self.assertEqual(runner["reason"], "PROFILE_MISSING")
        self.assertIsNone(runner["rpo_target_minutes"])
        self.assertIsNone(runner["rto_target_minutes"])
        self.assertEqual(sum(p["profile_status"] == "configured" for p in view["projects"]), 6)

    def test_invalid_cross_project_and_sensitive_fail_closed(self):
        for label in ("cross-project", "sensitive", "extra-project", "bad-shape", "unexpected-runner"):
            with self.subTest(case=label):
                self.assertTrue(scenario("recovery_portfolio_scenarios.php", label)["rejected"])
        stale = scenario("recovery_portfolio_scenarios.php", "stale")
        cb = next(x for x in stale["projects"] if x["project"] == "controlbot")
        self.assertEqual(cb["profile_status"], "unknown")
        self.assertEqual(cb["dr_status"], "UNKNOWN")
        self.assertIsNone(cb["rpo_target_minutes"])

    def test_owner_only_read_only_html(self):
        owner = scenario("recovery_center_web_scenarios.php", "owner")
        self.assertEqual(owner["status"], 200)
        self.assertEqual(owner["headers"]["Cache-Control"], "no-store")
        self.assertIn("default-src 'none'", owner["headers"]["Content-Security-Policy"])
        html = owner["body"]
        self.assertIn('id="recovery-portfolio-title"', html)
        for name in ("autofactory", "brvtal", "condor", "controlbot", "factory", "factoryrunner", "grindflow"):
            self.assertIn(f">{name}</td>", html)
        self.assertIn("PROFILE_MISSING", html)
        self.assertNotIn("HEALTHY", html)
        for unsafe in ("<script", "<form", "<button", "api_key", "gho_offline_recovery_fixture"):
            self.assertNotIn(unsafe, html.lower())
        guards = scenario("recovery_center_web_scenarios.php", "guards")
        self.assertEqual(guards["anonymous"]["status"], 403)
        self.assertEqual(guards["post"]["status"], 405)
        bad = scenario("recovery_center_web_scenarios.php", "invalid-profile")
        self.assertEqual({x["status"] for x in bad.values()}, {503})

    def test_broken_symlink_guard_precedes_missing_profile_fallback(self):
        source = (ROOT / "src/RecoveryCenterWebEntrypoint.php").read_text(encoding="utf-8")
        self.assertLess(source.index("if (is_link($path))"), source.index("if (!file_exists($path))"))

    def test_offline_provider_free_code_and_documented_limits(self):
        source = (ROOT / "src" / "RecoveryPortfolio.php").read_text(encoding="utf-8")
        for forbidden in ("curl_", "file_get_contents", "file_put_contents", "shell_exec", "create_issue", "dispatch(", "HEALTHY"):
            self.assertNotIn(forbidden, source)
        docs = (ROOT / "docs" / "recovery-center-web.md").read_text(encoding="utf-8")
        for phrase in ("FactoryRunner", "UNKNOWN", "owner-only", "#858", "No confirma backups"):
            self.assertIn(phrase, docs)


if __name__ == "__main__":
    unittest.main()

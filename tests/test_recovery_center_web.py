import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    p = subprocess.run(
        ["php", str(ROOT / "tests" / "recovery_center_web_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, timeout=30,
    )
    if p.returncode:
        raise AssertionError(p.stderr or p.stdout)
    return json.loads(p.stdout)


class RecoveryCenterWebTests(unittest.TestCase):
    def test_owner_only_recovery_route_uses_verified_session_and_projector(self):
        response = scenario("owner")
        self.assertEqual(response["status"], 200)
        self.assertEqual(response["headers"]["Cache-Control"], "no-store")
        self.assertIn("default-src 'none'", response["headers"]["Content-Security-Policy"])
        self.assertIn('data-section="overview"', response["body"])
        self.assertIn("Security Center / Resilience", response["body"])
        entry = (ROOT / "src" / "ControlBotWebEntrypoint.php").read_text()
        self.assertIn("OwnerAuthenticatedRouter::verifiedOwner", entry)
        self.assertIn("RecoveryCenterWebEntrypoint::handle", entry)
        self.assertIn("RecoveryCenterUi::project", (ROOT / "src" / "RecoveryCenterWebEntrypoint.php").read_text())

    def test_recovery_unknown_rpo_rto_and_read_only_html(self):
        html = scenario("owner")["body"]
        for term in ("UNKNOWN", "Recovery", "RPO objetivo", "RTO objetivo", "15 min", "60 min", "Copia externa", "Copia inmutable"):
            self.assertIn(term, html)
        self.assertNotIn("HEALTHY", html)
        for forbidden in ("<script", "<form", "<button", 'method="post"', "<iframe"):
            self.assertNotIn(forbidden, html.lower())
        self.assertNotIn("gho_offline_recovery_fixture", html)

    def test_spoofed_owner_post_and_invalid_profile_fail_closed(self):
        guards = scenario("guards")
        self.assertEqual(guards["anonymous"]["status"], 403)
        self.assertEqual(guards["post"]["status"], 405)
        self.assertNotEqual(guards["forged_user"]["status"], 200)
        for v in guards.values():
            self.assertNotIn("RPO objetivo", v["body"])
        invalid = scenario("invalid-profile")
        for v in invalid.values():
            self.assertEqual(v["status"], 503)
            self.assertEqual(v["body"], "Service unavailable.\n")
            self.assertNotIn("<script>", v["body"])

    def test_recovery_docs_match_integrated_owner_only_route(self):
        owner = scenario("owner")
        self.assertEqual(owner["status"], 200)
        self.assertEqual(owner["headers"]["Cache-Control"], "no-store")
        self.assertIn("text/html", owner["headers"]["Content-Type"])
        self.assertIn("default-src 'none'", owner["headers"]["Content-Security-Policy"])
        self.assertIn("UNKNOWN", owner["body"])
        self.assertNotIn("HEALTHY", owner["body"])
        self.assertNotIn("<form", owner["body"].lower())
        self.assertNotIn("<script", owner["body"].lower())

        guards = scenario("guards")
        self.assertEqual(guards["anonymous"]["status"], 403)
        self.assertEqual(guards["post"]["status"], 405)
        self.assertNotEqual(guards["forged_user"]["status"], 200)

        invalid = scenario("invalid-profile")
        for response in invalid.values():
            self.assertEqual(response["status"], 503)
            self.assertEqual(response["body"], "Service unavailable.\n")

        docs = (ROOT / "docs" / "disaster-recovery-ui.md").read_text(encoding="utf-8")
        for term in (
            "GET /security/resilience",
            "ControlBotWebEntrypoint::handleAuthenticated",
            "OwnerAuthenticatedRouter::verifiedOwner",
            "RecoveryCenterWebEntrypoint::handle",
            "UNKNOWN",
            "Cache-Control: no-store",
            "#577",
            "B en #577",
            "RPO 15 min",
            "RTO 60 min",
        ):
            self.assertIn(term, docs)
        self.assertNotIn("HTML/routing final queda fuera de este slice", docs)
        entry = (ROOT / "src" / "ControlBotWebEntrypoint.php").read_text(encoding="utf-8")
        self.assertIn("parse_url($uri, PHP_URL_PATH) === '/security/resilience'", entry)
        self.assertIn("RecoveryCenterWebEntrypoint::handle($server)", entry)
        self.assertIn("OwnerAuthenticatedRouter::verifiedOwner", entry)

    def test_existing_routes_and_no_live_capabilities_are_preserved(self):
        response = scenario("delegated")
        self.assertIn(response["status"], (200, 503))
        source = (ROOT / "src" / "RecoveryCenterWebEntrypoint.php").read_text().lower()
        for forbidden in ("curl_", "shell_exec", "proc_open", "mysqli", "pdo(", "workflow_dispatch", "create_issue", "file_put_contents", "http://", "https://"):
            self.assertNotIn(forbidden, source)
        docs = (ROOT / "docs" / "recovery-center-web.md").read_text()
        for term in ("#577", "B", "UNKNOWN", "owner-only", "GET /security/resilience", "V3"):
            self.assertIn(term, docs)


if __name__ == "__main__":
    unittest.main()

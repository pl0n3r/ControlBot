from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]
RUNBOOK = ROOT / "docs" / "runbooks" / "orchestrator-snapshot-cron.md"
PUBLIC_DOC = ROOT / "docs" / "public-orchestrator-entrypoint.md"
ENTRYPOINT = ROOT / "src" / "FactoryOrchestratorWebEntrypoint.php"


class OrchestratorRunbookPathsTests(unittest.TestCase):
    def setUp(self) -> None:
        self.runbook = RUNBOOK.read_text(encoding="utf-8")
        self.public_doc = PUBLIC_DOC.read_text(encoding="utf-8")
        self.entrypoint = ENTRYPOINT.read_text(encoding="utf-8")

    def test_runbook_snapshot_path_matches_the_path_read_by_the_web_entrypoint(self) -> None:
        assignment = re.search(
            r'export CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="([^"]+)"',
            self.runbook,
        )
        self.assertIsNotNone(assignment)
        documented = assignment.group(1)

        web_default = re.search(
            r"dirname\(__DIR__\)\s*\.\s*'(/var/orchestrator-live\.json)'",
            self.entrypoint,
        )
        self.assertIsNotNone(web_default)

        self.assertEqual(
            documented,
            "$HOME/domains/control.condorapp.com.co/public_html/var/orchestrator-live.json",
        )
        self.assertIn("/public_html", documented)
        self.assertEqual(documented.split("/public_html", 1)[1], web_default.group(1))
        self.assertIn("`var/orchestrator-live.json`", self.public_doc)
        self.assertIn(documented, self.public_doc)
        self.assertNotIn(
            'CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-live.json"',
            self.runbook,
        )

    def test_runbook_contains_complete_cron_command_without_secrets(self) -> None:
        cron_lines = [
            line.strip()
            for line in self.runbook.splitlines()
            if line.strip().startswith("*/5 * * * *")
        ]
        self.assertEqual(len(cron_lines), 1)
        cron = cron_lines[0]

        self.assertIn(
            'cd "$HOME/domains/control.condorapp.com.co/public_html"',
            cron,
        )
        self.assertIn("CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED=1", cron)
        self.assertIn(
            'CONTROLBOT_GITHUB_READ_TOKEN_FILE="$HOME/.controlbot/github-read-token"',
            cron,
        )
        self.assertIn(
            'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH="$HOME/domains/control.condorapp.com.co/private/orchestrator-evidence.json"',
            cron,
        )
        self.assertIn("CONTROLBOT_ORCHESTRATOR_CRON_ENABLED=1", cron)
        self.assertIn(
            'CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH="$HOME/domains/control.condorapp.com.co/public_html/var/orchestrator-live.json"',
            cron,
        )
        self.assertIn(
            "/opt/alt/php85/usr/bin/php scripts/orchestrator-evidence-collector.php",
            cron,
        )
        self.assertIn(
            "/opt/alt/php85/usr/bin/php scripts/orchestrator-snapshot-cron.php",
            cron,
        )
        self.assertIn(
            '>> "$HOME/.controlbot/orchestrator-snapshot-cron.log" 2>&1',
            cron,
        )
        self.assertNotIn("CONTROLBOT_GITHUB_READ_TOKEN=", cron)
        self.assertNotRegex(cron, r"(?:ghp_|github_pat_)[A-Za-z0-9_]+")


if __name__ == "__main__":
    unittest.main()

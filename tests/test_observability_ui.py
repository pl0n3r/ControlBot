import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SCENARIOS=ROOT/"tests"/"observability_ui_scenarios.php"

def scenario(name):
    run=subprocess.run(["php",str(SCENARIOS),name],cwd=ROOT,text=True,capture_output=True,check=True,timeout=30)
    return run.stdout

class ObservabilityUiTests(unittest.TestCase):
    def test_monitor_backup_and_restore_status_render_without_secrets(self):
        html=scenario("complete")
        for text in ("Monitor: healthy","Último probe","1.4.0","Último backup","backup:controlbot-001","Último restore probado","restore:controlbot-001","Restorable</dt><dd>sí"):
            self.assertIn(text,html)
        self.assertNotIn("vault:controlbot/backup-001",html)
        self.assertNotIn("a"*64,html)

    def test_missing_evidence_is_unknown_not_healthy_or_restorable(self):
        html=scenario("missing")
        self.assertIn("Monitor: unknown",html)
        self.assertIn("Sin evidencia de probe.",html)
        self.assertIn("Sin evidencia de backup.",html)
        self.assertIn("Sin restore drill verificado.",html)
        self.assertIn("Restorable</dt><dd>no",html)
        self.assertNotIn("Monitor: healthy",html)
        unknown=scenario("unknown_probe")
        self.assertIn("Monitor: unknown",unknown)
        self.assertIn("Freshness</dt><dd>unknown",unknown)
        self.assertIn("1970-01-01 00:00:00 UTC",unknown)

    def test_restorable_status_requires_verified_restore_drill(self):
        html=scenario("no_restore")
        self.assertIn("Restorable</dt><dd>no",html)
        self.assertIn("restore_evidence_missing",html)
        self.assertNotIn("Restorable</dt><dd>sí",html)
        invalid=scenario("invalid_restore")
        self.assertIn("Monitor: healthy",invalid)
        self.assertIn("backup:controlbot-001",invalid)
        self.assertIn("Sin restore drill verificado.",invalid)
        self.assertIn("Restorable</dt><dd>no",invalid)
        self.assertIn("restore_evidence_invalid",invalid)

    def test_external_alert_requirement_is_projected_without_execution(self):
        html=scenario("alert")
        self.assertIn("Monitor: down",html)
        self.assertIn("Requerida</dt><dd>sí",html)
        self.assertIn("Canal externo</dt><dd>requerido",html)
        self.assertIn(".state-down{color:var(--red)}",html)
        pure=json.loads(scenario("pure"))
        self.assertEqual(pure["hits"],[])

    def test_projection_redacts_storage_checksum_and_sensitive_metadata(self):
        data=json.loads(scenario("redaction"))
        self.assertTrue(all(data.values()))

    def test_mobile_accessibility_and_read_only_contract(self):
        html=scenario("complete")
        for text in ('name="viewport"','aria-labelledby="obs-title"','aria-label="Estado del vigilante y recuperación"',':focus-visible','prefers-reduced-motion','Vista read-only'):
            self.assertIn(text,html)
        pure=json.loads(scenario("pure"))
        self.assertFalse(pure["form"]);self.assertFalse(pure["button"]);self.assertFalse(pure["input"])

if __name__=="__main__": unittest.main()

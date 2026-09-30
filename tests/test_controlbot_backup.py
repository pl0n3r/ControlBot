import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"controlbot_backup_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=60)
    return json.loads(run.stdout)

class ControlBotBackupTests(unittest.TestCase):
    def test_backup_receipt_is_verifiable_and_secret_free(self):
        d=scenario("backup")
        self.assertEqual(d["valid"]["status"],"completed")
        self.assertEqual(len(d["valid"]["checksum"]),64)
        self.assertGreater(d["valid"]["size_bytes"],0)
        self.assertTrue(d["size_rejected"]); self.assertTrue(d["url_rejected"]); self.assertTrue(d["secret_rejected"])

    def test_restore_drill_isolated_and_traceable(self):
        d=scenario("restore")
        self.assertEqual(d["valid"]["target_environment"],"restore-drill")
        self.assertEqual(d["valid"]["source_checksum"],d["valid"]["destination_checksum"])
        self.assertTrue(d["production_rejected"]); self.assertTrue(d["checksum_rejected"]); self.assertTrue(d["before_backup_rejected"])

    def test_restorable_requires_successful_equivalent_restore(self):
        d=scenario("restorable")
        self.assertTrue(d["verified"]["restorable"])
        self.assertFalse(d["missing"]["restorable"]); self.assertFalse(d["invalid"]["restorable"])

    def test_retention_preserves_last_known_restorable_backup(self):
        d=scenario("retention")
        self.assertIn("backup-002",d["plan"]["protected_restorable"])
        self.assertIn("backup-002",d["plan"]["keep"]); self.assertIn("backup-003",d["plan"]["keep"])
        self.assertIn("backup-004",d["plan"]["keep"]); self.assertIn("backup-001",d["plan"]["delete"])
        self.assertEqual(d["plan"]["authority"],"plan_only"); self.assertTrue(d["ambiguous_rejected"])

    def test_parent_backup_targets_are_executable(self):
        self.assertEqual(scenario("backup")["valid"]["status"],"completed")
        self.assertEqual(scenario("restore")["valid"]["status"],"passed")
        self.assertIn("backup-002",scenario("retention")["plan"]["protected_restorable"])

    def test_backup_core_has_no_external_io(self):
        self.assertEqual(scenario("pure")["hits"],[])

if __name__=="__main__": unittest.main()

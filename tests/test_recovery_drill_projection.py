import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(
        ["php",str(ROOT/"tests"/"recovery_drill_projection_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(r.stdout)

class RecoveryDrillProjectionTests(unittest.TestCase):
    def test_projection_is_project_scoped_and_bound_to_profile_evidence_and_receipt(self):
        data=scenario("valid")
        self.assertEqual(data["project_ref"],"controlbot:project/project-controlbot")
        self.assertEqual(data["backup_receipt_id"],"11111111-1111-4111-8111-111111111111")
        self.assertTrue(scenario("cross-project")["blocked"])
        self.assertTrue(scenario("producer-mismatch")["blocked"])

    def test_factory_status_metrics_and_reasons_are_preserved_without_recalculation(self):
        valid=scenario("valid")
        self.assertEqual((valid["status"],valid["reported_status"]),("PASSED","PASSED"))
        self.assertEqual(valid["observed"],{
            "rpo_seconds":600,"rto_seconds":1680,
            "rpo_target_seconds":900,"rto_target_seconds":3600,
        })
        breached=scenario("breached")
        self.assertEqual((breached["status"],breached["reported_status"],breached["reasons"]),("BREACHED","BREACHED",["RTO_EXCEEDED"]))
        for case in ("stale","unknown"):
            item=scenario(case)
            self.assertEqual(item["status"],"UNKNOWN")
            self.assertEqual(item["reported_status"],"PASSED")
        source=(ROOT/"src"/"RecoveryDrillProjection.php").read_text()
        self.assertNotIn("rpo_seconds'] >",source)
        self.assertNotIn("rto_seconds'] >",source)

    def test_projection_requires_disposable_target_successful_checks_and_unchanged_authority(self):
        for case in ("production-target","failed-check","authority-expanded","execute-true"):
            with self.subTest(case=case):
                self.assertTrue(scenario(case)["blocked"])

    def test_sensitive_production_extra_or_incoherent_input_fails_closed_without_echo(self):
        cases={
            "sensitive-ref":"token-secret-value",
            "extra-field":"backup-material",
            "target-mismatch":"999",
        }
        for case,sensitive in cases.items():
            with self.subTest(case=case):
                result=scenario(case)
                self.assertTrue(result["blocked"])
                self.assertNotIn(sensitive,result["message"] or "")

    def test_projection_does_not_compute_health_workitems_or_external_io(self):
        data=scenario("valid")
        self.assertEqual(set(data),{
            "version","project_ref","recovery_evidence_ref","backup_receipt_id","target",
            "status","reported_status","observed","checks","reasons","evidence_refs",
            "observed_at","freshness","authority","execute",
        })
        source=(ROOT/"src"/"RecoveryDrillProjection.php").read_text()
        for forbidden in ("WorkItem","HEALTHY","DEGRADED","BLOCKED","file_get_contents","shell_exec","curl_"):
            self.assertNotIn(forbidden,source)

    def test_docs_preserve_factory_drill_and_status_authority_boundaries(self):
        docs=(ROOT/"docs"/"disaster-recovery-drill.md").read_text()
        self.assertIn("Factory #330",docs)
        self.assertIn("Factory #331",docs)
        self.assertIn("no recalcula",docs)
        self.assertIn("no ejecuta restore",docs)

if __name__=="__main__":
    unittest.main()

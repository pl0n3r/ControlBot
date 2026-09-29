import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(n):
 r=subprocess.run(["php",str(ROOT/"tests"/"recovery_center_ui_scenarios.php"),n],cwd=ROOT,check=True,text=True,capture_output=True)
 return json.loads(r.stdout)

class RecoveryCenterUiTests(unittest.TestCase):
 def test_view_model_joins_project_scoped_profile_evidence_drill_and_factory_health(self):
  d=scenario("valid")
  self.assertEqual(d["project_ref"],"controlbot:project/project-controlbot")
  self.assertEqual(d["dr_status"],"DEGRADED")
  self.assertTrue(scenario("cross-health"));self.assertTrue(scenario("cross-evidence"))

 def test_dr_status_reuses_factory_health_and_missing_health_defaults_to_unknown_without_recalculation(self):
  self.assertEqual(scenario("blocked-health")["dr_status"],"BLOCKED")
  missing=scenario("missing-health")
  self.assertEqual(missing["dr_status"],"UNKNOWN")
  self.assertEqual(missing["reasons"],["RECOVERY_HEALTH_MISSING"])

 def test_signals_and_rpo_rto_are_explainable_and_stale_details_remain_unknown(self):
  d=scenario("valid")
  self.assertEqual(d["signals"]["database_backup"]["state"],"verified")
  self.assertEqual(d["signals"]["offsite_copy"]["state"],"verified")
  self.assertEqual(d["signals"]["immutable_copy"]["state"],"verified")
  self.assertEqual(d["signals"]["media_versioning"]["state"],"not_applicable")
  self.assertEqual((d["restore_drill"]["rpo_seconds"],d["restore_drill"]["rto_seconds"]),(600,4000))
  stale=scenario("stale")
  self.assertEqual(stale["signals"]["offsite_copy"]["state"],"unknown")
  self.assertEqual((stale["restore_drill"]["status"],stale["restore_drill"]["reported_status"]),("UNKNOWN","BREACHED"))

 def test_reasons_work_classes_and_provenance_are_preserved_and_unsafe_health_fails_closed(self):
  d=scenario("valid")
  self.assertEqual(d["reasons"],["RTO_BREACHED"]);self.assertEqual(d["work_item_classes"],["rto_breached"])
  self.assertEqual(d["health"]["source"],"factory:recovery-health-v1")
  for c in ("unsafe-authority","execute-true","sensitive-health"):
   with self.subTest(case=c):self.assertTrue(scenario(c))

 def test_view_is_read_only_without_factory_calls_writes_scheduler_ranking_or_dispatch(self):
  d=scenario("valid");self.assertFalse(d["execution"]);self.assertEqual(d["actions"],[]);self.assertTrue(scenario("caller-action"))
  src=(ROOT/"src"/"RecoveryCenterUi.php").read_text()
  for x in ("curl_","file_put_contents","shell_exec","create_issue","dispatch(","schedule(","rank("):self.assertNotIn(x,src)

 def test_docs_preserve_factory_health_authority_and_execution_boundary(self):
  d=(ROOT/"docs"/"disaster-recovery-ui.md").read_text()
  for x in ("Factory #331","única autoridad","no lo recalcula","execution=false","no crea WorkItems"):self.assertIn(x,d)

if __name__=="__main__":unittest.main()

import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests/factory_live_orchestrator_snapshot_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return json.loads(r.stdout)

class FactoryLiveOrchestratorSnapshotTests(unittest.TestCase):
 def test_snapshot_derives_dispatcher_fronts_and_owner_latency_from_canonical_factory_live_snapshot(self):
  d=scenario("full");self.assertTrue(d["read_only"]);self.assertEqual(d["central"]["activity_state"],"ACTIVE")
  self.assertEqual((d["central"]["available"],d["central"]["reserved"],d["central"]["blocked"]),(1,1,3))
  fronts={x["issue_ref"]:x for x in d["fronts"]};self.assertEqual(fronts["github:pl0n3r/ControlBot#620"]["progress_percent"],40)
  self.assertEqual(fronts["github:pl0n3r/Factory#856"]["progress_state"],"UNKNOWN")
  decision=d["owner_decisions"][0];self.assertEqual(decision["issue_ref"],"github:pl0n3r/ControlBot#700");self.assertEqual(decision["age_seconds"],70)
 def test_unknown_stale_or_mismatched_evidence_never_becomes_healthy_progress(self):
  d=scenario("fail_closed");self.assertEqual(d["stale"]["central"]["activity_state"],"STALE")
  front=next(x for x in d["stale"]["fronts"] if x["issue_ref"]=="github:pl0n3r/ControlBot#620")
  self.assertEqual((front["status"],front["progress_state"],front["progress_percent"]),("STALE","UNKNOWN",None))
  self.assertEqual(d["missing"]["fronts"],[]);self.assertTrue(d["mismatch_blocked"])
 def test_projection_is_read_only_bounded_secret_and_pii_free(self):
  d=scenario("safety");self.assertTrue(d["bounded"]);self.assertTrue(d["secret_blocked"]);self.assertTrue(d["deterministic"])
  s=(ROOT/"src/FactoryLiveOrchestratorSnapshot.php").read_text().lower()
  for v in ("apiclient","apitransport","api.github.com","curl_","file_get_contents","fsockopen","entitymanager","pdo","'post'","'patch'","'put'","'delete'"):self.assertNotIn(v,s)
if __name__=="__main__":unittest.main()

import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests/factory_live_snapshot_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return json.loads(r.stdout)

class FactoryLiveSnapshotTests(unittest.TestCase):
 def test_snapshot_contains_all_factory_live_sections(self):
  d=scenario("full"); self.assertEqual(d["version"],1); self.assertEqual(d["observed_at"],200)
  self.assertEqual(list(d["sections"]),["batches","owner_decisions","releases","blockers","production","quality","work","learning"])
  self.assertTrue(all(d["sections"].values())); self.assertEqual(d["tool_usage"]["authority"],"tool_usage"); self.assertEqual(len(d["fingerprint"]),64)
 def test_every_signal_preserves_source_observed_at_and_freshness(self):
  d=scenario("full")
  for rows in d["sections"].values():
   for x in rows: self.assertIsInstance(x["source_ref"],str); self.assertIsInstance(x["observed_at"],int); self.assertEqual(x["freshness"],"current"); self.assertEqual(x["age_seconds"],200-x["observed_at"])
  self.assertEqual(d["sections"]["owner_decisions"][0]["data"]["issue_ref"],"https://github.com/pl0n3r/ControlBot/issues/100")
 def test_missing_stale_and_ambiguous_evidence_fails_closed(self):
  d=scenario("fail_closed"); x=d["missing"]; self.assertEqual((x["state"],x["freshness"]),("unknown","unknown")); self.assertIsNone(x["source_ref"]); self.assertIsNone(x["observed_at"])
  self.assertEqual(d["blocked"],{"stale":True,"duplicate":True,"authority":True})
 def test_snapshot_is_read_only_bounded_deterministic_and_secret_free(self):
  d=scenario("safety"); self.assertTrue(d["secret"]); self.assertTrue(d["bounded"]); self.assertTrue(d["deterministic"])
  s=(ROOT/"src/FactoryLiveSnapshot.php").read_text()
  for value in ("ApiClient","ApiTransport","curl_","file_get_contents","fsockopen","EntityManager","PDO","'POST'","'PATCH'","'DELETE'"): self.assertNotIn(value,s)
 def test_existing_authorities_are_composed_without_parallel_state(self):
  self.assertEqual(scenario("simulated")["authorities"],{"batches":"factory_plan","blockers":"github_project_snapshot","learning":"incident_lesson","owner_decisions":"owner_inbox","production":"observability_project_status","quality":"quality_health","releases":"github_project_snapshot","work":"github_project_snapshot"})
 def test_simulated_github_sonar_and_learning_cover_every_section(self):
  d=scenario("simulated"); self.assertEqual(d["source_kinds"],["factory","github","learning","observability","sonar","tool_usage"]); self.assertEqual(len(d["authorities"]),8)
if __name__=="__main__": unittest.main()

import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests/github_project_snapshot_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return json.loads(r.stdout)

class GitHubProjectSnapshotTests(unittest.TestCase):
 def test_snapshot_uses_project_repositories_and_get_only_allowlisted_routes(self):
  d=scenario("routes"); self.assertEqual([x["repository"] for x in d["snapshot"]["repositories"]],["pl0n3r/ControlBot","pl0n3r/Factory"]); self.assertEqual(d["snapshot"]["observed_at"],200); self.assertEqual(len(d["calls"]),16)
  for method,url,_headers,body in d["calls"]: self.assertEqual(method,"GET"); self.assertIsNone(body); self.assertTrue(url.startswith("https://api.github.com/repos/"))
 def test_snapshot_normalizes_main_checks_prs_issues_release_and_workflow(self):
  x=scenario("normalize")["snapshot"]["repositories"][0]; self.assertEqual(x["main_sha"],"a"*40); self.assertEqual([c["status"] for c in x["checks"]["items"]],["completed","in_progress"])
  self.assertEqual(x["pull_requests"]["items"][0]["number"],7); self.assertEqual(x["pull_requests"]["items"][0]["review_state"],"approved"); self.assertEqual(x["pull_requests"]["items"][0]["mergeability"],"mergeable"); self.assertEqual([i["number"] for i in x["issues"]["items"]],[9]); self.assertEqual(x["latest_release"]["tag_name"],"v0.1.20"); self.assertEqual(x["latest_workflow"]["run_number"],123)
 def test_open_pr_review_and_mergeability_are_bounded_get_only_and_normalized(self):
  d=scenario("routes"); repo=d["snapshot"]["repositories"][0]; pr=repo["pull_requests"]["items"][0]
  self.assertEqual(pr["review_state"],"approved"); self.assertEqual(pr["mergeability"],"mergeable"); self.assertFalse(repo["pull_requests"]["truncated"])
  detail=[call for call in d["calls"] if "/pulls/7" in call[1]]
  self.assertEqual(len(detail),4)
  for method,url,_headers,body in detail: self.assertEqual(method,"GET"); self.assertIsNone(body); self.assertTrue(url.startswith("https://api.github.com/repos/"))
  bounded=scenario("bounded"); detail=[call for call in bounded["calls"] if "/pulls/7" in call[1]]
  self.assertEqual(len(detail),40); self.assertTrue(bounded["bounded"]["pull_requests"]["truncated"])
  for method,url,_headers,body in detail: self.assertEqual(method,"GET"); self.assertIsNone(body); self.assertTrue(url.startswith("https://api.github.com/repos/"))
 def test_unknown_null_or_invalid_pr_review_evidence_fails_closed_without_ready_state(self):
  d=scenario("review-invalid")
  self.assertEqual(d["bad_review"]["items"][0]["review_state"],"unknown"); self.assertTrue(d["bad_review"]["truncated"])
  self.assertEqual(d["null_mergeable"]["items"][0]["mergeability"],"unknown"); self.assertEqual(d["null_mergeable"]["items"][0]["review_state"],"approved"); self.assertTrue(d["null_mergeable"]["truncated"])
  self.assertEqual(d["bad_mergeability"]["items"][0]["mergeability"],"unknown"); self.assertTrue(d["bad_mergeability"]["truncated"])
 def test_malformed_or_ambiguous_github_state_fails_closed(self):
  self.assertEqual(scenario("invalid")["blocked"],{"bad-sha":True,"bad-check":True,"bad-workflow":True,"project":True})
 def test_lists_are_bounded_and_release_absence_is_explicit(self):
  d=scenario("bounded"); x=d["bounded"]; self.assertTrue(all(x[k]["truncated"] for k in ("checks","pull_requests","issues"))); self.assertTrue(all(len(x[k]["items"])==100 for k in ("checks","pull_requests","issues"))); self.assertIsNone(d["no_release"])
 def test_projection_has_no_write_or_secret_surface(self):
  s=(ROOT/"src/GitHubProjectSnapshot.php").read_text(); u=s.upper(); l=s.lower()
  for v in ("'POST'","'PATCH'","'PUT'","'DELETE'"): self.assertNotIn(v,u)
  for v in ("dispatchworkflow","movetag(","closeissue(","commentissue(","token","password","secret"): self.assertNotIn(v,l)
  self.assertIn("'observed_at'=>$observedAt",s)
if __name__=="__main__": unittest.main()

import json, os, subprocess, unittest
from pathlib import Path
from urllib.parse import parse_qs, urlparse

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
 r=subprocess.run(
  ["php",str(ROOT/"tests/factory_orchestrator_evidence_collector_scenarios.php"),name],
  cwd=ROOT,check=True,text=True,capture_output=True,timeout=30
 )
 return json.loads(r.stdout)

class OrchestratorEvidenceCollectorTests(unittest.TestCase):
 def test_collector_is_cli_only_disabled_by_default_and_fails_closed_without_valid_token_file(self):
  script=ROOT/"scripts/orchestrator-evidence-collector.php"
  r=subprocess.run(["php",str(script)],cwd=ROOT,text=True,capture_output=True)
  self.assertEqual(0,r.returncode);self.assertEqual("disabled",json.loads(r.stdout)["state"])
  env=os.environ|{
   "CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED":"1",
   "CONTROLBOT_GITHUB_READ_TOKEN_FILE":"/missing",
   "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH":"/tmp/cb-evidence.json",
  }
  r=subprocess.run(["php",str(script)],cwd=ROOT,text=True,capture_output=True,env=env)
  self.assertNotEqual(0,r.returncode)

 def test_evidence_is_canonical_for_snapshot_source_with_injected_github_responses(self):
  d=scenario("canonical")
  self.assertTrue(d["snapshot"]["read_only"])
  self.assertEqual("UNKNOWN",d["snapshot"]["central"]["activity_state"])
  self.assertNotIn("work_inventory",d["evidence"])
  self.assertTrue(d["snapshot"]["fronts"])
  self.assertEqual([],d["evidence"]["releases"])
  statuses={row["data"].get("status") for row in d["evidence"]["work"]}
  self.assertEqual({"unknown","in_review","merged"},statuses)
  self.assertFalse(any(row["data"].get("status")=="blocked" for row in d["evidence"]["work"]))
  available=next(row for row in d["evidence"]["work"] if row["id"].endswith("-10"))
  self.assertEqual(["estado: disponible"],available["data"]["labels"])
  self.assertEqual("unknown",available["data"]["status"])
  self.assertTrue(any(row["id"].endswith("-11") for row in d["evidence"]["blockers"]))
  self.assertFalse(any(row["id"]=="blocker:factory-767" for row in d["evidence"]["blockers"]))

 def test_only_get_requests_are_made_within_request_and_byte_budgets(self):
  d=scenario("canonical")
  self.assertEqual(len(d["calls"]),d["result"]["requests"])
  self.assertLessEqual(d["result"]["requests"],40)
  self.assertLessEqual(d["result"]["download_bytes"],2_000_000)
  self.assertLessEqual(d["result"]["evidence_bytes"],2_000_000)
  self.assertTrue(all(x["method"]=="GET" for x in d["calls"]))
  self.assertTrue(all(x["url"].startswith("https://api.github.com/") for x in d["calls"]))
  self.assertTrue(all(x["user_agent"]=="controlbot-orchestrator-evidence-collector/1" for x in d["calls"]))
  self.assertTrue(all(x["api_version"]=="2022-11-28" for x in d["calls"]))

  contract=scenario("transport-contract")
  self.assertEqual(contract["retry_calls"],contract["retry_result"]["requests"])
  self.assertGreater(contract["retry_calls"],15)
  self.assertTrue(contract["missing_bytes_failed"])
  self.assertEqual('{"old":true}',contract["previous"])

  recent=scenario("recent-pulls")
  pull_urls=[u for u in recent["calls"] if urlparse(u).path.endswith("/pulls")]
  self.assertEqual(7,len(pull_urls))
  for url in pull_urls:
   query=parse_qs(urlparse(url).query)
   self.assertEqual(["100"],query.get("per_page"))
   self.assertEqual(["1"],query.get("page"))
  self.assertFalse(any("page=2" in url and urlparse(url).path.endswith("/pulls") for url in recent["calls"]))

  script=(ROOT/"scripts/orchestrator-evidence-collector.php").read_text()
  self.assertIn("CURLPROTO_HTTPS",script)
  self.assertIn("($parts['scheme']??null)!=='https'",script)
  self.assertIn("($parts['host']??null)!=='api.github.com'",script)
  self.assertIn("if($status!==200)return $base;",script)

 def test_kill_switch_requires_single_exact_owner_marker_and_merged_prs_are_not_releases(self):
  d=scenario("kill-switch-cases")
  self.assertEqual([],d["canonical"])
  for case in ("wrong_number","wrong_author","duplicate","invalid"):
   self.assertEqual(1,len(d[case]),case)

  canonical=scenario("canonical")
  self.assertEqual([],canonical["evidence"]["releases"])
  merged=[row for row in canonical["evidence"]["work"] if row["data"].get("status")=="merged"]
  self.assertTrue(merged)
  self.assertTrue(all(row["id"].startswith("work:") for row in merged))

 def test_transport_requires_real_bytes_and_retries_non_json_5xx(self):
  self.test_only_get_requests_are_made_within_request_and_byte_budgets()

 def test_kill_switch_requires_issue_767_single_exact_owner_marker(self):
  self.test_kill_switch_requires_single_exact_owner_marker_and_merged_prs_are_not_releases()

 def test_work_evidence_preserves_allowlisted_labels_without_parallel_status_derivation(self):
  d=scenario("canonical")
  available=next(row for row in d["evidence"]["work"] if row["id"].endswith("-10"))
  self.assertEqual("unknown",available["data"]["status"])
  self.assertEqual(["estado: disponible"],available["data"]["labels"])
  self.assertFalse(any(row["data"].get("status")=="blocked" for row in d["evidence"]["work"]))
  source=(ROOT/"src/FactoryOrchestratorEvidenceCollector.php").read_text(encoding="utf-8")
  self.assertNotIn("function status(",source)

 def test_recent_merged_pr_lookup_is_bounded_and_runbook_matches_guardrails(self):
  recent=scenario("recent-pulls")
  pull_urls=[u for u in recent["calls"] if urlparse(u).path.endswith("/pulls")]
  self.assertEqual(7,len(pull_urls))
  for url in pull_urls:
   query=parse_qs(urlparse(url).query)
   self.assertEqual(["100"],query.get("per_page"))
   self.assertEqual(["1"],query.get("page"))
  self.assertFalse(any("page=2" in url and urlparse(url).path.endswith("/pulls") for url in recent["calls"]))
  self.assertEqual([],recent["evidence"]["releases"])
  self.assertEqual(7,len([row for row in recent["evidence"]["work"] if row["data"].get("status")=="merged"]))
  doc=(ROOT/"docs/runbooks/orchestrator-snapshot-cron.md").read_text(encoding="utf-8")
  for phrase in ("40 requests","2 MB","una página reciente","HTTPS","PR fusionado"):
   self.assertIn(phrase,doc)

 def test_token_never_appears_in_output_logs_evidence_or_snapshot(self):
  d=scenario("canonical");blob=json.dumps(d)
  self.assertNotIn("sentinel-read-value",blob)
  self.assertTrue(all(x["authorized"] for x in d["calls"]))

 def test_error_or_rate_limit_keeps_previous_evidence_and_never_leaves_partial_file(self):
  d=scenario("failure")
  self.assertTrue(d["failed"])
  self.assertEqual('{"old":true}',d["previous"])

if __name__=="__main__":
 unittest.main()

import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests"/"momentum_work_origin_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return json.loads(r.stdout)

class MomentumWorkOriginTests(unittest.TestCase):
 def test_valid_signal_materializes_factory_work_item_v1(self):
  d=scenario("valid"); w=d["work_item"]
  self.assertEqual(d["status"],"materialized"); self.assertFalse(d["execution"])
  self.assertEqual((w["origin_mode"],w["origin_system"],w["authority_level"]),("automatic","momentum","l2_venture_admin"))
  self.assertEqual(w["work_type"],"marketing_growth"); self.assertEqual(w["producer_ref"],"momentum:work-origin")
  self.assertEqual(w["required_roles"],["datos-analitica","marketing"])
  self.assertEqual(w["policy_ref"],"controlbot:policy/business-os-v1"); self.assertEqual(w["budget_ref"],"budget:"+"1"*32)
  required={"work_id","origin_mode","origin_system","group_id","work_type","requested_capabilities","required_roles",
   "authority_level","producer_ref","priority_class","depends_on","claims","policy_ref","evidence_refs","idempotency_key"}
  optional={"venture_id","project_id","repository_ref","severity","budget_ref","approval_ref","observed_at"}
  self.assertTrue(required<=set(w)); self.assertTrue(set(w)<=required|optional); self.assertNotIn("freshness",w); self.assertNotIn("execution",w)

 def test_authority_budget_unknown_stale_and_owner_gate_fail_closed(self):
  d=scenario("gates")
  for key,row in d.items():
   self.assertEqual(row["status"],"blocked",key); self.assertIsNone(row["work_item"],key); self.assertFalse(row["execution"],key)
  self.assertIn("authority_owner_gate",d["owner"]["reasons"]); self.assertIn("capital_denied",d["budget"]["reasons"])
  self.assertIn("authority_unknown",d["unknown"]["reasons"]); self.assertEqual(d["paid_stale"]["freshness"],"stale")
  self.assertEqual(d["origin_unknown"]["freshness"],"unknown"); self.assertIn("experiment_result_unknown",d["experiment_unknown"]["reasons"])
  self.assertIn("email_not_eligible",d["email_denied"]["reasons"])

 def test_idempotency_claims_evidence_and_freshness_are_preserved(self):
  d=scenario("identity"); a,b=d["a"]["work_item"],d["b"]["work_item"]
  self.assertEqual(a,b); self.assertEqual(a["idempotency_key"],"momentum:condor:campaign-1:growth")
  self.assertEqual(a["claims"],sorted(a["claims"])); self.assertEqual(a["evidence_refs"],sorted(a["evidence_refs"]))
  self.assertEqual(a["observed_at"],"2026-09-30T03:40:00Z")

 def test_momentum_end_to_end_uses_existing_contracts(self):
  d=scenario("e2e"); self.assertEqual(d["out"]["status"],"materialized")
  self.assertIn(d["creative"]["variant_id"],d["campaign"]["creative_variant_refs"])
  self.assertTrue(d["email"]["marketing_eligible"]); self.assertEqual(d["experiment"]["status"],"completed")
  self.assertEqual(d["paid"]["campaign_id"],d["campaign"]["campaign_id"])
  self.assertEqual(d["performance"]["campaign_ref"],d["campaign"]["campaign_id"])

 def test_no_parallel_scheduler_provider_execution_secrets_or_pii(self):
  d=scenario("unsafe")
  for row in d.values(): self.assertEqual(row["reasons"],["invalid_input"])
  source=(ROOT/"src"/"MomentumWorkOrigin.php").read_text(encoding="utf-8").lower()
  for forbidden in ("curl_","factoryrunner","scheduler","enqueue","mysqli","pdo(","mail(","->send","provider_api"):
   self.assertNotIn(forbidden,source)

 def test_docs_define_institution_boundaries(self):
  docs=(ROOT/"docs"/"momentum-work-origin.md").read_text(encoding="utf-8")
  for word in ("MOMENTUM","Factory","FactoryRunner","AEGIS","CAPITAL","readiness","WorkItem"): self.assertIn(word,docs)
  self.assertIn("no crea cola",docs); self.assertIn("server-side",docs)

if __name__=="__main__": unittest.main()

import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests"/"momentum_performance_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return json.loads(r.stdout)

class MomentumPerformanceTests(unittest.TestCase):
 def test_performance_is_venture_campaign_and_period_scoped(self):
  d=scenario("scope"); self.assertEqual(d["ok"]["venture_id"],"venture-condor")
  for k in ("venture","campaign","period","paid_denied"): self.assertTrue(d[k],k)

 def test_spend_lead_conversion_revenue_margin_require_compatible_evidence(self):
  d=scenario("chain"); x=d["full"]["derived"]
  self.assertEqual(x,{"spend_minor":2500000,"leads":100,"conversions":20,"observed_revenue_minor":9000000,
   "inferred_revenue_minor":None,"cost_minor":1000000,"margin_minor":5500000})
  self.assertNotEqual(x["spend_minor"],d["planned"])
  self.assertEqual(d["full"]["paid_media_spend_ref"],d["full"]["spend"]["governed_spend_ref"])
  self.assertIsNone(d["unknown_spend"]["derived"]["spend_minor"]); self.assertIsNone(d["unknown_spend"]["derived"]["margin_minor"])

 def test_observed_inferred_unknown_remain_distinct(self):
  d=scenario("classification")
  self.assertEqual(d["observed"]["derived"]["observed_revenue_minor"],9000000)
  self.assertIsNone(d["observed"]["derived"]["inferred_revenue_minor"])
  self.assertIsNone(d["inferred"]["derived"]["observed_revenue_minor"]); self.assertEqual(d["inferred"]["derived"]["inferred_revenue_minor"],9000000)
  self.assertIsNone(d["inferred"]["derived"]["margin_minor"])
  self.assertIsNone(d["unknown"]["derived"]["observed_revenue_minor"]); self.assertIsNone(d["unknown"]["derived"]["inferred_revenue_minor"])

 def test_unit_economics_are_unknown_when_inputs_are_insufficient(self):
  d=scenario("unit"); u=d["full"]["unit_economics"]
  self.assertEqual(u["cac_minor"],125000); self.assertEqual(u["roas_milli"],3600)
  self.assertEqual(u["payback_classification"],"unknown"); self.assertEqual(u["ltv_classification"],"unknown")
  self.assertIsNone(d["zero"]["unit_economics"]["cac_minor"]); self.assertIsNone(d["no_cost"]["derived"]["margin_minor"])
  self.assertIsNone(d["inferred"]["unit_economics"]["roas_milli"])

 def test_currency_period_freshness_and_evidence_fail_closed(self):
  d=scenario("guardrails")
  for k in ("paid_stale","currency","duplicate","missing","outside","spend_ref"): self.assertTrue(d[k],k)
  self.assertEqual(d["stale"]["freshness"],"stale"); self.assertIsNone(d["stale"]["derived"]["spend_minor"])
  self.assertIsNone(d["pipeline_stale"]["derived"]["spend_minor"]); self.assertIsNone(d["pipeline_stale"]["derived"]["observed_revenue_minor"])
  self.assertEqual(d["stale"]["evidence_refs"],sorted(d["stale"]["evidence_refs"]))

 def test_projection_has_no_pii_tracking_provider_or_side_effects(self):
  d=scenario("pure"); self.assertEqual(d["methods"],["project"]); self.assertFalse(d["execution"])
  source=(ROOT/"src"/"MomentumPerformance.php").read_text(encoding="utf-8").lower()
  for bad in ("curl_","http://","https://","pdo(","mysqli","factoryrunner","scheduler","workitem","decisionrights","capitalpolicy","provider","tracking","email","phone"):
   self.assertNotIn(bad,source)

if __name__=="__main__": unittest.main()

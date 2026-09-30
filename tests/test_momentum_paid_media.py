import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests"/"momentum_paid_media_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return json.loads(r.stdout)

class MomentumPaidMediaTests(unittest.TestCase):
 def test_authority_requires_nominal_verified_access_context(self):
  d=scenario("nominal"); self.assertEqual(d["ok"]["status"],"planned")
  self.assertEqual(d["array"]["status"],"denied"); self.assertEqual(d["object"]["status"],"denied")

 def test_caller_cannot_supply_or_downgrade_server_authority(self):
  d=scenario("authority"); self.assertEqual(d["caller"]["reasons"],["invalid_input"])
  for k in ("launch_l1","pause_l1","reallocate_l1"):
   self.assertEqual(d[k]["status"],"owner_decision_required")
   self.assertEqual(d[k]["authority"]["required_authority_level"],"L2_VENTURE_ADMIN")

 def test_verified_authority_mismatch_deny_and_owner_fail_closed(self):
  d=scenario("mismatch")
  for k in ("scope","capability","policy","invalid"): self.assertEqual(d[k]["status"],"denied")
  self.assertEqual(d["owner"]["status"],"owner_decision_required")
  for row in d.values(): self.assertFalse(row["execution"])

 def test_future_and_age_stale_evidence_fail_closed(self):
  d=scenario("freshness"); self.assertEqual(d["current"]["status"],"planned")
  self.assertEqual(d["future"]["reasons"],["evidence_from_future"])
  self.assertEqual(d["expired"]["reasons"],["evidence_expired"])
  self.assertEqual(d["stale"]["reasons"],["evidence_stale"]); self.assertEqual(d["unknown"]["reasons"],["evidence_unknown"])

 def test_spend_evidence_and_blast_radius_are_scoped_and_deterministic(self):
  d=scenario("evidence"); self.assertEqual(d["a"],d["b"]); spend=d["a"]["spend"]
  self.assertEqual(spend["spend_ref"],"spend:cccccccccccccccccccccccccccccccc")
  self.assertEqual(spend["evidence_refs"],["evidence:dddddddddddddddddddddddddddddddd","evidence:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"])
  self.assertEqual(d["duplicate"]["status"],"denied")
  self.assertEqual(d["a"]["spend"]["blast_radius"],"medium")
  self.assertEqual(d["blast"]["spend"]["blast_radius"],"high")
  self.assertEqual(d["blast"]["status"],"planned")
  self.assertEqual(d["invalid_blast"]["status"],"denied")
  self.assertEqual(d["expand"]["status"],"owner_decision_required")

 def test_governance_has_no_provider_payment_queue_or_side_effects(self):
  d=scenario("pure"); self.assertEqual(d["methods"],["plan"]); self.assertFalse(d["plan"]["execution"])
  source=(ROOT/"src"/"MomentumPaidMedia.php").read_text(encoding="utf-8").lower()
  for bad in ("curl_","http://","https://","factoryrunner","scheduler","mysqli","pdo(","mail(","->send","payment("): self.assertNotIn(bad,source)

 def test_authority_and_budget_are_canonical_gates_not_refs(self):
  authority=scenario("authority"); evidence=scenario("evidence")
  self.assertEqual(authority["caller"]["status"],"denied")
  self.assertEqual(evidence["capital_mismatch"]["status"],"denied")

 def test_owner_deny_unknown_and_stale_fail_closed(self):
  mismatch=scenario("mismatch"); freshness=scenario("freshness")
  self.assertEqual(mismatch["owner"]["status"],"owner_decision_required")
  self.assertEqual(mismatch["policy"]["status"],"denied")
  self.assertEqual(freshness["stale"]["status"],"denied")
  self.assertEqual(freshness["unknown"]["status"],"denied")

if __name__=="__main__": unittest.main()

import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"momentum_paid_media_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)

class MomentumPaidMediaTests(unittest.TestCase):
    def test_plan_is_paid_campaign_and_venture_scoped(self):
        d=scenario("scope")
        self.assertEqual(d["ok"]["status"],"planned")
        self.assertEqual(d["ok"]["venture_id"],"venture-condor")
        self.assertEqual(d["ok"]["channel"],"paid_social")
        self.assertEqual(d["wrong_scope"]["status"],"denied")
        self.assertEqual(d["organic"]["status"],"denied")

    def test_authority_and_budget_are_canonical_gates_not_refs(self):
        d=scenario("gates")
        self.assertEqual(d["authority"]["status"],"denied")
        self.assertIn("authority_denied",d["authority"]["reasons"])
        self.assertEqual(d["capital"]["status"],"denied")
        self.assertIn("capital_denied",d["capital"]["reasons"])

    def test_owner_deny_unknown_and_stale_fail_closed(self):
        d=scenario("closed")
        self.assertEqual(d["owner"]["status"],"owner_decision_required")
        self.assertEqual(d["stale"]["status"],"denied")
        self.assertEqual(d["unknown"]["status"],"denied")
        for row in d.values(): self.assertFalse(row["execution"])

    def test_spend_expansion_cannot_self_certify(self):
        d=scenario("reallocation")
        self.assertEqual(d["same"]["status"],"planned")
        self.assertEqual(d["more"]["status"],"owner_decision_required")
        self.assertIn("spend_expansion_requires_owner",d["more"]["reasons"])

    def test_secret_scopes_are_opaque_and_sensitive_payloads_fail_closed(self):
        d=scenario("secrets")
        self.assertEqual(d["opaque"]["secret_scope_ref"],"scope:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
        self.assertEqual(d["secret"]["status"],"denied")
        self.assertEqual(d["extra"]["status"],"denied")

    def test_governance_has_no_provider_payment_queue_or_side_effects(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["plan"])
        self.assertFalse(d["plan"]["execution"])
        source=(ROOT/"src"/"MomentumPaidMedia.php").read_text().lower()
        for forbidden in ("curl_","http://","https://","providerapi","execute payment","scheduler","factory queue"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"vendor_exception_signal_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)

class VendorExceptionSignalTests(unittest.TestCase):
    def test_material_vendor_conditions_emit_only_supported_deterministic_signals(self):
        d=scenario("material")
        self.assertEqual(d["types"],[
            "health_degraded","legal_review_rejected","missing_exit_plan",
            "missing_export_capability","renewal_due","security_review_rejected",
        ])
        self.assertEqual([x["type"] for x in d["signals"]],sorted(d["types"]))

    def test_critical_unknown_or_stale_never_becomes_healthy_incident_or_outage(self):
        d=scenario("unknown")
        self.assertEqual(d["types"],["critical_unknown","missing_exit_plan","missing_export_capability"])
        signal=d["signals"][0]
        self.assertEqual(signal["freshness"],"unknown")
        self.assertEqual(signal["health"],"unknown")
        payload=json.dumps(d).lower()
        for forbidden in ("healthy","incident","outage"):
            self.assertNotIn(forbidden,payload)

    def test_signals_preserve_scope_provenance_freshness_and_dates_without_sensitive_payloads(self):
        d=scenario("provenance")
        self.assertTrue(d["signals"])
        for signal in d["signals"]:
            self.assertEqual(signal["venture_id"],"venture-alpha")
            self.assertRegex(signal["vendor_id"],r"^vendor:[a-f0-9]{32}$")
            self.assertEqual(signal["freshness"],"fresh")
            self.assertEqual(signal["observed_at"],1500)
            self.assertTrue(signal["source_ref"].startswith("evidence:"))
            self.assertIn("capital:44444444444444444444444444444444",signal["evidence_refs"])
        payload=json.dumps(d).lower()
        for forbidden in ("credential:77777777777777777777777777777777","owner_ref","email","password","token"):
            self.assertNotIn(forbidden,payload)

    def test_projection_does_not_infer_priority_authority_budget_decision_or_procurement_action(self):
        payload=scenario("boundary")["payload"].lower()
        for forbidden in (
            "priority_class","authority_level","budget_ref","approval_ref","decision",
            "purchase","payment","renew_vendor","offboard_vendor",
        ):
            self.assertNotIn(forbidden,payload)

    def test_signals_are_deduplicated_ordered_and_consumer_neutral(self):
        d=scenario("deterministic")
        self.assertEqual(d["a"],d["b"])
        self.assertEqual(d["types"],sorted(set(d["types"])))
        payload=json.dumps(d["a"]).lower()
        for forbidden in ("fyi","watch","critical_owner","owner_inbox","workitem","producer_ref"):
            self.assertNotIn(forbidden,payload)

    def test_contract_has_no_persistence_provider_workitem_scheduler_or_procurement_execution(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["project"])
        source=d["source"].lower()
        for forbidden in (
            "pdo(","mysqli","curl_","provider","workitem","factoryrunner","scheduler",
            "purchase","payment","renew(","offboard(",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

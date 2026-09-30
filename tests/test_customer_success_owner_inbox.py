import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"customer_success_owner_inbox_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    return json.loads(run.stdout)

class CustomerSuccessOwnerInboxProjectionTests(unittest.TestCase):
    def test_support_signal_projects_safe_venture_scoped_owner_inbox_entry(self):
        d=scenario("support"); e=d["entry"]
        self.assertEqual(e["scope"],{"kind":"venture","ref":"controlbot:venture/venture-condor"})
        self.assertEqual(e["class"],"watch")
        self.assertEqual(e["freshness"],"current")
        self.assertEqual(e["observed_at"],995)
        self.assertTrue(e["source_ref"].startswith("controlbot:customer-success/source/"))
        self.assertTrue(all(x.startswith("controlbot:evidence/customer-success/") for x in e["evidence_refs"]))
        payload=d["payload"].lower()
        for forbidden in ("support:"+"5"*32,"evidence:"+"8"*32,"ticket_body","transcript"):
            self.assertNotIn(forbidden,payload)

    def test_snapshot_dimension_preserves_freshness_and_inferred_semantics(self):
        d=scenario("snapshot")
        self.assertEqual(d["dimension"]["name"],"churn_risk")
        self.assertEqual(d["dimension"]["nature"],"inferred")
        self.assertEqual(d["dimension"]["confidence"],0.7)
        self.assertEqual(d["entry"]["freshness"],"current")
        self.assertEqual(d["entry"]["class"],"fyi")
        self.assertIsNotNone(d["entry"]["source_ref"])

    def test_priority_and_authority_are_explicit_inputs_not_inferred(self):
        d=scenario("explicit")
        self.assertEqual(d["watch"]["class"],"watch")
        self.assertIsNone(d["watch"]["required_authority_level"])
        self.assertEqual(d["decision"]["class"],"decision")
        self.assertEqual(d["decision"]["required_authority_level"],"L4_OWNER")
        self.assertEqual(d["decision"]["decision_ref"],"controlbot:decision/customer-success-review")

    def test_unknown_cross_scope_extra_fields_and_sensitive_material_fail_closed(self):
        d=scenario("closed")
        self.assertEqual(d["unknown"]["freshness"],"unknown")
        self.assertIsNone(d["unknown"]["source_ref"])
        self.assertIsNone(d["unknown"]["observed_at"])
        self.assertEqual(d["unknown"]["evidence_refs"],[])
        self.assertEqual(d["stale"]["freshness"],"stale")
        self.assertTrue(d["cross"]);self.assertTrue(d["missing"]);self.assertTrue(d["extra"]);self.assertTrue(d["pii"])

    def test_output_is_owner_inbox_contract_compatible(self):
        d=scenario("compatible")
        self.assertEqual(d["entry"],d["again"])

    def test_projection_has_no_external_io_or_execution(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["fromSnapshotDimension","fromSupport"])
        source=d["source"].lower()
        for forbidden in ("pdo(","mysqli","curl_","provider","workitem","factoryrunner","scheduler","notify(","dispatch"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

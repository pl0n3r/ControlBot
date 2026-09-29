import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"vendor_owner_inbox_projection_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    return json.loads(run.stdout)

class VendorOwnerInboxProjectionTests(unittest.TestCase):
    def test_only_material_emitted_vendor_signal_can_enter_owner_inbox(self):
        d=scenario("material")
        self.assertEqual(d["entry"]["class"],"watch")
        self.assertTrue(d["entry"]["entry_ref"].startswith("controlbot:vendor-exception/inbox/"))
        self.assertTrue(d["fake"])

    def test_class_authority_and_decision_are_explicit_not_inferred_from_vendor_state(self):
        d=scenario("explicit")
        self.assertEqual(d["watch"]["class"],"watch")
        self.assertIsNone(d["watch"]["required_authority_level"])
        self.assertIsNone(d["watch"]["decision_ref"])
        self.assertEqual(d["decision"]["class"],"decision")
        self.assertEqual(d["decision"]["required_authority_level"],"L4_OWNER")
        self.assertEqual(d["decision"]["decision_ref"],"controlbot:decision/vendor-review")

    def test_scope_freshness_and_provenance_are_deterministic_controlbot_refs_without_sensitive_payloads(self):
        d=scenario("provenance")
        e=d["entry"]
        self.assertEqual(e["scope"],{"kind":"venture","ref":"controlbot:venture/venture-alpha"})
        self.assertEqual(e["freshness"],"current")
        self.assertEqual(e["observed_at"],1500)
        self.assertTrue(e["source_ref"].startswith("controlbot:vendor-exception/source/"))
        self.assertTrue(all(ref.startswith(("controlbot:vendor-exception/signal/","controlbot:evidence/vendor/")) for ref in e["evidence_refs"]))
        payload=d["payload"].lower()
        for forbidden in ("credential:","identity:33333333333333333333333333333333","evidence:cccccccccccccccccccccccccccccccc"):
            self.assertNotIn(forbidden,payload)

    def test_unknown_freshness_has_empty_provenance_and_observed_states_preserve_time(self):
        d=scenario("freshness")
        self.assertEqual(d["unknown"]["freshness"],"unknown")
        self.assertIsNone(d["unknown"]["source_ref"])
        self.assertIsNone(d["unknown"]["observed_at"])
        self.assertEqual(d["unknown"]["evidence_refs"],[])
        self.assertEqual(d["stale"]["freshness"],"stale")
        self.assertEqual(d["stale"]["observed_at"],1500)
        self.assertIsNotNone(d["stale"]["source_ref"])

    def test_output_obeys_owner_inbox_class_contract(self):
        d=scenario("classes")
        self.assertEqual(d["watch"]["class"],"watch")
        self.assertTrue(d["watch_authority"])
        self.assertTrue(d["decision_missing"])
        self.assertEqual(d["critical"]["required_authority_level"],"L4_OWNER")

    def test_bridge_has_no_policy_decisionrights_workitems_procurement_provider_or_persistence(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["project"])
        source=d["source"].lower()
        for forbidden in (
            "pdo(","mysqli","curl_","provider","workitem","factoryrunner","scheduler",
            "purchase","payment","renew(","offboard(","decisionrights",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

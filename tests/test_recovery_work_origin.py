import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"recovery_work_origin_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)

class RecoveryWorkOriginTests(unittest.TestCase):
    def test_canonical_recovery_class_builds_automatic_aegis_factory_work_item(self):
        item=scenario("valid")
        self.assertEqual((item["origin_mode"],item["origin_system"],item["producer_ref"]),("automatic","aegis","controlbot:aegis/recovery"))
        self.assertEqual(item["project_id"],"controlbot")
        self.assertEqual(item["evidence_refs"],["controlbot:recovery-health/controlbot-001"])
        self.assertTrue(item["idempotency_key"].startswith("recovery:rto_breached:"))

    def test_authority_priority_type_capabilities_roles_and_policy_are_explicit_inputs(self):
        item=scenario("explicit")
        self.assertEqual(item["authority_level"],"owner_reviewed")
        self.assertEqual(item["priority_class"],"critical")
        self.assertEqual(item["work_type"],"operations")
        self.assertEqual(item["requested_capabilities"],["incident.repair"])
        self.assertEqual(item["required_roles"],["sre"])
        self.assertEqual(item["policy_ref"],"factory:recovery-critical")

    def test_only_present_canonical_classes_from_nonhealthy_factory_health_can_origin_work(self):
        self.assertEqual(scenario("unknown")["project_id"],"controlbot")
        for case in ("healthy","class-absent","unknown-class","expanded-health","incoherent-drill","sensitive-health"):
            with self.subTest(case=case):
                self.assertTrue(scenario(case)["blocked"])

    def test_evidence_idempotency_and_observed_at_are_deterministic_without_readiness_escalation(self):
        first,second=scenario("order-a"),scenario("order-b")
        self.assertEqual(first["idempotency_key"],second["idempotency_key"])
        self.assertEqual(first["observed_at"],"2026-09-29T20:00:00Z")
        unknown=scenario("unknown")
        self.assertEqual(unknown["observed_at"],"2026-09-29T20:00:00Z")
        for forbidden in ("ready","approved","authorized","freshness"):
            self.assertNotIn(forbidden,unknown)

    def test_factory_optional_refs_are_preserved_without_dispatch_fields(self):
        item=scenario("optionals")
        self.assertEqual(item["venture_id"],"venture-main")
        self.assertEqual(item["repository_ref"],"pl0n3r/ControlBot")
        self.assertEqual(item["budget_ref"],"capital:recovery")
        self.assertEqual(item["approval_ref"],"owner:recovery")
        self.assertEqual(item["severity"],"high")
        for forbidden in ("provider","model","executor","rank","ready"):
            self.assertNotIn(forbidden,item)

    def test_bridge_has_no_factory_calls_persistence_ranking_scheduler_or_parallel_queue(self):
        source=(ROOT/"src"/"RecoveryWorkOrigin.php").read_text()
        for forbidden in ("file_get_contents","curl_","shell_exec","create_issue","dispatch(","schedule(","new PDO","mysqli"):
            self.assertNotIn(forbidden,source)
        docs=(ROOT/"docs"/"disaster-recovery-work-origin.md").read_text()
        self.assertIn("Factory #269",docs)
        self.assertIn("Factory #331",docs)
        self.assertIn("no crea Issues",docs)
        self.assertIn("no rankea",docs)

if __name__=="__main__":
    unittest.main()

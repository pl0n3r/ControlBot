import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "finance_runtime_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class FinanceRuntimeTests(unittest.TestCase):
    def test_finance_reads_are_venture_scoped(self):
        data = scenario("scope")
        allowed = data["allowed"]
        self.assertEqual(allowed["status"], "allow")
        self.assertEqual(allowed["state"], "green")
        self.assertEqual(allowed["scope"], "venture:condor")
        self.assertEqual(allowed["snapshot"]["venture_id"], "condor")

        cross = data["cross"]
        self.assertEqual(cross["status"], "deny")
        self.assertEqual(cross["state"], "blocked")
        self.assertIsNone(cross["snapshot"])
        self.assertIsNone(cross["planning"])

    def test_out_of_authority_finance_action_reuses_owner_flow(self):
        data = scenario("owner")
        result = data["result"]
        self.assertEqual(result["status"], "owner_decision_required")
        self.assertFalse(result["execution"])
        self.assertEqual(result["authority_source"], "CapitalPolicy")
        self.assertIsNotNone(result["owner_decision_gate"])
        self.assertEqual(data["gate"], {
            "category": "money",
            "recommendation": "B",
            "safe_default": "B",
        })

    def test_factory_handoff_preserves_provenance_without_pii(self):
        data = scenario("factory")
        self.assertEqual(data["queue"], "factory")
        self.assertTrue(data["ready_hint"])
        self.assertEqual(data["freshness"], "fresh")
        item = data["work_item"]
        self.assertEqual(item["origin_system"], "capital")
        self.assertEqual(item["venture_id"], "condor")
        self.assertEqual(item["repository_ref"], "pl0n3r/Condor")
        self.assertEqual(item["work_type"], "finance_analysis")
        self.assertEqual(item["producer_ref"], "controlbot:finance/runtime")
        self.assertEqual(item["observed_at"], "2026-09-28T15:00:00Z")
        self.assertIn("controlbot:finance/snapshot-condor", item["evidence_refs"])
        encoded = json.dumps(item, sort_keys=True)
        for forbidden in ("customers", "transactions", "revenue_streams", "cash_in", "cash_out"):
            self.assertNotIn(forbidden, encoded)

    def test_existing_budget_and_decision_authorities_are_reused(self):
        data = scenario("authorities")
        allowed = data["allowed"]
        self.assertEqual(allowed["status"], "allow")
        self.assertEqual(allowed["authority_source"], "CapitalPolicy")
        self.assertEqual(allowed["decision_rights_source"], "DecisionRights")
        evidence = allowed["capital"]["authority_evidence"]
        self.assertIn("budget_guard", evidence)
        self.assertIn("decision_rights", evidence)

        denied = data["denied"]
        self.assertEqual(denied["status"], "deny")
        self.assertIn("decision_rights_denied", denied["reasons"])
        self.assertFalse(denied["execution"])

    def test_unknown_or_stale_finance_state_never_becomes_green(self):
        data = scenario("freshness")
        self.assertEqual(data["stale"]["status"], "allow")
        self.assertEqual(data["stale"]["state"], "stale")
        self.assertNotEqual(data["stale"]["state"], "green")
        self.assertEqual(data["unknown"]["state"], "unknown")
        self.assertNotEqual(data["unknown"]["state"], "green")
        self.assertFalse(data["handoff"]["ready_hint"])
        self.assertEqual(data["handoff"]["freshness"], "stale")

    def test_snapshot_to_owner_or_work_handoff_e2e(self):
        data = scenario("e2e")
        self.assertTrue(data["same_read"])
        self.assertEqual(data["read"]["status"], "allow")
        self.assertEqual(data["read"]["state"], "green")
        self.assertEqual(data["action"]["status"], "owner_decision_required")
        self.assertFalse(data["action"]["execution"])
        self.assertEqual(data["handoff"]["queue"], "factory")
        self.assertTrue(data["handoff"]["ready_hint"])
        self.assertIsNotNone(data["handoff"]["work_item"])


if __name__ == "__main__":
    unittest.main()

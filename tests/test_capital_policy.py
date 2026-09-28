import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "capital_policy_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class CapitalPolicyTests(unittest.TestCase):
    def test_budget_reserve_and_proposal_use_exact_scope_and_minor_units(self):
        data = scenario("base")
        self.assertEqual(data["scope"], "venture:condor")
        self.assertEqual(data["currency"], "COP")
        self.assertEqual(data["decision"], "allow")
        self.assertFalse(data["execution"])
        self.assertIsInstance(data["proposal"]["amount_minor"], int)
        self.assertIsInstance(data["budget"]["limit_minor"], int)
        self.assertIsInstance(data["reserve"]["cash_available_minor"], int)
        self.assertEqual(data["budget"]["after_proposal_minor"], 12_000_000)
        self.assertEqual(data["reserve"]["cash_after_proposal_minor"], 45_000_000)

    def test_budget_and_decision_rights_only_restrict(self):
        data = scenario("restrictions")
        self.assertEqual(data["baseline"]["decision"], "allow")
        self.assertEqual(data["budget_guard"]["decision"], "deny")
        self.assertIn("budget_guard_restricted", data["budget_guard"]["reasons"])
        self.assertEqual(data["decision_rights"]["decision"], "deny")
        self.assertIn("decision_rights_denied", data["decision_rights"]["reasons"])

    def test_out_of_limit_l4_or_irreversible_requires_owner_decision(self):
        data = scenario("owner-gates")
        for key in ("over_limit", "l4", "irreversible", "reserve_floor"):
            self.assertEqual(data[key]["decision"], "owner_decision_required")
            self.assertFalse(data[key]["execution"])
        self.assertIn("budget_limit_exceeded", data["over_limit"]["reasons"])
        self.assertIn("owner_authority_required", data["l4"]["reasons"])
        self.assertIn("irreversible_action", data["irreversible"]["reasons"])
        self.assertIn("reserve_floor_breached", data["reserve_floor"]["reasons"])

    def test_shared_costs_without_provenance_remain_unallocated(self):
        data = scenario("shared-costs")
        by_id = {row["cost_id"]: row for row in data["shared_costs"]}
        self.assertEqual(by_id["cost-infra"]["status"], "allocated")
        self.assertEqual(by_id["cost-infra"]["target_scope"], "venture:condor")
        self.assertEqual(by_id["cost-shared"]["status"], "unallocated")
        self.assertIsNone(by_id["cost-shared"]["target_scope"])
        self.assertIsNone(by_id["cost-shared"]["rule_ref"])
        self.assertIsNone(by_id["cost-shared"]["provenance_ref"])

    def test_capital_never_executes_payments_or_returns_financial_credentials(self):
        data = scenario("no-payments")
        self.assertEqual(data["evaluated"]["decision"], "deny")
        self.assertFalse(data["evaluated"]["execution"])
        self.assertIn("does not execute payments", data["payment"])
        self.assertFalse(data["leaked"])

    def test_capital_result_is_deterministic_and_fail_closed(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(data["unknown"]["decision"], "deny")
        self.assertIn("unknown_financial_state", data["unknown"]["reasons"])
        self.assertEqual(data["mismatch"], {
            "version": 1,
            "decision": "deny",
            "reasons": ["invalid_input"],
            "execution": False,
        })
        self.assertEqual(data["invalid_gate"]["decision"], "deny")
        self.assertFalse(data["invalid_gate"]["execution"])
        self.assertIn("decision_rights_denied", data["invalid_gate"]["reasons"])
        self.assertIn("invalid_input", data["invalid_gate"]["reasons"])


if __name__ == "__main__":
    unittest.main()

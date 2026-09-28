import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "finance_cost_center_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class FinanceCostCenterTests(unittest.TestCase):
    def test_venture_and_institution_cost_center_remain_distinct(self):
        data = scenario("distinct")
        self.assertEqual(data["venture"]["kind"], "venture")
        self.assertEqual(data["venture"]["scope"], "venture:condor")
        self.assertEqual(data["institution"]["kind"], "institution_cost_center")
        self.assertEqual(data["institution"]["scope"], "institution:factory")
        self.assertNotEqual(data["venture"]["kind"], data["institution"]["kind"])

    def test_canonical_institution_cost_centers_have_exact_scopes(self):
        data = scenario("canonical")
        by_id = {row["id"]: row for row in data}
        expected = {
            "factory": ("Factory", "institution:factory"),
            "controlbot": ("ControlBot", "institution:controlbot"),
            "runner": ("Runner", "institution:runner"),
            "aegis": ("AEGIS", "institution:aegis"),
            "momentum": ("MOMENTUM", "institution:momentum"),
            "capital": ("CAPITAL", "institution:capital"),
        }
        self.assertEqual(set(by_id), set(expected))
        for entity_id, (title, scope) in expected.items():
            self.assertEqual(by_id[entity_id]["kind"], "institution_cost_center")
            self.assertEqual(by_id[entity_id]["title"], title)
            self.assertEqual(by_id[entity_id]["scope"], scope)

    def test_kind_scope_crossovers_fail_closed(self):
        data = scenario("crossovers")
        self.assertIn("mismatch", data["institution_as_venture_scope"])
        self.assertIn("mismatch", data["reserved_id_as_venture"])
        self.assertIn("mismatch", data["venture_as_institution_scope"])

    def test_institution_attribution_preserves_explicit_provenance(self):
        data = scenario("attribution")
        self.assertEqual(data["target_kind"], "institution_cost_center")
        self.assertEqual(data["target_id"], "controlbot")
        self.assertEqual(data["target_scope"], "institution:controlbot")
        self.assertEqual(data["provenance_ref"], "controlbot:finance/source-shared-ai")
        self.assertEqual(data["source_ref"], "controlbot:finance/attribution-shared-ai")
        self.assertEqual(data["amount_minor"], 1_500_000)
        self.assertEqual(data["currency"], "COP")
        self.assertEqual(data["freshness"], "fresh")

    def test_unknown_or_mismatched_cost_center_is_rejected(self):
        data = scenario("unknown")
        self.assertIn("mismatch", data["unknown_institution"])
        self.assertIn("mismatch", data["wrong_title"])
        self.assertIn("invalid", data["unknown_kind"])

    def test_normalization_is_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(
            [(row["kind"], row["id"]) for row in data["first"]],
            [
                ("institution_cost_center", "capital"),
                ("venture", "condor"),
                ("venture", "grindflow"),
            ],
        )


if __name__ == "__main__":
    unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "lex_runtime_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class LexRuntimeTests(unittest.TestCase):
    def test_market_to_pack_to_gap_gate_work_evidence_reevaluation_flow(self):
        data = scenario("flow")
        self.assertEqual(data["market"]["jurisdictions"], ["country:CO"])
        self.assertEqual([p["pack_id"] for p in data["selected_packs"]], ["pack-co-v1"])
        self.assertEqual(data["before"]["summary"]["counts"]["gap"], 1)
        self.assertIsNotNone(data["gate"]["work_item"])
        self.assertIsNone(data["gate"]["human_gate"])
        self.assertEqual(data["after"]["summary"]["counts"]["compliant"], 1)
        self.assertEqual(data["reevaluation_state"], "compliant")
        self.assertEqual(
            data["after"]["obligations"][0]["evidence_refs"],
            ["controlbot:lex/evidence/privacy"],
        )

    def test_global_market_scope_never_means_no_jurisdiction(self):
        data = scenario("global")
        runtime = data["runtime"]
        self.assertEqual(runtime["market"]["mode"], "global")
        self.assertEqual(runtime["market"]["jurisdictions"], ["country:CO", "country:MX"])
        self.assertEqual(
            [p["jurisdiction"] for p in runtime["selected_packs"]],
            ["country:CO", "country:MX"],
        )
        self.assertEqual(runtime["jurisdiction_state"], "covered")
        self.assertTrue(data["empty_blocked"])

    def test_insufficient_evidence_never_becomes_compliant(self):
        data = scenario("insufficient")
        self.assertTrue(data["missing_evidence_blocked"])
        self.assertEqual(data["stale"]["after"]["obligations"][0]["status"], "unknown")
        self.assertEqual(data["stale"]["reevaluation_state"], "unknown")
        self.assertNotEqual(data["stale"]["reevaluation_state"], "compliant")

    def test_e2e_never_executes_provider_or_expands_factory_authority(self):
        data = scenario("authority")
        self.assertFalse(data["provider_executed"])
        self.assertEqual(data["factory_authority"], "unchanged")
        self.assertEqual(data["authority_effect"], "none")
        self.assertFalse(data["gate"]["auto_execute"])
        self.assertEqual(data["gate"]["authority_effect"], "none")
        self.assertNotIn("provider", data)
        docs = (ROOT / "docs" / "lex-e2e.md").read_text(encoding="utf-8")
        for marker in (
            "Factory Queue",
            "FactoryRunner",
            "sin providers reales",
            "authority=unchanged",
            "global",
            "UNKNOWN",
        ):
            self.assertIn(marker, docs)


if __name__ == "__main__":
    unittest.main()

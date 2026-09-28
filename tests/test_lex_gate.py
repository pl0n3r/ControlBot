import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "lex_gate_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class LexGateTests(unittest.TestCase):
    def test_material_legal_uncertainty_creates_human_gate_with_safe_default(self):
        data = scenario("gate")
        gate = data["human_gate"]

        self.assertEqual(data["kind"], "material_uncertainty")
        self.assertEqual(gate["category"], "legal")
        self.assertEqual(gate["safe_default"], "B")
        self.assertEqual(gate["recommendation"], "B")
        self.assertEqual(gate["options"][1]["effect"], "No se concede aprobación y el scope afectado continúa bloqueado.")
        self.assertEqual(gate["blocks"], "venture:condor")
        self.assertFalse(data["ready_hint"])

    def test_executable_gap_creates_factory_work_item(self):
        data = scenario("work")
        item = data["work_item"]

        required = {
            "work_id", "origin_mode", "origin_system", "group_id", "work_type",
            "requested_capabilities", "required_roles", "authority_level",
            "producer_ref", "priority_class", "severity", "depends_on", "claims",
            "policy_ref", "evidence_refs", "observed_at", "idempotency_key",
            "venture_id", "project_id", "repository_ref",
        }
        self.assertEqual(set(item), required)
        self.assertEqual(item["origin_mode"], "automatic")
        self.assertEqual(item["origin_system"], "controlbot")
        self.assertEqual(item["work_type"], "compliance_review")
        self.assertEqual(item["repository_ref"], "pl0n3r/Condor")
        self.assertEqual(item["claims"], ["venture:condor"])
        self.assertNotIn("provider", item)
        self.assertNotIn("executor", item)
        self.assertNotIn("model", item)
        self.assertIsNone(data["human_gate"])

    def test_unknown_or_stale_evidence_is_never_ready(self):
        data = scenario("stale")
        for name in ("stale", "unknown"):
            with self.subTest(name=name):
                self.assertFalse(data[name]["ready_hint"])
                self.assertFalse(data[name]["auto_execute"])
                self.assertEqual(data[name]["authority_effect"], "none")

    def test_pending_gate_never_expands_authority(self):
        data = scenario("authority")
        gate = data["human_gate"]

        self.assertFalse(data["auto_execute"])
        self.assertEqual(data["authority_effect"], "none")
        self.assertFalse(data["ready_hint"])
        self.assertEqual(gate["safe_default"], "B")
        self.assertEqual(
            set(gate),
            {
                "category", "context", "title_simple", "summary_simple",
                "why_recommended", "blocks", "options", "recommendation",
                "safe_default",
            },
        )

    def test_equivalent_work_items_are_idempotent(self):
        data = scenario("idempotency")
        first = data["first"]["work_item"]
        second = data["second"]["work_item"]

        self.assertEqual(first, second)
        self.assertEqual(first["idempotency_key"], second["idempotency_key"])
        self.assertEqual(len(data["batch"]), 1)
        self.assertEqual(data["batch"][0], first)


if __name__ == "__main__":
    unittest.main()

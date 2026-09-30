"""E2E acceptance contract for Infrastructure Control Center (#191)."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario():
    """Run the deterministic PHP E2E fixture."""
    run = subprocess.run(
        ["php", str(ROOT / "tests/infrastructure_control_center_scenarios.php")],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(run.stdout)


class InfrastructureControlCenterTests(unittest.TestCase):
    def test_end_to_end_intent_to_verified_evidence_uses_existing_control_planes(self):
        data = scenario()
        self.assertEqual(data["fresh_effective"], "online")
        self.assertIn("controlbot:capability/commerce", data["impact"]["capability_refs"])
        self.assertEqual(data["plan"]["status"], "planned")
        self.assertIsNotNone(data["plan"]["work_item"])
        self.assertIsNotNone(data["plan"]["runner_request"])
        self.assertEqual(data["action"]["status"], "planned")
        self.assertFalse(data["action"]["execution"])
        self.assertTrue(data["runner_health"]["eligible"])
        self.assertEqual(data["order"]["work_item_id"], data["plan"]["work_item"]["work_id"])
        self.assertEqual(data["completed"]["state"], "completed")
        self.assertEqual(data["completed"]["evidence"]["code"], "verified")

    def test_end_to_end_high_risk_and_stale_paths_fail_closed(self):
        data = scenario()
        self.assertEqual(data["owner"]["status"], "owner_decision_required")
        self.assertIsNone(data["owner"]["runner_request"])
        self.assertIsNotNone(data["owner"]["work_item"]["approval_ref"])
        self.assertEqual(data["authority_denied"]["status"], "denied")
        self.assertIsNone(data["authority_denied"]["runner_request"])
        self.assertEqual(data["budget_denied"]["status"], "denied")
        self.assertIn("financial_evidence_unknown", data["budget_denied"]["reasons"])
        self.assertEqual(data["stale_effective"], "unknown")
        self.assertIsNone(data["stale_order"])

    def test_no_parallel_queue_direct_provider_path_or_secret_payload(self):
        data = scenario()
        self.assertEqual(data["plan"]["work_item"]["origin_system"], "controlbot")
        self.assertEqual(
            data["plan"]["runner_request"]["work_item_id"],
            data["plan"]["work_item"]["work_id"],
        )
        self.assertTrue(data["secret_instruction_rejected"])
        self.assertTrue(data["secret_evidence_rejected"])
        serialized = json.dumps(data).lower()
        for forbidden in ("password", "bearer ", "github_pat_", "supersecret"):
            self.assertNotIn(forbidden, serialized)
        source = (ROOT / "tests/infrastructure_control_center_scenarios.php").read_text()
        for direct in ("curl_", "Guzzle", "Aws\\", "HostingerConnection", "ssh2_"):
            self.assertNotIn(direct, source)

    def test_docs_define_authority_execution_and_cost_boundaries(self):
        docs = (ROOT / "docs/infrastructure-control-center.md").read_text().lower()
        for term in ("controlbot", "factory", "factoryrunner", "aegis", "capital"):
            self.assertIn(term, docs)
        self.assertIn("cola única", docs)
        self.assertIn("no llama directamente", docs)
        self.assertIn("no concede authority operacional", docs)


if __name__ == "__main__":
    unittest.main()

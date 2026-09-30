"""Acceptance contract for governed infrastructure action projection (#205)."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario():
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "infrastructure_action_projection_scenarios.php")],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(run.stdout)


class InfrastructureActionProjectionTests(unittest.TestCase):
    def test_projection_accepts_only_governed_intent_output(self):
        data = scenario()["planned"]
        self.assertEqual(data["status"], "planned")
        self.assertEqual(data["authority_decision"], "allow")
        self.assertEqual(
            data["source_ref"],
            "controlbot:infrastructure-intent/intent-205",
        )
        self.assertEqual(data["work_item_id"], "infra-intent-205")
        self.assertTrue(data["runner_request_ready"])
        self.assertFalse(data["execution"])

    def test_projection_preserves_owner_gate_and_deny(self):
        data = scenario()
        owner = data["owner"]
        self.assertEqual(owner["status"], "owner_decision_required")
        self.assertEqual(
            owner["approval_ref"],
            "controlbot:approval/infra-intent-owner",
        )
        self.assertFalse(owner["runner_request_ready"])
        denied = data["denied"]
        self.assertEqual(denied["status"], "denied")
        self.assertIsNone(denied["work_item_id"])
        self.assertIsNone(denied["approval_ref"])
        self.assertFalse(denied["runner_request_ready"])

    def test_projection_never_grants_execution_or_exposes_secrets(self):
        data = scenario()
        for key in ("planned", "owner", "denied"):
            self.assertFalse(data[key]["execution"])
        serialized = json.dumps(data).lower()
        for forbidden in ("password", "bearer ", "github_pat_", "supersecret"):
            self.assertNotIn(forbidden, serialized)
        self.assertTrue(data["secret_rejected"])

    def test_caller_cannot_self_certify_planned_allow(self):
        data = scenario()
        for key in (
            "fake_plan_rejected",
            "caller_status_rejected",
            "fabricated_array_rejected",
            "fabricated_object_rejected",
            "cross_capability_rejected",
        ):
            self.assertTrue(data[key], key)


if __name__ == "__main__":
    unittest.main()

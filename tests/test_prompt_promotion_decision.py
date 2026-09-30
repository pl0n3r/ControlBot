import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "prompt_promotion_decision_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)

class PromptPromotionDecisionTests(unittest.TestCase):
    def test_only_approved_current_and_candidate_successor_are_eligible(self):
        data = scenario("eligibility")
        self.assertEqual(data["valid"]["decision"], "eligible_for_human_approval")
        for key in ("current_not_approved", "candidate_not_candidate", "wrong_successor", "contract_drift"):
            self.assertTrue(data[key], (key, data))

    def test_evaluation_must_match_versions_fingerprint_and_advisory_authority(self):
        data = scenario("binding")
        self.assertTrue(data["version"])
        self.assertTrue(data["authority"])
        self.assertTrue(data["fingerprint"])
        self.assertTrue(data["template"])

    def test_only_candidate_better_is_eligible_for_human_approval(self):
        data = scenario("decision")
        self.assertEqual(data["better"]["decision"], "eligible_for_human_approval")
        self.assertEqual(data["hold"]["decision"], "hold")

    def test_human_gate_is_always_required_and_inputs_are_not_mutated(self):
        data = scenario("human_gate")
        self.assertTrue(data["human_gate_required"])
        self.assertEqual(data["authority"], "human_approval_required")
        self.assertTrue(data["history_unchanged"])
        self.assertEqual(data["current_status"], "approved")
        self.assertEqual(data["candidate_status"], "candidate")

    def test_decision_reasons_and_fingerprint_are_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["fingerprint_same"])
        self.assertEqual(data["one"]["reasons"], ["evaluation_supports_candidate"])

    def test_decision_propagates_and_validates_result_fingerprints(self):
        data = scenario("provenance")
        self.assertEqual(
            data["out"]["current_result_fingerprint"],
            data["evaluation"]["current_result_fingerprint"],
        )
        self.assertEqual(
            data["out"]["candidate_result_fingerprint"],
            data["evaluation"]["candidate_result_fingerprint"],
        )
        self.assertTrue(data["tampered_current"])
        self.assertTrue(data["tampered_candidate"])

    def test_result_provenance_preserves_human_gate_and_fail_closed_authority(self):
        data = scenario("provenance_gate")
        for key in ("eligible", "hold"):
            self.assertTrue(data[key]["human_gate_required"])
            self.assertEqual(data[key]["authority"], "human_approval_required")
            self.assertEqual(len(data[key]["current_result_fingerprint"]), 64)
            self.assertEqual(len(data[key]["candidate_result_fingerprint"]), 64)
        self.assertEqual(data["eligible"]["decision"], "eligible_for_human_approval")
        self.assertEqual(data["hold"]["decision"], "hold")
        self.assertTrue(data["same_set"])

    def test_result_fingerprint_format_and_required_keys_fail_closed(self):
        data = scenario("provenance_format")
        self.assertEqual(len(data), 8)
        for key, rejected in data.items():
            self.assertTrue(rejected, key)

    def test_promotion_decision_is_pure_and_cannot_expand_authority(self):
        data = scenario("pure")
        self.assertEqual(data["hits"], [])
        self.assertEqual(data["authority"], "human_approval_required")
        self.assertTrue(data["human_gate_required"])

if __name__ == "__main__":
    unittest.main()

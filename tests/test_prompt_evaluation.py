import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "prompt_evaluation_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)

class PromptEvaluationTests(unittest.TestCase):
    def test_comparison_requires_same_task_class_and_evaluation_set(self):
        data = scenario("compatibility")
        self.assertEqual(data["valid"]["decision"], "candidate_better")
        self.assertEqual(data["valid"]["template_id"], "support-agent")
        self.assertTrue(data["task"])
        self.assertTrue(data["set"])
        self.assertTrue(data["older_candidate"])

    def test_metrics_must_be_compatible_finite_and_well_sampled(self):
        data = scenario("metrics")
        self.assertTrue(data["unknown"])
        self.assertTrue(data["shape"])
        self.assertTrue(data["sample"])

    def test_safety_or_policy_failure_blocks_candidate_better(self):
        data = scenario("safety")
        for key in ("unsafe", "policy"):
            self.assertEqual(data[key]["decision"], "keep_current")
            self.assertIn("candidate_safety_or_policy_failed", data[key]["reasons"])

    def test_tie_or_insufficient_evidence_keeps_current_approved_version(self):
        data = scenario("keep")
        self.assertEqual(data["tie"]["decision"], "keep_current")
        self.assertEqual(data["tie"]["current_version"], 1)
        self.assertIn("tie_keep_current", data["tie"]["reasons"])
        self.assertEqual(data["small"]["decision"], "keep_current")
        self.assertIn("insufficient_evidence", data["small"]["reasons"])

    def test_decision_and_fingerprint_are_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["a"]["fingerprint"], data["b"]["fingerprint"])

    def test_compare_binds_current_and_candidate_result_fingerprints(self):
        data = scenario("provenance")
        self.assertEqual(data["base"]["current_result_fingerprint"], data["current_helper"])
        self.assertEqual(data["base"]["candidate_result_fingerprint"], data["candidate_helper"])
        self.assertEqual(len(data["base"]["current_result_fingerprint"]), 64)
        self.assertEqual(len(data["base"]["candidate_result_fingerprint"]), 64)

    def test_result_fingerprint_changes_with_metrics_or_safety_policy(self):
        data = scenario("provenance")
        base = data["base"]["candidate_result_fingerprint"]
        for key in ("metric", "safety", "policy"):
            self.assertNotEqual(base, data[key]["candidate_result_fingerprint"], key)
            self.assertEqual(
                data["base"]["current_result_fingerprint"],
                data[key]["current_result_fingerprint"],
            )

    def test_compare_fingerprint_rejects_result_substitution(self):
        data = scenario("substitution")
        self.assertTrue(data["evaluation_changed"])
        self.assertTrue(data["current_stable"])
        self.assertTrue(data["candidate_changed"])
        self.assertTrue(data["old_candidate_rejects_substitute"])

    def test_evaluation_is_pure_and_cannot_expand_authority(self):
        data = scenario("pure")
        self.assertEqual(data["hits"], [])
        self.assertEqual(data["authority"], "advisory_only")
        self.assertEqual(data["decision"], "candidate_better")

if __name__ == "__main__":
    unittest.main()

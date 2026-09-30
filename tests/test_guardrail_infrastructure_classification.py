import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class GuardrailInfrastructureClassificationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        r=subprocess.run(
            ["php",str(ROOT/"tests"/"guardrail_infrastructure_classification_scenarios.php")],
            cwd=ROOT,check=True,text=True,capture_output=True,timeout=60
        )
        cls.d=json.loads(r.stdout)

    def test_pre_step_startup_failure_is_not_agent_stuck(self):
        self.assertEqual(self.d["one_pre"]["classification"],"unknown")
        self.assertNotEqual(self.d["one_pre"]["classification"],"agent_stuck")
        self.assertIsNone(self.d["one_pre"]["retry_intent"])

    def test_multi_repo_private_failure_with_healthy_public_contrast_is_infrastructure_blocked(self):
        d=self.d["blocked"]
        self.assertEqual(d["classification"],"blocked_by_infrastructure")
        self.assertEqual(d["retry_intent"]["action"],"suppress_retry")
        self.assertEqual(d["handoff"]["status"],"waiting_dependency")
        self.assertFalse(d["queue_release_allowed"])
        self.assertEqual(d,self.d["blocked_reversed"])

    def test_insufficient_or_conflicting_evidence_remains_unknown(self):
        self.assertEqual(self.d["stale"]["classification"],"unknown")
        self.assertEqual(self.d["older_external"]["classification"],"unknown")
        self.assertEqual(self.d["duplicate_same_repo"]["classification"],"unknown")
        self.assertEqual(self.d["same_repository_different_issues"]["classification"],"unknown")
        self.assertEqual(self.d["conflict"]["classification"],"unknown")
        self.assertEqual(self.d["public_not_started"]["classification"],"unknown")
        self.assertEqual(self.d["public_failed"]["classification"],"unknown")
        self.assertEqual(self.d["public_unknown"]["classification"],"unknown")
        self.assertEqual(self.d["unresolved_external_agent"]["classification"],"unknown")
        self.assertEqual(self.d["older_healthy_agent"]["classification"],"unknown")
        self.assertEqual(self.d["agent_only"]["classification"],"agent_stuck")

    def test_external_fingerprint_suppresses_duplicate_retries(self):
        d=self.d["deduped"]
        self.assertEqual(d["classification"],"blocked_by_infrastructure")
        self.assertTrue(d["retry_intent"]["deduplicated"])
        self.assertEqual(d["retry_intent"]["fingerprint"],"a"*64)

    def test_recovery_projects_exactly_one_canary_before_queue_release(self):
        d=self.d["recovery"]
        canary=d["canary_work_item"]
        self.assertIsNotNone(canary)
        self.assertEqual(canary["state"],"queued")
        self.assertEqual(canary["type"],"verification")
        self.assertEqual(canary["generation"],1)
        self.assertEqual(canary["attempt"],1)
        self.assertFalse(d["queue_release_allowed"])
        self.assertEqual(d,self.d["recovery_reversed"])
        self.assertIsNone(self.d["same_timestamp_recovery"]["canary_work_item"])
        self.assertIsNone(self.d["unrelated_recovery"]["canary_work_item"])
        self.assertIsNone(self.d["mismatched_active_recovery"]["canary_work_item"])
        self.assertIsNone(self.d["unchanged_fingerprint_recovery"]["canary_work_item"])
        self.assertIsNone(self.d["older_recovery"]["canary_work_item"])
        self.assertIsNone(self.d["old_recovery"]["canary_work_item"])

    def test_recovery_cross_repo_canary_uses_project_from_canonical_source(self):
        canary=self.d["recovery"]["canary_work_item"]
        self.assertEqual(canary["source_ref"],"pl0n3r/Condor#350")
        self.assertEqual(canary["project_id"],"condor")

    def test_cross_repo_canary_project_is_deterministic_when_evidence_order_changes(self):
        normal=self.d["recovery"]["canary_work_item"]
        reversed_=self.d["recovery_reversed"]["canary_work_item"]
        self.assertEqual(normal,reversed_)
        self.assertEqual(reversed_["project_id"],"condor")

    def test_canary_projection_keeps_queue_release_closed_and_has_no_side_effects(self):
        d=self.d["recovery"]
        self.assertFalse(d["queue_release_allowed"])
        self.assertIsNotNone(d["canary_work_item"])
        source=(ROOT/"src"/"GuardrailInfrastructureClassification.php").read_text(encoding="utf-8").lower()
        forbidden=("curl_","fsockopen","new pdo","mysqli","file_put_contents","shell_exec","proc_open","exec(","system(","mail(")
        self.assertFalse(any(x in source for x in forbidden))

    def test_handoff_preserves_external_cause_without_sensitive_material(self):
        h=self.d["blocked"]["handoff"]
        self.assertEqual(h["cause"],"blocked_by_infrastructure")
        self.assertEqual(h["dependency_ref"],"controlbot:dependency/github-actions-private")
        self.assertIn("controlbot:evidence/actions-quota",h["evidence_refs"])
        self.assertGreaterEqual(len(h["evidence_refs"]),3)
        text=json.dumps(h).lower()
        for word in ("password:","token:","authorization:","cookie:","@"):
            self.assertNotIn(word,text)

    def test_classifier_has_no_external_io_or_actions(self):
        source=(ROOT/"src"/"GuardrailInfrastructureClassification.php").read_text(encoding="utf-8").lower()
        forbidden=("curl_","fsockopen","new pdo","mysqli","file_put_contents","shell_exec","proc_open","exec(","system(","mail(")
        self.assertFalse(any(x in source for x in forbidden))

if __name__=="__main__":
    unittest.main()

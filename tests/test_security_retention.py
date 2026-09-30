import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class SecurityRetentionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        completed = subprocess.run(
            ["php", str(ROOT / "tests" / "security_event_retention_scenarios.php")],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
        )
        cls.d = json.loads(completed.stdout)

    def test_expired_event_preserves_minimum_audit_and_purges_context(self):
        plan = self.d["expired"]
        self.assertTrue(plan["expired"])
        self.assertEqual(plan["classifications"]["device"], "purge")
        self.assertEqual(plan["classifications"]["actor"], "purge")
        minimum = plan["retain_minimum"]
        for key in ("event_identity", "fingerprint", "event_type", "severity", "confidence", "observed_at"):
            self.assertIn(key, minimum)
        self.assertNotIn("provider", minimum)
        payload = json.dumps(minimum).lower()
        self.assertNotIn("pereira", payload)
        self.assertNotIn("risaralda", payload)

    def test_sensitive_material_is_never_retained_even_under_hold(self):
        plan = self.d["held"]
        self.assertTrue(plan["legal_hold"])
        for key in ("raw_payload", "secrets", "ip", "precise_location", "credentials"):
            self.assertEqual(plan["classifications"][key], "purge")
            self.assertNotIn(key, plan["retain_minimum"])
        self.assertEqual(plan["classifications"]["artifacts"], ["keep_reference", "redact", "purge", "purge"])
        self.assertEqual(plan["retained_artifact_references"], ["controlbot:evidence/security-evt-retention"])
        serialized = json.dumps(plan).lower()
        self.assertNotIn("password=never-retain", serialized)
        self.assertNotIn("github_pat_not_retained", serialized)
        self.assertNotIn("203.0.113.4", serialized)

    def test_opaque_references_survive_without_raw_payload(self):
        minimum = self.d["expired"]["retain_minimum"]
        self.assertEqual(minimum["account_scope"], "controlbot:account/owner")
        self.assertEqual(self.d["expired"]["classifications"]["account_scope"], "keep_reference")
        self.assertNotIn("raw_payload", minimum)

    def test_acknowledge_remains_reconstructable_after_purge(self):
        self.assertTrue(self.d["wrong_ack_rejected"])
        ack = self.d["expired"]["retain_minimum"]["acknowledge"]
        self.assertEqual(ack["state"], "acknowledged")
        self.assertTrue(ack["event_identity"].startswith("security-event:"))
        self.assertEqual(ack["observed_at"], 120)
        self.assertNotIn("actor_ref", ack)
        self.assertNotIn("context_ref", ack)

    def test_purge_plan_is_idempotent_for_same_policy_version(self):
        self.assertEqual(self.d["expired"], self.d["replay"])

    def test_policy_is_deterministic_by_event_type_and_severity(self):
        self.assertTrue(self.d["expired"]["expired"])
        self.assertFalse(self.d["login_plan"]["expired"])
        self.assertEqual(self.d["expired"]["retention_window_seconds"], 50)
        self.assertEqual(self.d["login_plan"]["retention_window_seconds"], 150)

    def test_policy_version_changes_are_explicit(self):
        first = self.d["expired"]
        same_version_changed = self.d["same_version_changed"]
        changed = self.d["changed"]
        self.assertEqual(first["policy_version"], 1)
        self.assertEqual(same_version_changed["policy_version"], 1)
        self.assertNotEqual(first["policy_fingerprint"], same_version_changed["policy_fingerprint"])
        self.assertNotEqual(first["purge_plan_id"], same_version_changed["purge_plan_id"])
        self.assertEqual(changed["policy_version"], 2)
        self.assertNotEqual(first["policy_fingerprint"], changed["policy_fingerprint"])
        self.assertNotEqual(first["purge_plan_id"], changed["purge_plan_id"])

    def test_retention_planner_has_no_external_io_or_actions(self):
        source = (ROOT / "src" / "SecurityEventRetention.php").read_text(encoding="utf-8").lower()
        forbidden = (
            "curl_", "fsockopen", "new pdo", "mysqli", "file_put_contents",
            "unlink(", "shell_exec", "proc_open", "exec(", "system(", "mail(",
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))


if __name__ == "__main__":
    unittest.main()

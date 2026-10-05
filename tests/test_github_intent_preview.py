import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"github_intent_preview_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if run.returncode: raise AssertionError(run.stderr.strip() or run.stdout.strip())
    return json.loads(run.stdout)

class GitHubIntentPreviewTests(unittest.TestCase):
    def test_preview_exposes_allowlisted_intent_policy_approval_and_evidence_with_execution_false(self):
        data=scenario("preview")
        live=data["live"]; owner=data["owner"]
        self.assertEqual(set(live["intent"]),{"intent_id","project_ref","repository_ref","type","params"})
        self.assertEqual(live["policy"]["decision"],"deny")
        self.assertEqual(live["policy"]["reasons"],["untrusted_evidence_freshness"])
        self.assertFalse(live["approval"]["required"])
        self.assertEqual(live["approval"]["state"],"unavailable")
        self.assertEqual(owner["policy"]["decision"],"owner_decision_required")
        self.assertTrue(owner["approval"]["required"])
        self.assertEqual(owner["approval"]["state"],"required")
        self.assertEqual(live["evidence_refs"],["github:evidence/main-7369763","controlbot:evidence/policy-687"])
        self.assertEqual(live["idempotency_key"],"intent:controlbot:680:1")
        for row in data.values():
            self.assertEqual(row["mutation_controls"],[])
            self.assertFalse(row["execution"])
        source=(ROOT/"tests"/"github_intent_preview_scenarios.php").read_text(encoding="utf-8")
        self.assertNotIn("hostinger.read",source)
        self.assertNotIn("database.restore",source)

    def test_denied_unknown_or_sensitive_context_never_exposes_mutation_controls_or_secrets(self):
        data=scenario("safe")
        self.assertEqual(data["deny"]["policy"]["decision"],"deny")
        self.assertEqual(data["unknown"]["policy"]["decision"],"unknown")
        for row in data.values():
            self.assertEqual(row["mutation_controls"],[])
            self.assertFalse(row["execution"])
        self.assertIsNone(data["sensitive"]["intent"])
        self.assertEqual(data["sensitive"]["evidence_refs"],[])
        self.assertNotIn("secret",json.dumps(data["sensitive"]).lower())
        self.assertIsNone(data["bad_policy"]["intent"])

if __name__=="__main__": unittest.main()

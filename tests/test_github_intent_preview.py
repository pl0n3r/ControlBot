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
        allow=data["allow"]; owner=data["owner"]
        self.assertEqual(set(allow["intent"]),{"intent_id","project_ref","repository_ref","type","params"})
        self.assertEqual(allow["policy"]["decision"],"allow")
        self.assertFalse(allow["approval"]["required"])
        self.assertEqual(owner["policy"]["decision"],"owner_decision_required")
        self.assertTrue(owner["approval"]["required"])
        self.assertEqual(allow["evidence_refs"],["github:evidence/main-96c31c2","controlbot:evidence/policy-679"])
        self.assertEqual(allow["idempotency_key"],"intent:controlbot:680:1")
        self.assertEqual(allow["mutation_controls"],[])
        self.assertFalse(allow["execution"])

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

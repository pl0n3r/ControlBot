import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"github_intent_envelope_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if run.returncode: raise AssertionError(run.stderr.strip() or f"scenario {name} failed")
    return json.loads(run.stdout)

class GitHubIntentEnvelopeTests(unittest.TestCase):
    def test_known_intent_types_use_closed_params_and_preserve_project_repo_idempotency_and_evidence_refs(self):
        data=scenario("known")["rows"]
        self.assertEqual(set(data),{"issue.create","issue.update","issue.close","issue.reserve","issue.release","pr.review","pr.merge","workflow.dispatch","release.approve","project.freeze","project.unfreeze"})
        for intent_type,row in data.items():
            self.assertEqual(row["type"],intent_type); self.assertEqual(row["project_ref"],"controlbot:project/controlbot"); self.assertEqual(row["repository_ref"],"pl0n3r/ControlBot")
            self.assertEqual(row["idempotency_key"],"intent:controlbot:678:1"); self.assertEqual(row["evidence_refs"],["controlbot:evidence/issue-677-a","github:evidence/main-9d5c73e"]); self.assertFalse(row["execution"])
        self.assertEqual(set(data["issue.close"]["params"]),{"issue_number"})
        self.assertEqual(set(data["pr.merge"]["params"]),{"pr_number","expected_head_sha","merge_method"})
        self.assertEqual(set(data["workflow.dispatch"]["params"]),{"workflow_ref","git_ref","inputs_ref"})

    def test_unknown_type_shell_git_freeform_secret_or_extra_param_fails_closed(self):
        self.assertTrue(all(scenario("invalid").values()))
        source=(ROOT/"src"/"GitHubIntentEnvelope.php").read_text().lower()
        for forbidden in ("curl_init(","file_get_contents('http",'file_get_contents("http',"shell_exec(","exec(","system(","proc_open(","github_pat_"):
            self.assertNotIn(forbidden,source)
if __name__=="__main__": unittest.main()

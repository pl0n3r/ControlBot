import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"github_intent_policy_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if run.returncode: raise AssertionError(run.stderr.strip() or run.stdout.strip())
    return json.loads(run.stdout)

class GitHubIntentPolicyTests(unittest.TestCase):
    def test_policy_reuses_existing_capability_and_approval_contracts_without_execution(self):
        data=scenario("reuse")
        self.assertEqual(data["allow"]["decision"],"allow")
        self.assertEqual(data["owner_required"]["decision"],"owner_decision_required")
        self.assertEqual(data["owner_allow"]["decision"],"allow")
        self.assertTrue(all(row["execution"] is False for row in data.values()))

    def test_unknown_stale_scope_mismatch_or_missing_authority_fails_closed_without_adapter_calls(self):
        data=scenario("closed")
        self.assertEqual(data["unknown"]["decision"],"unknown")
        self.assertEqual(data["stale"]["decision"],"deny")
        self.assertEqual(data["scope_mismatch"]["decision"],"deny")
        self.assertEqual(data["missing_authority"]["decision"],"deny")
        self.assertNotEqual(data["ambiguous"]["decision"],"allow")
        source=(ROOT/"src"/"GitHubIntentPolicy.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_init(","file_get_contents('http",'file_get_contents("http',"shell_exec(","exec(","system(","proc_open(","githubgateway","github_pat_"):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()

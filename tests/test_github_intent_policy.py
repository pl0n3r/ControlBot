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
        self.assertTrue(all(row["decision"]=="deny" for row in data.values()))
        self.assertTrue(all(row["reasons"]==["untrusted_evidence_freshness"] for row in data.values()))
        self.assertTrue(all(row["execution"] is False for row in data.values()))

    def test_unknown_stale_scope_mismatch_or_missing_authority_fails_closed_without_adapter_calls(self):
        data=scenario("closed")
        self.assertEqual(data["unknown"]["decision"],"unknown")
        self.assertEqual(data["stale"]["reasons"],["stale_evidence"])
        self.assertTrue(all(row["decision"]!="allow" for row in data.values()))
        source=(ROOT/"src"/"GitHubIntentPolicy.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_init(","file_get_contents('http",'file_get_contents("http',"shell_exec(","exec(","system(","proc_open(","githubgateway","github_pat_"):
            self.assertNotIn(forbidden,source)

    def test_semantically_unbound_capability_never_allows_github_intent(self):
        data=scenario("binding")
        self.assertTrue(all(row["decision"]=="deny" for row in data.values()))
        self.assertTrue(all(row["reasons"]==["untrusted_evidence_freshness"] for row in data.values()))
        self.assertTrue(all(row["execution"] is False for row in data.values()))
        source=(ROOT/"src"/"GitHubIntentPolicy.php").read_text(encoding="utf-8")
        self.assertNotIn("context['capability']",source)

    def test_freshness_without_evidence_binding_never_allows(self):
        data=scenario("freshness")
        self.assertEqual(data["unbound_ref"]["reasons"],["evidence_mismatch"])
        self.assertEqual(data["raw_fresh"]["reasons"],["untrusted_evidence_freshness"])
        self.assertEqual(data["stale_ref"]["reasons"],["stale_evidence"])
        self.assertTrue(all(row["decision"]=="deny" for row in data.values()))

if __name__=="__main__": unittest.main()

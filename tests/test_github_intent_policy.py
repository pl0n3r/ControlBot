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

    def test_invalid_execution_context_time_and_authority_fail_closed(self):
        data=scenario("invalid_context")
        self.assertEqual(data["execution"]["decision"],"unknown")
        self.assertEqual(data["execution"]["reasons"],["invalid_intent"])
        for key in ("list_context","extra_context","zero_now","future_observed","bad_ttl","bad_authority"):
            self.assertEqual(data[key]["decision"],"deny")
            self.assertEqual(data[key]["reasons"],["invalid_authority"])
            self.assertFalse(data[key]["execution"])


    def test_policy_consumes_trusted_evidence_and_authority_requirements_without_caller_freshness(self):
        data = scenario("trusted_valid")
        self.assertEqual(data["current"], {"decision": "deny", "reasons": ["grant_required"], "execution": False})
        self.assertEqual(data["last_valid_second"], data["current"])
        self.assertEqual(data["no_caller_ttl"]["reasons"], ["requirements_invalid"])
        self.assertEqual(data["no_caller_timestamp"]["decision"], "unknown")
        self.assertEqual(data["no_caller_timestamp"]["reasons"], ["invalid_intent"])
        source = (ROOT / "src" / "GitHubIntentPolicy.php").read_text()
        self.assertIn("GitHubIntentTrustedEvidence::derive(", source)
        self.assertIn("GitHubIntentAuthorityRequirements::describe(", source)
        self.assertIn("unset($raw['execution'])", source)
        self.assertNotIn("CapabilityGrant::issue(", source)

    def test_trusted_evidence_without_real_grant_or_approval_never_authorizes_or_calls_adapter(self):
        data = scenario("trusted_closed")
        self.assertEqual(len(data), 10)
        self.assertTrue(all(row["decision"] != "allow" and row["execution"] is False for row in data.values()))
        for key in ("missing_grant", "foreign_grant", "foreign_owner_approval"):
            self.assertEqual(data[key]["reasons"], ["grant_required"])
        self.assertEqual(data["stale"]["reasons"], ["untrusted_or_stale_snapshot"])
        self.assertEqual(data["duplicate_source"]["reasons"], ["untrusted_or_stale_snapshot"])
        for key in ("mismatched_scope", "mismatched_intent", "extra_context", "invalid_clock"):
            self.assertEqual(data[key]["reasons"], ["requirements_invalid"])
        self.assertEqual(data["unknown_type"]["decision"], "unknown")
        source = (ROOT / "src" / "GitHubIntentPolicy.php").read_text().lower()
        for forbidden in ("curl_init(", "file_get_contents(", "shell_exec(", "exec(", "system(",
                          "proc_open(", "capabilitygrant::authorize(", "githubgateway", "github_pat_"):
            self.assertNotIn(forbidden, source)

if __name__=="__main__": unittest.main()

import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"external_api_owner_decision_read_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode or r.stderr.strip(): raise AssertionError(r.stderr.strip() or f"scenario {name} failed")
    return json.loads(r.stdout)

class ExternalApiOwnerDecisionReadTests(unittest.TestCase):
    def test_only_authorized_owner_decision_get_returns_detail(self):
        d=scenario("authorized")
        self.assertEqual((d["data"]["decision_id"],d["data"]["state"],d["meta"]["freshness"]["state"]),("decision-alpha","pending","current"))

    def test_requested_decision_must_match_server_detail(self):
        self.assertTrue(all(scenario("mismatch").values()))

    def test_public_envelope_matches_openapi_contract(self):
        d=scenario("contract"); valid=d["valid"]
        self.assertEqual(set(valid),{"meta","data"})
        self.assertEqual(set(valid["data"]),{"decision_id","category","title","question","options","state","deadline_at"})
        self.assertEqual(valid["data"]["options"],[{"key":"A","label":"Approve staged launch"},{"key":"B","label":"Keep current rollout"}])
        self.assertTrue(all(d[k] for k in ("duplicate_option","invalid_state","invalid_deadline","extra_field")))

    def test_invalid_or_revoked_verified_context_fails_closed(self):
        self.assertTrue(all(scenario("invalid_auth").values()))

    def test_response_is_secret_free_and_unknown_freshness_has_no_provenance(self):
        d=scenario("privacy")
        self.assertEqual(d["unknown"]["meta"]["freshness"],{"state":"unknown","observed_at":None,"source_ref":None})
        serialized=json.dumps(d["unknown"],sort_keys=True).lower()
        for key in ("identity_id","authority_level","policy_ref","grant_id","session_ref","device_ref","step_up_ref","access_token","refresh_token","authorization"):
            self.assertNotIn(key,serialized)
        self.assertEqual(d["ordinary_copy"]["data"]["question"],"Review tokenization footprint with the Secretary on 2026-09-29 10:00")
        self.assertTrue(all(d[k] for k in ("email_pii","phone_pii","secret","dsn","invalid_utf8")))

    def test_contract_is_deterministic_and_external_io_free(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["detail"]); self.assertEqual(d["first"],d["second"])
        source=d["source"].lower()
        for key in ("new pdo","mysqli_connect(","curl_","http://","https://","factoryrunner","dispatchworkflow","enqueue(","scheduler","file_put_contents","fopen(","redis","memcached"):
            self.assertNotIn(key,source)

if __name__=="__main__": unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_authenticated_read_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    if run.stderr.strip():
        raise AssertionError(f"scenario {name} wrote to stderr: {run.stderr.strip()}")
    return json.loads(run.stdout)

class ExternalApiAuthenticatedReadTests(unittest.TestCase):
    def test_cockpit_requires_authorized_get(self):
        d=scenario("cockpit")
        self.assertEqual(d["data"]["ventures"][0]["venture_ref"],"controlbot:venture/alpha")
        self.assertEqual(d["meta"]["freshness"]["state"],"current")

    def test_owner_inbox_requires_authorized_get(self):
        d=scenario("inbox")
        self.assertEqual(d["data"]["entries"][0]["kind"],"DECISION")
        self.assertEqual(d["data"]["entries"][0]["decision_id"],"decision-alpha")

    def test_invalid_or_mismatched_verified_context_fails_closed(self):
        d=scenario("invalid")
        self.assertTrue(d["wrong_scope"])
        self.assertTrue(d["revoked"])
        self.assertTrue(d["wrong_capability"])

    def test_public_shapes_delegate_to_existing_projection(self):
        d=scenario("shape")
        self.assertEqual(set(d["cockpit"]),{"meta","data"})
        self.assertEqual(set(d["cockpit"]["data"]),{"ventures"})
        self.assertEqual(set(d["inbox"]),{"meta","data"})
        self.assertEqual(set(d["inbox"]["data"]),{"entries"})
        self.assertEqual(d["cockpit"]["meta"]["freshness"],{
            "state":"unknown","observed_at":None,"source_ref":None
        })

    def test_response_exposes_no_security_context(self):
        d=scenario("shape")
        serialized=json.dumps(d,sort_keys=True).lower()
        for forbidden in (
            "identity_id","authority_level","policy_ref","grant_id","session_ref",
            "device_ref","step_up_ref","access_token","refresh_token","authorization",
        ):
            self.assertNotIn(forbidden,serialized)

    def test_contract_is_deterministic_and_external_io_free(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["cockpit","ownerInbox"])
        self.assertEqual(d["first"],d["second"])
        source=d["source"].lower()
        for forbidden in (
            "new pdo","mysqli_connect(","curl_","http://","https://","factoryrunner",
            "dispatchworkflow","enqueue(","scheduler","file_put_contents","fopen(","redis","memcached",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

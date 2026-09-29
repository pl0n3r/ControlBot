import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_owner_decision_read_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    if run.stderr.strip():
        raise AssertionError(f"scenario {name} wrote to stderr: {run.stderr.strip()}")
    return json.loads(run.stdout)


class ExternalApiOwnerDecisionReadTests(unittest.TestCase):
    def test_only_authorized_owner_decision_get_returns_detail(self):
        d=scenario("authorized")
        self.assertEqual(d["data"]["decision_id"],"decision-alpha")
        self.assertEqual(d["data"]["state"],"pending")
        self.assertEqual(d["meta"]["freshness"]["state"],"current")

    def test_requested_decision_must_match_server_detail(self):
        d=scenario("mismatch")
        self.assertTrue(d["requested"])
        self.assertTrue(d["server"])

    def test_public_envelope_matches_openapi_contract(self):
        d=scenario("contract")
        valid=d["valid"]
        self.assertEqual(set(valid),{"meta","data"})
        self.assertEqual(set(valid["data"]),{
            "decision_id","category","title","question","options","state","deadline_at"
        })
        self.assertEqual(valid["data"]["options"],[
            {"key":"A","label":"Approve staged launch"},
            {"key":"B","label":"Keep current rollout"},
        ])
        self.assertTrue(d["duplicate_option"])
        self.assertTrue(d["invalid_state"])
        self.assertTrue(d["invalid_deadline"])
        self.assertTrue(d["extra_field"])

    def test_invalid_or_revoked_verified_context_fails_closed(self):
        d=scenario("invalid_auth")
        self.assertTrue(d["wrong_scope"])
        self.assertTrue(d["revoked"])
        self.assertTrue(d["wrong_capability"])

    def test_response_is_secret_free_and_unknown_freshness_has_no_provenance(self):
        d=scenario("privacy")
        freshness=d["unknown"]["meta"]["freshness"]
        self.assertEqual(freshness,{"state":"unknown","observed_at":None,"source_ref":None})
        serialized=json.dumps(d["unknown"],sort_keys=True).lower()
        for forbidden in (
            "identity_id","authority_level","policy_ref","grant_id","session_ref",
            "device_ref","step_up_ref","access_token","refresh_token","authorization",
        ):
            self.assertNotIn(forbidden,serialized)
        self.assertEqual(
            d["ordinary_copy"]["data"]["question"],
            "Review tokenization footprint with the Secretary on 2026-09-29 10:00",
        )
        self.assertTrue(d["email_pii"])
        self.assertTrue(d["phone_pii"])
        self.assertTrue(d["secret"])

    def test_contract_is_deterministic_and_external_io_free(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["detail"])
        self.assertEqual(d["first"],d["second"])
        source=d["source"].lower()
        for forbidden in (
            "new pdo","mysqli_connect(","curl_","http://","https://","factoryrunner",
            "dispatchworkflow","enqueue(","scheduler","file_put_contents","fopen(","redis","memcached",
        ):
            self.assertNotIn(forbidden,source)


if __name__=="__main__":
    unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    """Run one deterministic PHP scenario and decode its JSON result."""
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_session_source_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)

class ExternalApiSessionSourceTests(unittest.TestCase):
    def test_request_session_records_come_only_from_server_side_source(self):
        """The verified context resolves exactly once through the server-side source."""
        d=scenario("source")
        self.assertEqual(d["calls"],1)
        self.assertEqual(d["summary"]["source_ref"],"controlbot:session-source/primary")
        self.assertEqual(d["summary"]["device_ref"],d["device"]["device_ref"])
        self.assertEqual(d["summary"]["session_ref"],d["session"]["session_ref"])
        self.assertEqual(d["summary"]["step_up_ref"],d["step"]["step_up_ref"])

    def test_verified_context_cannot_be_minted_from_raw_client_arrays_or_serialized(self):
        """Only fromSource is a construction path and the resulting object is not serializable."""
        d=scenario("mint")
        self.assertTrue(d["constructor_private"])
        self.assertTrue(d["serialize_rejected"])
        self.assertEqual(
            d["public_methods"],
            ["__serialize","__unserialize","device","fromSource","safeSummary","session","stepUp"],
        )

    def test_mismatch_revocation_expiry_stale_and_unknown_fail_closed(self):
        """All stale, unknown, mismatched, revoked, expired or future states fail closed."""
        d=scenario("fail_closed")
        self.assertTrue(all(d.values()))

    def test_source_and_context_expose_no_tokens_cookies_otp_secrets_credentials_or_keys(self):
        """Exact-field contracts reject injected secret-bearing fields and summaries stay clean."""
        d=scenario("secrets")
        self.assertTrue(all(d["bad"].values()))
        payload=json.dumps(d["summary"],sort_keys=True).lower()
        for forbidden in ("access_token","cookie","otp","secret","credential","private_key","public_key"):
            self.assertNotIn(forbidden,payload)

    def test_verified_session_context_does_not_make_authorization_decisions(self):
        """The authentication context never emits authorization decisions or authority fields."""
        payload=json.dumps(scenario("boundary"),sort_keys=True).lower()
        for forbidden in ("allow","deny","decision","capability","authority_level","policy_ref","authorized"):
            self.assertNotIn(forbidden,payload)

    def test_contract_has_no_persistence_provider_oauth_jwt_or_request_adapter(self):
        """The source boundary remains a pure contract with no runtime integration or persistence."""
        d=scenario("pure")
        self.assertEqual(d["source_methods"],["resolve"])
        source=(d["source_code"]+"\n"+d["context_code"]).lower()
        for forbidden in (
            "pdo(","mysqli","redis","curl_","provider sdk","oauth","jwt",
            "keychain","secure enclave","httprequest","factoryrunner","workitem",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

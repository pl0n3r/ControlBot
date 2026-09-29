import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_session_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)

class ExternalApiSessionTests(unittest.TestCase):
    def test_device_and_session_are_bound_to_identity_scope_and_device(self):
        d=scenario("binding")
        self.assertEqual(d["device"]["identity_id"],"owner-human")
        self.assertEqual(d["session"]["scope"],"venture:alpha")
        self.assertEqual(d["session"]["device_ref"],d["device"]["device_ref"])
        self.assertTrue(d["wrong_identity"] and d["wrong_scope"] and d["wrong_device"])

    def test_session_lifetime_expiry_and_revocation_fail_closed(self):
        d=scenario("lifetime")
        self.assertEqual(d["valid"]["state"],"active")
        for key in ("too_long","expired","revoked_session","revoked_device"):
            self.assertTrue(d[key],key)

    def test_step_up_is_short_lived_and_bound_to_active_session_device_identity(self):
        d=scenario("stepup")
        self.assertEqual(d["valid"]["method"],"passkey")
        self.assertLessEqual(d["valid"]["expires_at"]-d["valid"]["verified_at"],300)
        for key in ("too_long","expired","wrong_session","wrong_device","wrong_identity","revoked_device"):
            self.assertTrue(d[key],key)

    def test_safe_inventory_rejects_tokens_cookies_otp_secrets_credentials_and_keys(self):
        d=scenario("inventory")
        inv=d["inventory"]
        self.assertEqual(inv["identity_id"],"owner-human")
        self.assertEqual(len(inv["devices"]),2)
        self.assertEqual(len(inv["sessions"]),2)
        self.assertTrue(all(d["bad"].values()))
        payload=json.dumps(inv,sort_keys=True).lower()
        for forbidden in ("access_token","refresh_token","cookie","otp","secret","credential","private_key","public_key"):
            self.assertNotIn(forbidden,payload)

    def test_session_contract_does_not_make_authorization_decisions(self):
        payload=json.dumps(scenario("boundary"),sort_keys=True).lower()
        for forbidden in ("allow","capability","authority_level","policy_ref","authorized"):
            self.assertNotIn(forbidden,payload)

    def test_contract_has_no_persistence_oauth_jwt_provider_push_or_mobile_secret_storage(self):
        self.assertEqual(
            scenario("pure")["methods"],
            ["device","safeInventory","session","stepUp"],
        )
        source=(ROOT/"src"/"ExternalApiSession.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo(","mysqli","curl_","oauth","jwt","provider","push",
            "keychain","secure enclave","factoryrunner","dispatch",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

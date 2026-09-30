import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_read_projection_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    if run.stderr.strip():
        raise AssertionError(f"scenario {name} wrote to stderr: {run.stderr.strip()}")
    return json.loads(run.stdout)

class ExternalApiReadProjectionTests(unittest.TestCase):
    def test_cockpit_projection_matches_public_contract(self):
        d=scenario("cockpit")
        self.assertEqual(d["meta"]["version"],1)
        self.assertEqual(d["meta"]["request_id"],"a"*32)
        self.assertEqual(d["meta"]["correlation_id"],"b"*32)
        self.assertEqual(set(d["data"]),{"ventures"})
        self.assertEqual(set(d["data"]["ventures"][0]),{
            "venture_ref","business_state","technical_state","pending_decisions","critical_events"
        })

    def test_owner_inbox_projection_matches_public_contract(self):
        d=scenario("inbox")
        self.assertEqual(set(d["data"]),{"entries"})
        self.assertEqual(d["data"]["entries"][0]["kind"],"DECISION")
        self.assertEqual(d["data"]["entries"][0]["title"],"Revisión de bearer token")
        self.assertEqual(d["data"]["entries"][1]["kind"],"WATCH")
        self.assertIsNone(d["data"]["entries"][1]["venture_ref"])
        self.assertIsNone(d["data"]["entries"][1]["decision_id"])

    def test_freshness_never_upgrades_stale_or_unknown(self):
        d=scenario("freshness")
        self.assertEqual(d["current"]["meta"]["freshness"]["state"],"current")
        self.assertEqual(d["stale"]["meta"]["freshness"]["state"],"stale")
        self.assertEqual(d["unknown"]["meta"]["freshness"]["state"],"unknown")
        self.assertIsNone(d["unknown"]["meta"]["freshness"]["observed_at"])
        self.assertIsNone(d["unknown"]["meta"]["freshness"]["source_ref"])
        self.assertTrue(d["unknown_with_source"])

    def test_invalid_extra_or_sensitive_fields_fail_closed(self):
        d=scenario("invalid")
        self.assertTrue(all(d.values()))

    def test_projection_is_deterministic_and_external_io_free(self):
        first_cockpit=scenario("cockpit")
        second_cockpit=scenario("cockpit")
        self.assertEqual(first_cockpit,second_cockpit)
        pure=scenario("pure")
        self.assertEqual(pure["methods"],["cockpit","ownerInbox"])
        source=pure["source"].lower()
        for forbidden in (
            "new pdo","mysqli_connect(","curl_","http://","https://","factoryrunner",
            "file_put_contents","fopen(","redis","memcached","apns","firebase",
        ):
            self.assertNotIn(forbidden,source)

    def test_public_projection_does_not_leak_internal_fields(self):
        d=scenario("no_leak")
        self.assertTrue(d["blocked_internal"])
        self.assertEqual(d["inbox"]["data"]["entries"][0]["title"],"Revisión de bearer token")
        self.assertIn("password",d["inbox"]["data"]["entries"][0]["summary"].lower())

        keys=set()
        def collect_keys(value):
            if isinstance(value,dict):
                for key,item in value.items():
                    keys.add(key.lower())
                    collect_keys(item)
            elif isinstance(value,list):
                for item in value:
                    collect_keys(item)

        collect_keys(d["cockpit"])
        collect_keys(d["inbox"])
        for forbidden in (
            "internal_owner_email","password","api_key","provider_credential",
            "device_token","authorization","user_agent","ip_address",
        ):
            self.assertNotIn(forbidden,keys)

if __name__=="__main__":
    unittest.main()

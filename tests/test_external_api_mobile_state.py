import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_mobile_state_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)

class ExternalApiMobileStateTests(unittest.TestCase):
    def test_snapshot_state_is_derived_from_time_and_connectivity(self):
        d=scenario("states")
        self.assertEqual(d["fresh"]["state"],"fresh")
        self.assertTrue(d["fresh"]["current"])
        self.assertEqual(d["stale"]["state"],"stale")
        self.assertEqual(d["offline"]["state"],"offline")
        self.assertEqual(d["unknown"]["state"],"unknown")
        self.assertIsNone(d["unknown"]["age_seconds"])
        self.assertTrue(d["partial"])

    def test_reads_preserve_source_age_and_explicit_stale_offline_state(self):
        d=scenario("reads")
        self.assertEqual(d["stale"]["source_ref"],"controlbot:cockpit/group-health")
        self.assertEqual(d["stale"]["observed_at"],1000)
        self.assertEqual(d["stale"]["age_seconds"],401)
        self.assertFalse(d["stale"]["current"])
        self.assertEqual(d["offline"]["state"],"offline")
        self.assertEqual(d["offline"]["age_seconds"],200)
        self.assertFalse(d["offline"]["current"])

    def test_sensitive_mutations_require_fresh_state_and_fail_closed(self):
        d=scenario("mutation")
        self.assertEqual(d["fresh"]["freshness_gate"],"pass")
        for key in ("stale","offline_stepup","offline_high","unknown"):
            self.assertEqual(d[key]["freshness_gate"],"fail",key)
        self.assertFalse(d["offline_stepup"]["queueable"])
        self.assertFalse(d["offline_high"]["queueable"])

    def test_offline_queueability_is_explicit_idempotent_and_non_executing(self):
        d=scenario("queue")
        self.assertTrue(d["queueable"]["queueable"])
        self.assertEqual(d["queueable"]["snapshot_state"],"offline")
        self.assertFalse(d["not_listed"]["queueable"])
        self.assertFalse(d["not_idempotent"]["queueable"])
        self.assertFalse(d["fresh"]["queueable"])
        payload=json.dumps(d).lower()
        for forbidden in ("queued_at","enqueued","executed","dispatch_id"):
            self.assertNotIn(forbidden,payload)

    def test_sensitivity_controls_persistent_cache_without_secrets(self):
        d=scenario("cache")
        self.assertTrue(d["public"]["persistent_cache_allowed"])
        self.assertFalse(d["conf_plain"]["persistent_cache_allowed"])
        self.assertTrue(d["conf_encrypted"]["persistent_cache_allowed"])
        self.assertFalse(d["restricted"]["persistent_cache_allowed"])
        self.assertTrue(d["sensitive_ref"] and d["extra_sensitive"])

    def test_contract_has_no_storage_sync_push_provider_factory_or_execution(self):
        self.assertEqual(
            scenario("pure")["methods"],
            ["cachePolicy","mutationPolicy","snapshot"],
        )
        source=(ROOT/"src"/"ExternalApiMobileState.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "pdo(","mysqli","sqlite","coredata","swiftdata","keychain",
            "background sync","pushnotification","apns","factoryrunner","curl_","http://","https://",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    """Run one PHP scenario and return its decoded JSON output."""
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_push_envelope_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    return json.loads(run.stdout)

class ExternalApiPushEnvelopeTests(unittest.TestCase):
    def test_push_supports_only_closed_types_and_authenticated_targets(self):
        """Verify the type and target allowlists fail closed."""
        d=scenario("closed")
        self.assertEqual(len(d["types"]),6)
        self.assertEqual(len(d["targets"]),3)
        self.assertTrue(d["bad_type"] and d["external_target"])

    def test_payload_rejects_free_text_pii_secrets_device_tokens_provider_credentials_and_external_urls(self):
        """Verify the envelope rejects extra/sensitive fields and malformed refs."""
        d=scenario("minimal")
        self.assertTrue(all(d["extra"].values()))
        self.assertTrue(d["sensitive_target"])
        self.assertEqual(d["legit_dsn_target"],"controlbot:incident/dsn-outage")
        self.assertTrue(d["token_hyphen_target"])
        self.assertTrue(d["token_dot_target"])
        for key in (
            "bad_version","bad_notification_ref","bad_correlation_id","bad_venture_ref",
            "bad_occurred_type","bad_occurred_zero","bad_copy_key",
        ):
            self.assertTrue(d[key], key)
        payload=json.dumps(d["payload"],sort_keys=True).lower()
        for forbidden in ("title","body","http://","https://","device_token","provider_credential","api_secret"):
            self.assertNotIn(forbidden,payload)

    def test_deep_link_contains_only_route_and_target_for_authenticated_api_resolution(self):
        """Verify deep links carry no authority or sensitive detail."""
        d=scenario("deep_link")
        self.assertEqual(d["inbox"]["route"],"owner_inbox_detail")
        self.assertEqual(d["decision"]["route"],"owner_decision_detail")
        self.assertEqual(d["incident"]["route"],"incident_detail")
        for row in d.values():
            self.assertEqual(set(row),{"route","target_ref","requires_authenticated_api_fetch"})
            self.assertTrue(row["requires_authenticated_api_fetch"])

    def test_freshness_and_provenance_fail_closed_without_invented_unknown_evidence(self):
        """Verify stale/unknown provenance is explicit and cannot be fabricated."""
        d=scenario("freshness")
        self.assertEqual(d["current"]["freshness"],"current")
        self.assertEqual(d["stale"]["freshness"],"stale")
        self.assertEqual(d["unknown"]["freshness"],"unknown")
        self.assertIsNone(d["unknown"]["source_ref"])
        self.assertIsNone(d["unknown"]["occurred_at"])
        self.assertTrue(d["unknown_source"] and d["unknown_time"])
        self.assertTrue(d["current_missing_source"] and d["stale_missing_time"])

    def test_collapse_key_is_deterministic_and_delivery_policy_inputs_are_explicit(self):
        """Verify collapse identity and all delivery policy inputs are explicit."""
        d=scenario("delivery")
        self.assertEqual(d["a"]["collapse_key"],d["b"]["collapse_key"])
        self.assertTrue(d["a"]["eligible"])
        self.assertFalse(d["preference_off"]["eligible"])
        self.assertFalse(d["policy_off"]["eligible"])
        self.assertFalse(d["severity_off"]["eligible"])
        self.assertEqual(d["preference_off"]["preference_enabled"],False)
        self.assertEqual(d["policy_off"]["policy_allows_type"],False)
        self.assertEqual(d["severity_off"]["severity_allows_delivery"],False)
        self.assertNotEqual(d["a"]["collapse_key"],d["different_type"]["collapse_key"])
        self.assertNotEqual(d["a"]["collapse_key"],d["different_target"]["collapse_key"])

    def test_contract_has_no_push_provider_storage_queue_retry_scheduler_or_send(self):
        """Smoke-check the contract source for direct provider/execution primitives."""
        d=scenario("pure")
        self.assertEqual(d["methods"],["deepLink","deliveryPolicy","envelope"])
        source=d["source"].lower()
        for forbidden in (
            "curl_init(","http://","https://","new pdo","mysqli_connect(","enqueue(","retry(","scheduler",
            "firebase","apns","device_token","keychain","factoryrunner",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

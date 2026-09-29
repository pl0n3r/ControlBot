import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_push_notification_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    return json.loads(run.stdout)

class ExternalApiPushNotificationTests(unittest.TestCase):
    def test_payload_is_minimal_opaque_and_contains_no_sensitive_business_data(self):
        d=scenario("minimal")
        self.assertTrue(all(d["extra"].values()))
        self.assertTrue(d["sensitive_entity"] and d["external_entity"])
        self.assertTrue(d["bad_notification"] and d["bad_dedupe"])
        payload=d["payload"]
        self.assertTrue(payload["requires_authenticated_detail"])
        self.assertEqual(payload["localization_key"],"push.critical_incident")
        serialized=json.dumps(payload,sort_keys=True).lower()
        for forbidden in ("title","body","amount","email","device_token","provider_credential","http://","https://"):
            self.assertNotIn(forbidden,serialized)

    def test_detail_operation_is_existing_public_get_and_requires_authenticated_fetch(self):
        d=scenario("detail")
        self.assertEqual(d["operation"],"owner_inbox.read")
        self.assertTrue(d["is_public_get"])
        self.assertTrue(d["requires_authenticated_detail"])
        self.assertTrue(d["post_operation"] and d["unknown_operation"])

    def test_preferences_suppress_without_granting_authority_or_access(self):
        d=scenario("policy")
        self.assertEqual(d["deliver"]["decision"],"deliver")
        self.assertEqual(d["category_off"]["decision"],"suppress")
        self.assertIn("preference_disabled",d["category_off"]["reasons"])
        self.assertEqual(d["default_quiet"]["decision"],"suppress")
        for row in d.values():
            serialized=json.dumps(row,sort_keys=True).lower()
            self.assertNotIn("authority",serialized)
            self.assertNotIn("capability",serialized)
            self.assertNotIn("auth_scope",serialized)

    def test_dedupe_expiry_and_rate_limit_are_deterministic_and_non_executing(self):
        d=scenario("policy")
        self.assertEqual(d["expired"]["reasons"],["expired"])
        self.assertEqual(d["duplicate"]["reasons"],["duplicate"])
        self.assertEqual(d["rate_limited"]["reasons"],["rate_limited"])
        self.assertEqual(d["dedupe_window_elapsed"]["decision"],"deliver")
        self.assertEqual(d["rate_window_elapsed"]["decision"],"deliver")

    def test_payload_rejects_device_tokens_credentials_external_urls_and_extra_fields(self):
        d=scenario("minimal")
        self.assertTrue(all(d["extra"].values()))
        c=scenario("closed")
        self.assertTrue(all(c.values()))

    def test_contract_has_no_push_provider_network_storage_scheduler_factory_or_execution(self):
        d=scenario("pure")
        self.assertEqual(d["methods"],["deliveryPolicy","payload"])
        source=d["source"].lower()
        for forbidden in (
            "curl_init(","http://","https://","new pdo","mysqli_connect(","enqueue(","scheduler",
            "firebase","apns","device_token","factoryrunner","workitem","decisionrights",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

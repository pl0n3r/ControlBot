import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    p=subprocess.run(
        ["php",str(ROOT/"tests"/"observability_event_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(p.stdout)

class ObservabilityEventTests(unittest.TestCase):
    def test_event_v1_normalizes_supported_sources_with_closed_schema(self):
        data=scenario("sources")
        self.assertEqual(set(data),{"health","ci","deploy","agent"})
        expected={"version","source","project_id","environment_id","repository_id","severity","type",
                  "payload_allowlisted","occurred_at","received_at","correlation_keys","fingerprint","freshness"}
        for source,row in data.items():
            self.assertEqual(row["source"],source)
            self.assertEqual(set(row),expected)

    def test_freshness_is_injected_and_fail_closed(self):
        data=scenario("freshness")
        self.assertEqual((data["fresh"],data["stale"],data["unknown"]),("fresh","stale","unknown"))
        self.assertEqual(data["delayed"],"stale")
        self.assertTrue(data["future_rejected"])

    def test_payload_and_correlation_keys_are_bounded_allowlisted_and_secret_free(self):
        data=scenario("security")
        for key in ("secret","email","bearer","nested","extra","wrong_type","secret_key","project_secret"):
            self.assertTrue(data[key],(key,data))
        self.assertEqual(data["keys"],["issue:78","project:controlbot"])
        self.assertEqual(data["numeric"]["correlation_keys"],["run:1234567890"])
        self.assertEqual(data["numeric"]["payload_allowlisted"]["sha"],"abcdef1234567890abcdef1234567890abcdef12")

    def test_fingerprint_is_deterministic_and_materially_bound(self):
        data=scenario("fingerprint")
        self.assertEqual(data["a"],data["retry"])
        self.assertNotEqual(data["a"],data["changed"])

    def test_incident_78_preserves_runner_capacity_and_unknown_application_state(self):
        row=scenario("incident78")
        payload=row["payload_allowlisted"]
        self.assertEqual(row["type"],"runner_capacity")
        self.assertEqual(payload["runner_status"],"unavailable")
        self.assertEqual(payload["application_status"],"unknown")
        self.assertTrue(payload["startup_failure"])
        self.assertIsNone(payload["steps"])

    def test_event_core_has_no_external_io_or_actions(self):
        source=scenario("pure")["source"].lower()
        for token in ("new pdo","mysqli_connect(","curl_","http://","https://","file_put_contents",
                      "fopen(","shell_exec","exec(","proc_open","mail(","factoryrunner"):
            self.assertNotIn(token,source)

if __name__=="__main__":
    unittest.main()

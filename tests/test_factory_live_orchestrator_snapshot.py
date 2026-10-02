import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "src" / "FactoryLiveOrchestratorSnapshot.php"


def scenario(name):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "factory_live_orchestrator_snapshot_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class FactoryLiveOrchestratorSnapshotTests(unittest.TestCase):
    def test_snapshot_derives_dispatcher_fronts_and_owner_latency_from_canonical_factory_live_snapshot(self):
        data = scenario("full")
        self.assertEqual(data["version"], 1)
        self.assertTrue(data["read_only"])
        self.assertEqual(data["central"]["activity_state"], "ACTIVE")
        self.assertEqual(data["central"]["available"], 1)
        self.assertEqual(data["central"]["reserved"], 1)
        self.assertEqual(data["central"]["blocked"], 3)
        self.assertEqual(data["central"]["freshness"], "current")

        fronts = {front["issue_ref"]: front for front in data["fronts"]}
        current = fronts["github:pl0n3r/ControlBot#620"]
        self.assertEqual(current["repository_ref"], "pl0n3r/ControlBot")
        self.assertEqual(current["status"], "reserved")
        self.assertEqual(current["progress_percent"], 40)
        self.assertEqual(current["progress_state"], "EVIDENCED")
        self.assertEqual(current["freshness"], "current")

        review = fronts["github:pl0n3r/Factory#856"]
        self.assertIsNone(review["progress_percent"])
        self.assertEqual(review["progress_state"], "UNKNOWN")

        decision = data["owner_decisions"][0]
        self.assertEqual(decision["issue_ref"], "github:pl0n3r/ControlBot#700")
        self.assertEqual(decision["age_seconds"], 70)
        self.assertEqual(len(data["fingerprint"]), 64)

    def test_unknown_stale_or_mismatched_evidence_never_becomes_healthy_progress(self):
        data = scenario("fail_closed")
        stale = data["stale"]
        self.assertEqual(stale["central"]["activity_state"], "STALE")
        self.assertEqual(stale["central"]["freshness"], "stale")

        front = next(
            item for item in stale["fronts"]
            if item["issue_ref"] == "github:pl0n3r/ControlBot#620"
        )
        self.assertEqual(front["freshness"], "stale")
        self.assertIsNone(front["progress_percent"])
        self.assertEqual(front["progress_state"], "UNKNOWN")

        self.assertEqual(data["missing"]["fronts"], [])
        self.assertTrue(data["mismatch_blocked"])

    def test_projection_is_read_only_bounded_secret_and_pii_free(self):
        data = scenario("safety")
        safe = data["safe"]
        serialized = json.dumps(safe, sort_keys=True)
        self.assertNotIn("ignored_note", serialized)
        self.assertNotIn("presentation must not copy arbitrary payloads", serialized)
        self.assertTrue(data["bounded"])
        self.assertTrue(data["secret_blocked"])

        source = SOURCE.read_text(encoding="utf-8")
        for forbidden in (
            "ApiClient",
            "ApiTransport",
            "curl_",
            "file_get_contents",
            "fsockopen",
            "PDO",
            "EntityManager",
            "'POST'",
            "'PATCH'",
            "'DELETE'",
            "/tomar",
            "/decidir",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/factory_orchestrator_snapshot_refresh_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class FactoryOrchestratorSnapshotRefreshTests(unittest.TestCase):
    def test_refresh_writes_valid_snapshot_atomically_with_size_and_freshness_bounds(self) -> None:
        data = scenario("success")
        self.assertTrue(data["written"])
        self.assertGreater(data["bytes"], 1)
        self.assertLessEqual(data["bytes"], 2_000_000)
        self.assertEqual(220, data["observed_at"])
        self.assertTrue(data["fingerprint_matches"])
        self.assertEqual("0640", data["mode"])
        self.assertEqual("reserved", data["live_status"])
        self.assertEqual("reserved", data["boundary_status"])
        self.assertEqual("UNKNOWN", data["expired_state"])
        self.assertEqual([], data["expired_fronts"])
        self.assertEqual(0, data["temporary_count"])

        source = (ROOT / "src/FactoryOrchestratorSnapshotRefresh.php").read_text()
        self.assertIn("tempnam($directory", source)
        self.assertIn("rename($temporary, $snapshotPath)", source)
        self.assertNotIn("api.github.com", source)
        self.assertNotIn("curl_", source)

    def test_failed_collector_writes_stale_preserving_last_success(self) -> None:
        result = scenario("stale_fallback")
        self.assertEqual(result["status"], "stale")
        self.assertEqual(result["reason"], "evidence_invalid")
        self.assertEqual(result["last_success_at"], 220)
        self.assertEqual(result["detected_at"], 1000)
        self.assertEqual(result["old_view_state"], "UNKNOWN")
        self.assertEqual(result["old_view_fronts"], 0)
        self.assertTrue(result["visible_stale"])
        self.assertTrue(result["visible_last_success"])
        self.assertTrue(result["no_old_work"])
        self.assertTrue(result["bad_write_preserved"])
        self.assertEqual(result["bad_write_error"], "Snapshot refresh failed.")
        self.assertEqual(result["mode"], "0640")

    def test_failed_collection_preserves_fail_closed_state_without_partial_snapshot_or_secret_echo(self) -> None:
        data = scenario("fail_closed")
        self.assertTrue(data["collection_preserved"])
        self.assertTrue(data["sensitive_preserved"])
        self.assertTrue(data["size_preserved"])
        self.assertTrue(data["invalid_type_preserved"])
        self.assertEqual(
            [
                "Snapshot refresh failed.",
                "Snapshot refresh failed.",
                "Snapshot refresh failed.",
                "Snapshot refresh failed.",
            ],
            data["messages"],
        )
        self.assertFalse(data["secret_echo"])
        self.assertEqual(0, data["temporary_count"])
        self.assertEqual("reserved", data["live_status"])


if __name__ == "__main__":
    unittest.main()

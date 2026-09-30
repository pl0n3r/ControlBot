import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests/factory_live_snapshot_scenarios.php"
SOURCE = ROOT / "src/FactoryLiveSnapshot.php"


def scenario(name: str):
    result = subprocess.run(
        ["php", str(SCENARIOS), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class FactoryLiveSnapshotTests(unittest.TestCase):
    def test_snapshot_contains_all_factory_live_sections(self):
        data = scenario("full")
        self.assertEqual(data["version"], 1)
        self.assertEqual(data["observed_at"], 200)
        self.assertEqual(
            list(data["sections"]),
            [
                "batches",
                "owner_decisions",
                "releases",
                "blockers",
                "production",
                "quality",
                "work",
                "learning",
            ],
        )
        for section in data["sections"].values():
            self.assertGreaterEqual(len(section), 1)
        self.assertEqual(data["tool_usage"]["authority"], "tool_usage")
        self.assertEqual(len(data["fingerprint"]), 64)

    def test_every_signal_preserves_source_observed_at_and_freshness(self):
        data = scenario("full")
        for signals in data["sections"].values():
            for signal in signals:
                self.assertIsInstance(signal["source_ref"], str)
                self.assertIsInstance(signal["observed_at"], int)
                self.assertEqual(signal["freshness"], "current")
                self.assertEqual(
                    signal["age_seconds"],
                    data["observed_at"] - signal["observed_at"],
                )
        decision = data["sections"]["owner_decisions"][0]
        self.assertEqual(
            decision["data"]["issue_ref"],
            "https://github.com/pl0n3r/ControlBot/issues/100",
        )

    def test_missing_stale_and_ambiguous_evidence_fails_closed(self):
        data = scenario("fail_closed")
        missing = data["missing_quality"]
        self.assertEqual(missing["state"], "unknown")
        self.assertEqual(missing["freshness"], "unknown")
        self.assertIsNone(missing["source_ref"])
        self.assertIsNone(missing["observed_at"])
        self.assertEqual(
            data["blocked"],
            {
                "stale_healthy": True,
                "duplicate": True,
                "wrong_authority": True,
            },
        )

    def test_snapshot_is_read_only_bounded_deterministic_and_secret_free(self):
        data = scenario("safety")
        self.assertTrue(data["secret_blocked"])
        self.assertTrue(data["bounded_blocked"])
        self.assertTrue(data["deterministic"])

        source = SOURCE.read_text(encoding="utf-8")
        for forbidden in (
            "ApiClient",
            "ApiTransport",
            "curl_",
            "file_get_contents",
            "fsockopen",
            "EntityManager",
            "PDO",
        ):
            self.assertNotIn(forbidden, source)
        self.assertNotIn("'POST'", source)
        self.assertNotIn("'PATCH'", source)
        self.assertNotIn("'DELETE'", source)

    def test_existing_authorities_are_composed_without_parallel_state(self):
        data = scenario("simulated")
        self.assertEqual(
            data["authorities"],
            {
                "batches": "factory_plan",
                "blockers": "github_project_snapshot",
                "learning": "incident_lesson",
                "owner_decisions": "owner_inbox",
                "production": "observability_project_status",
                "quality": "quality_health",
                "releases": "github_project_snapshot",
                "work": "github_project_snapshot",
            },
        )

    def test_simulated_github_sonar_and_learning_cover_every_section(self):
        data = scenario("simulated")
        self.assertEqual(
            data["source_kinds"],
            [
                "factory",
                "github",
                "learning",
                "observability",
                "sonar",
                "tool_usage",
            ],
        )
        self.assertEqual(len(data["authorities"]), 8)


if __name__ == "__main__":
    unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/factory_orchestrator_snapshot_source_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class FactoryOrchestratorSnapshotSourceTests(unittest.TestCase):
    def test_source_composes_canonical_factory_snapshot_from_injected_read_only_github_evidence(self) -> None:
        data = scenario("compose")
        self.assertTrue(data["read_only"])
        self.assertEqual("ACTIVE", data["central"]["activity_state"])
        self.assertEqual(
            (2, 1, 5),
            (
                data["central"]["available"],
                data["central"]["reserved"],
                data["central"]["blocked"],
            ),
        )
        self.assertEqual("github:pl0n3r/ControlBot#659", data["fronts"][0]["issue_ref"])
        self.assertEqual("reserved", data["fronts"][0]["status"])
        self.assertEqual("EVIDENCED", data["fronts"][0]["progress_state"])
        self.assertEqual(25, data["fronts"][0]["progress_percent"])

        source = (ROOT / "src/FactoryOrchestratorSnapshotSource.php").read_text().lower()
        for forbidden in (
            "apiclient",
            "apitransport",
            "api.github.com",
            "curl_",
            "file_get_contents",
            "fsockopen",
            "entitymanager",
            "pdo",
            "'post'",
            "'patch'",
            "'put'",
            "'delete'",
        ):
            self.assertNotIn(forbidden, source)

    def test_missing_stale_mismatched_or_sensitive_evidence_fails_closed_without_invented_state(self) -> None:
        data = scenario("fail_closed")
        self.assertEqual("UNKNOWN", data["missing"]["activity_state"])
        self.assertEqual([], data["missing"]["fronts"])
        self.assertEqual("STALE", data["stale"])
        self.assertTrue(data["mismatch_blocked"])
        self.assertTrue(data["sensitive_blocked"])
        self.assertTrue(data["authority_blocked"])


if __name__ == "__main__":
    unittest.main()

import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "observability_project_status_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


def project(data, project_id):
    return next(row for row in data["projects"] if row["project_id"] == project_id)


class ObservabilityProjectStatusTests(unittest.TestCase):
    def test_current_projects_expose_all_sources_with_explicit_freshness(self):
        data = scenario("current")
        self.assertEqual(
            [row["project_id"] for row in data["projects"]],
            ["project-brvtal", "project-condor", "project-controlbot", "project-grindflow"],
        )
        for row in data["projects"]:
            self.assertEqual(set(row["sources"]), {"health", "ci", "deploy", "agent"})
            self.assertTrue(all(slot["freshness"] == "fresh" for slot in row["sources"].values()))

    def test_missing_and_stale_sources_never_become_healthy_or_fresh(self):
        data = scenario("missing-stale")
        controlbot = project(data, "project-controlbot")["sources"]
        condor = project(data, "project-condor")["sources"]
        self.assertEqual(controlbot["health"]["freshness"], "stale")
        self.assertEqual(controlbot["ci"]["freshness"], "unknown")
        self.assertEqual(controlbot["ci"]["severity"], "unknown")
        self.assertEqual(condor["health"]["freshness"], "unknown")
        self.assertNotEqual(condor["health"]["freshness"], "fresh")
        self.assertEqual(condor["agent"]["freshness"], "fresh")

    def test_latest_event_selection_is_deterministic_and_ambiguous_ties_fail_closed(self):
        latest = scenario("latest")
        health = project(latest, "project-controlbot")["sources"]["health"]
        self.assertEqual(health["observed_at"], 120)
        self.assertEqual(health["severity"], "critical")
        self.assertTrue(scenario("ambiguous"))

    def test_catalog_duplicates_extra_sensitive_and_invalid_inputs_fail_closed(self):
        data = scenario("invalid")
        self.assertTrue(all(data.values()), data)

    def test_snapshot_and_fingerprint_are_order_independent_and_evidence_is_opaque(self):
        data = scenario("order")
        self.assertEqual(data["a"], data["b"])
        self.assertRegex(data["a"]["fingerprint"], r"^[0-9a-f]{64}$")
        refs = [
            slot["evidence_ref"]
            for row in data["a"]["projects"]
            for slot in row["sources"].values()
            if slot["evidence_ref"] is not None
        ]
        self.assertTrue(refs)
        self.assertTrue(all(re.fullmatch(r"controlbot:observability-event/[0-9a-f]{32}", ref) for ref in refs))

    def test_project_status_has_no_external_io_or_actions(self):
        source = (ROOT / "src" / "ObservabilityProjectStatus.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "file_put_contents", "curl_", "mysqli", "new pdo", "shell_exec",
            "proc_open", "passthru", "externalapipushnotification", "pausecontrol",
            "ownerinbox",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

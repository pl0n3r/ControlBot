"""Executable acceptance contract for Infrastructure Center UI (#190)."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    """Run one deterministic PHP fixture."""
    run = subprocess.run(
        ["php", str(ROOT / "tests/infrastructure_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(run.stdout)


def resources(data):
    """Index projected resources by resource_id."""
    return {row["resource_id"]: row for row in data["resources"]}


class InfrastructureUiTests(unittest.TestCase):
    def test_cockpit_preserves_unknown_freshness_and_source(self):
        rows = resources(scenario("cockpit"))
        self.assertEqual(rows["service-web"]["state"]["effective_state"], "online")

        stale = rows["database-primary"]["state"]
        self.assertEqual(stale["observed_state"], "degraded")
        self.assertEqual(stale["effective_state"], "unknown")
        self.assertEqual(stale["freshness"], "stale")
        self.assertEqual(stale["source_ref"], "controlbot:observation/database-primary")

        absent = rows["env-prod"]["state"]
        self.assertEqual(absent["effective_state"], "unknown")
        self.assertEqual(absent["freshness"], "unknown")
        self.assertIsNone(absent["source_ref"])

    def test_cockpit_supports_bidirectional_impact_drilldown(self):
        data = scenario("cockpit")
        impact = resources(data)["database-primary"]["impact"]
        self.assertEqual(impact["service_ref"], "controlbot:resource/service-web")
        self.assertEqual(impact["venture_ref"], "controlbot:venture/venture-platform")
        self.assertEqual(impact["capability_refs"], ["controlbot:capability/commerce"])

        project = data["drilldown"]["scopes"][
            "controlbot:venture/venture-platform"
        ]["controlbot:project/project-controlbot"]
        self.assertEqual(project["_direct"], ["controlbot:resource/env-prod"])
        self.assertEqual(
            project["controlbot:environment/controlbot-prod"],
            [
                "controlbot:resource/database-primary",
                "controlbot:resource/service-web",
            ],
        )

        invalid = scenario("invalid")
        self.assertTrue(invalid["duplicate_observation"])
        self.assertTrue(invalid["orphan_observation"])
        self.assertTrue(invalid["scalar_observation"])
        self.assertTrue(invalid["cross_scope"])

    def test_cockpit_keeps_operational_signals_separate(self):
        signals = resources(scenario("cockpit"))["database-primary"]["signals"]
        self.assertEqual(signals["backup_freshness"]["state"], "stale")
        self.assertEqual(signals["restore_verification"]["state"], "verified")
        self.assertEqual(signals["cost_attribution"]["state"], "attributed")
        self.assertEqual(signals["release_drift"]["state"], "drift")
        self.assertEqual(
            signals["incident_refs"],
            ["https://github.com/pl0n3r/ControlBot/issues/190"],
        )

    def test_ui_never_exposes_secrets_or_grants_authority(self):
        data = scenario("cockpit")
        self.assertFalse(data["execution"])
        self.assertTrue(all(not row["actions"] for row in data["resources"]))

        serialized = json.dumps(data).lower()
        for forbidden in ("password", "bearer ", "github_pat_", "private key"):
            self.assertNotIn(forbidden, serialized)

        self.assertTrue(scenario("invalid")["caller_action"])


if __name__ == "__main__":
    unittest.main()

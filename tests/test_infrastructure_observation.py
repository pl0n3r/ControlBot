import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "infrastructure_observation_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class InfrastructureObservationTests(unittest.TestCase):
    def test_observations_preserve_source_freshness_and_fail_closed(self):
        data = scenario("freshness")
        self.assertEqual(data["fresh"]["source_ref"], "controlbot:observation/service-web-2000")
        self.assertEqual(data["fresh"]["freshness"], "fresh")
        self.assertEqual(data["fresh_effective"], "online")
        self.assertEqual(data["stale_effective"], "unknown")
        self.assertEqual(data["unknown_effective"], "unknown")
        self.assertEqual(data["absent_effective"], "unknown")

        invalid = scenario("invalid")
        self.assertTrue(invalid["sensitive_source"])
        self.assertTrue(invalid["duplicate_incident"])

    def test_impact_graph_traverses_resource_service_project_and_venture(self):
        data = scenario("impact")
        database = data["database"]
        self.assertEqual(database["service_ref"], "controlbot:resource/service-web")
        self.assertEqual(database["environment_ref"], "controlbot:environment/controlbot-prod")
        self.assertEqual(database["project_ref"], "controlbot:project/project-controlbot")
        self.assertEqual(database["venture_ref"], "controlbot:venture/venture-platform")
        self.assertEqual(database["capability_refs"], ["controlbot:capability/commerce"])

        self.assertEqual(
            data["platform_resources"],
            [
                "controlbot:resource/database-primary",
                "controlbot:resource/env-prod",
                "controlbot:resource/service-web",
            ],
        )
        self.assertEqual(data["other_resources"], [])
        self.assertNotIn(
            "controlbot:capability/other-venture",
            data["graph"]["resource_impact"]["database-primary"]["capability_refs"],
        )
        self.assertTrue(scenario("invalid")["duplicate_binding"])

    def test_backup_and_restore_verification_remain_separate_signals(self):
        data = scenario("recovery-signals")
        self.assertEqual(data["backup_freshness"]["state"], "stale")
        self.assertEqual(data["restore_verification"]["state"], "verified")
        self.assertNotEqual(
            data["backup_freshness"]["source_ref"],
            data["restore_verification"]["source_ref"],
        )

    def test_cost_and_release_drift_require_explicit_evidence(self):
        data = scenario("evidence-signals")
        evidenced = data["evidenced"]
        self.assertEqual(evidenced["cost_attribution"]["state"], "attributed")
        self.assertEqual(
            evidenced["cost_attribution"]["cost_ref"],
            "controlbot:cost/project-controlbot",
        )
        self.assertEqual(evidenced["release_drift"]["state"], "drift")
        self.assertEqual(evidenced["release_drift"]["expected_sha"], "a" * 40)
        self.assertEqual(evidenced["release_drift"]["observed_sha"], "b" * 40)

        unknown = data["unknown"]
        self.assertEqual(unknown["cost_attribution"]["state"], "unknown")
        self.assertEqual(unknown["release_drift"]["state"], "unknown")
        self.assertIsNone(unknown["cost_attribution"]["source_ref"])
        self.assertIsNone(unknown["release_drift"]["source_ref"])

        self.assertTrue(data["cost_missing_evidence_blocked"])
        self.assertTrue(data["release_state_mismatch_blocked"])


if __name__ == "__main__":
    unittest.main()

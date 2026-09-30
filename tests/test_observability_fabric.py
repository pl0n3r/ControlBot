import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(script: str, name: str):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / script), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=60,
    )
    return json.loads(run.stdout)


def project_status(name: str):
    return scenario("observability_project_status_scenarios.php", name)


def incident(name: str):
    return scenario("observability_incident_scenarios.php", name)


def policy(name: str):
    return scenario("observability_policy_router_scenarios.php", name)


def postmortem(name: str):
    return scenario("incident_postmortem_scenarios.php", name)


class ObservabilityFabricTests(unittest.TestCase):
    def test_current_projects_expose_freshness_for_health_ci_deploy_and_agents(self):
        data = project_status("current")
        self.assertEqual(
            [row["project_id"] for row in data["projects"]],
            ["project-brvtal", "project-condor", "project-controlbot", "project-grindflow"],
        )
        for row in data["projects"]:
            self.assertEqual(set(row["sources"]), {"health", "ci", "deploy", "agent"})
            self.assertTrue(all(slot["freshness"] == "fresh" for slot in row["sources"].values()))

    def test_repeated_health_failure_opens_single_deduplicated_incident(self):
        data = incident("dedupe")
        self.assertEqual(len(data["incidents"]), 1)
        self.assertEqual(data["incidents"][0]["occurrence_count"], 2)
        self.assertEqual(len(data["incidents"][0]["event_fingerprints"]), 1)

    def test_post_deploy_failure_correlates_only_with_sufficient_evidence(self):
        data = incident("deploy")
        linked = data["linked"]["incidents"][0]
        unlinked = data["unlinked"]["incidents"][0]
        too_late = data["too_late"]["incidents"][0]
        self.assertEqual(linked["correlation_reason"], "post_deploy_explicit")
        self.assertNotEqual(unlinked["correlation_reason"], "post_deploy_explicit")
        self.assertNotEqual(too_late["correlation_reason"], "post_deploy_explicit")

    def test_recovery_resolves_incident_with_complete_timeline(self):
        data = incident("recovery")
        row = data["resolved"]["incidents"][0]
        self.assertEqual(row["status"], "resolved")
        self.assertIsNotNone(row["resolved_at"])
        self.assertEqual(data["timeline"]["incident_ref"], row["incident_id"])
        self.assertEqual(
            len(data["timeline"]["events"]),
            len(row["timeline_events"]),
        )
        self.assertTrue(any(event["kind"] == "incident.resolved" for event in data["timeline"]["events"]))

    def test_critical_policy_can_freeze_new_deploys_without_destructive_actions(self):
        data = policy("freeze")
        self.assertEqual(data["freeze"]["scope_type"], "project")
        self.assertEqual(data["freeze"]["source"], "policy")
        self.assertTrue(data["write"]["pause_blocked"])
        self.assertFalse(data["write"]["pause_allows"])
        self.assertFalse(data["read"]["pause_blocked"])
        self.assertTrue(data["read"]["pause_allows"])
        self.assertEqual(data["write"]["authorization"], "not_granted")

    def test_missing_source_becomes_stale_or_unknown_not_healthy(self):
        data = project_status("missing-stale")
        rows = {row["project_id"]: row["sources"] for row in data["projects"]}
        self.assertEqual(rows["project-controlbot"]["health"]["freshness"], "stale")
        self.assertEqual(rows["project-controlbot"]["ci"]["freshness"], "unknown")
        self.assertEqual(rows["project-controlbot"]["ci"]["severity"], "unknown")
        self.assertEqual(rows["project-condor"]["health"]["freshness"], "unknown")
        self.assertNotEqual(rows["project-condor"]["health"]["freshness"], "fresh")

    def test_postmortem_can_be_derived_from_incident_events(self):
        recovery = incident("recovery")
        timeline = recovery["timeline"]
        report = postmortem("postmortem")
        self.assertTrue(timeline["events"])
        self.assertTrue(all(event["evidence_ref"].startswith("evidence:") for event in timeline["events"]))
        self.assertIn("duration_seconds", timeline)
        self.assertTrue(report["findings"])
        self.assertEqual(report["version"], 1)
        self.assertTrue(all("evidence_ref" in finding for finding in report["findings"]))

    def test_observability_parent_contract_has_all_exact_targets(self):
        expected = [
            "test_current_projects_expose_freshness_for_health_ci_deploy_and_agents",
            "test_repeated_health_failure_opens_single_deduplicated_incident",
            "test_post_deploy_failure_correlates_only_with_sufficient_evidence",
            "test_recovery_resolves_incident_with_complete_timeline",
            "test_critical_policy_can_freeze_new_deploys_without_destructive_actions",
            "test_missing_source_becomes_stale_or_unknown_not_healthy",
            "test_postmortem_can_be_derived_from_incident_events",
        ]
        self.assertTrue(all(callable(getattr(ObservabilityFabricTests, name, None)) for name in expected))


if __name__ == "__main__":
    unittest.main()

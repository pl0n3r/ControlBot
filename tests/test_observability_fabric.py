import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(script: str, name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / script), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=60,
    )
    return json.loads(result.stdout)


def php_inline(source: str):
    result = subprocess.run(
        ["php", "-r", source],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=60,
    )
    return json.loads(result.stdout)


class ObservabilityFabricTests(unittest.TestCase):
    def test_current_projects_expose_freshness_for_health_ci_deploy_and_agents(self):
        data = scenario("observability_project_status_scenarios.php", "current")
        self.assertEqual(
            [row["project_id"] for row in data["projects"]],
            ["project-brvtal", "project-condor", "project-controlbot", "project-grindflow"],
        )
        for row in data["projects"]:
            self.assertEqual(set(row["sources"]), {"health", "ci", "deploy", "agent"})
            self.assertTrue(
                all(slot["freshness"] in {"fresh", "stale", "unknown"} for slot in row["sources"].values())
            )

    def test_repeated_health_failure_opens_single_deduplicated_incident(self):
        data = scenario("observability_incident_scenarios.php", "dedupe")
        self.assertEqual(len(data["incidents"]), 1)
        incident = data["incidents"][0]
        self.assertEqual(incident["status"], "open")
        self.assertEqual(incident["occurrence_count"], 2)
        self.assertEqual(len(incident["event_fingerprints"]), 1)

    def test_post_deploy_failure_correlates_only_with_sufficient_evidence(self):
        data = scenario("observability_incident_scenarios.php", "deploy")
        self.assertEqual(
            data["linked"]["incidents"][0]["correlation_reason"],
            "post_deploy_explicit",
        )
        self.assertEqual(
            data["unlinked"]["incidents"][0]["correlation_reason"],
            "new_incident",
        )
        self.assertEqual(
            data["too_late"]["incidents"][0]["correlation_reason"],
            "new_incident",
        )

    def test_recovery_resolves_incident_with_complete_timeline(self):
        data = scenario("observability_incident_scenarios.php", "recovery")
        incident = data["resolved"]["incidents"][0]
        self.assertEqual(incident["status"], "resolved")
        self.assertEqual(incident["resolved_at"], 120)
        self.assertEqual(data["timeline"]["incident_ref"], incident["incident_id"])
        self.assertEqual(data["timeline"]["recovered_at"], 120)
        kinds = {event["kind"] for event in incident["timeline_events"]}
        self.assertTrue({"incident.monitoring", "incident.resolved"} <= kinds)

    def test_critical_policy_can_freeze_new_deploys_without_destructive_actions(self):
        data = scenario("observability_policy_router_scenarios.php", "freeze")
        self.assertEqual(data["freeze"]["scope_type"], "project")
        self.assertEqual(data["freeze"]["source"], "policy")
        self.assertEqual(data["freeze"]["state"], "unknown")
        self.assertTrue(data["write"]["pause_blocked"])
        self.assertFalse(data["write"]["pause_allows"])
        self.assertFalse(data["read"]["pause_blocked"])
        self.assertTrue(data["read"]["pause_allows"])

    def test_missing_source_becomes_stale_or_unknown_not_healthy(self):
        data = scenario("observability_project_status_scenarios.php", "missing-stale")
        projects = {row["project_id"]: row for row in data["projects"]}
        controlbot = projects["project-controlbot"]["sources"]
        condor = projects["project-condor"]["sources"]
        self.assertEqual(controlbot["health"]["freshness"], "stale")
        self.assertEqual(controlbot["ci"]["freshness"], "unknown")
        self.assertEqual(controlbot["ci"]["severity"], "unknown")
        self.assertEqual(condor["health"]["freshness"], "unknown")
        self.assertNotEqual(condor["health"]["freshness"], "fresh")
        self.assertEqual(condor["agent"]["freshness"], "fresh")

    def test_postmortem_can_be_derived_from_incident_events(self):
        source = r'''
require "src/ObservabilityEvent.php";
require "src/IncidentTimeline.php";
require "src/ObservabilityIncident.php";
require "src/Postmortem.php";

use ControlBot\Business\IncidentTimeline;
use ControlBot\Business\Postmortem;
use ControlBot\Observability\ObservabilityIncident;

$failure = [
    "version"=>1,"source"=>"health","project_id"=>"controlbot","environment_id"=>"production",
    "repository_id"=>"pl0n3r/ControlBot","severity"=>"error","type"=>"health_probe",
    "payload"=>["status"=>"down"],"occurred_at"=>100,"received_at"=>101,
    "correlation_keys"=>["project:controlbot","issue:78"],
];
$recovery = $failure;
$recovery["severity"] = "info";
$recovery["payload"] = ["status"=>"healthy"];
$recovery["occurred_at"] = 120;
$recovery["received_at"] = 121;

$result = ObservabilityIncident::correlate(
    [$failure,$recovery],
    130,
    ["health"=>300,"ci"=>300,"deploy"=>300,"agent"=>300]
);
$incident = $result["incidents"][0];
$timeline = IncidentTimeline::build([
    "version"=>1,
    "incident_ref"=>$incident["incident_id"],
    "opened_at"=>$incident["opened_at"],
    "detected_at"=>$incident["opened_at"],
    "recovered_at"=>$incident["resolved_at"],
    "events"=>$incident["timeline_events"],
]);

$evidence = $incident["timeline_events"][0]["evidence_ref"];
$postmortem = Postmortem::analyze([
    "version"=>1,
    "incident_ref"=>$incident["incident_id"],
    "findings"=>[[
        "finding_ref"=>"finding:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "classification"=>"contributing_factor",
        "evidence_ref"=>$evidence,
        "evidence_state"=>"supported",
        "evidence_relation"=>"contributor",
        "summary"=>"Observed incident event is preserved as supported postmortem evidence.",
        "owner_action_required"=>false,
    ]],
    "recovery"=>[
        "canary_issue"=>86,
        "mode"=>"serial",
        "fan_out"=>false,
        "serial_queue"=>[72],
    ],
], $timeline);

echo json_encode([
    "incident"=>$incident,
    "timeline"=>$timeline,
    "postmortem"=>$postmortem,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
'''
        data = php_inline(source)
        incident = data["incident"]
        postmortem = data["postmortem"]
        evidence_refs = {event["evidence_ref"] for event in incident["timeline_events"]}
        self.assertEqual(incident["status"], "resolved")
        self.assertEqual(postmortem["incident_ref"], incident["incident_id"])
        self.assertIn(postmortem["findings"][0]["evidence_ref"], evidence_refs)
        self.assertEqual(postmortem["findings"][0]["evidence_state"], "supported")


if __name__ == "__main__":
    unittest.main()

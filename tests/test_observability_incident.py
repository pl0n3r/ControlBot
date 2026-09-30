import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    p=subprocess.run(
        ["php",str(ROOT/"tests"/"observability_incident_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,timeout=60,
    )
    return json.loads(p.stdout)

class ObservabilityIncidentTests(unittest.TestCase):
    def test_repeated_health_failure_opens_single_deduplicated_incident(self):
        i=scenario("dedupe")["incidents"][0]
        self.assertEqual((i["status"],i["occurrence_count"]),("open",2))
        self.assertEqual((len(i["event_fingerprints"]),len(i["timeline_events"])),(1,1))
        self.assertNotIn("_evidence_keys",i); self.assertNotIn("_keys",i)

    def test_post_deploy_failure_requires_explicit_correlation_evidence(self):
        d=scenario("deploy")
        self.assertEqual(d["linked"]["incidents"][0]["correlation_reason"],"post_deploy_explicit")
        self.assertEqual(len(d["linked"]["incidents"][0]["event_fingerprints"]),2)
        self.assertEqual(d["unlinked"]["incidents"][0]["correlation_reason"],"new_incident")
        self.assertEqual(d["too_late"]["incidents"][0]["correlation_reason"],"new_incident")

    def test_explicit_recovery_resolves_with_complete_timeline(self):
        d=scenario("recovery"); i=d["resolved"]["incidents"][0]
        self.assertEqual((i["status"],i["resolved_at"]),("resolved",120))
        self.assertEqual((d["timeline"]["incident_ref"],d["timeline"]["recovered_at"]),(i["incident_id"],120))
        self.assertTrue({"incident.monitoring","incident.resolved"}<={e["kind"] for e in i["timeline_events"]})
        self.assertEqual((d["stale_status"],d["unknown_status"]),("open","open"))
        self.assertEqual(d["reopened_status"],"open")

    def test_temporal_proximity_without_shared_evidence_does_not_correlate(self):
        incidents=scenario("separate")["incidents"]
        self.assertEqual(len(incidents),2)
        self.assertEqual({i["correlation_reason"] for i in incidents},{"new_incident"})
        self.assertEqual(len({i["incident_id"] for i in incidents}),2)

    def test_severity_escalation_and_result_are_deterministic(self):
        d=scenario("severity")
        self.assertEqual(d["forward"],d["reverse"])
        self.assertEqual(d["forward"]["incidents"][0]["severity"],"critical")

    def test_incident_core_has_no_external_io_or_actions(self):
        self.assertEqual(scenario("pure")["hits"],[])

if __name__=="__main__": unittest.main()

import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"incident_postmortem_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode or r.stderr.strip(): raise AssertionError(r.stderr.strip() or f"scenario {name} failed")
    return json.loads(r.stdout)

class IncidentTimelineTests(unittest.TestCase):
    def test_events_render_chronologically_without_losing_source_order(self):
        d=scenario("timeline"); events=d["events"]
        self.assertEqual([e["event_id"] for e in events],["event-opened","event-capacity","event-recovered"])
        self.assertEqual([e["source_index"] for e in events],[1,0,2])
        self.assertEqual([e["source"] for e in events],["controlbot","github_actions","owner_report"])
        self.assertEqual(d["duration_seconds"],110)

    def test_mttr_is_fail_closed_when_recovery_timestamp_is_missing(self):
        d=scenario("missing_recovery")
        self.assertEqual(d["detected_at"],110)
        self.assertIsNone(d["recovered_at"]); self.assertIsNone(d["duration_seconds"])

if __name__=="__main__": unittest.main()

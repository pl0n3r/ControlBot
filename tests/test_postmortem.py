import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"incident_postmortem_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode or r.stderr.strip(): raise AssertionError(r.stderr.strip() or f"scenario {name} failed")
    return json.loads(r.stdout)

class PostmortemTests(unittest.TestCase):
    def test_incident_78_keeps_causal_categories_distinct(self):
        d=scenario("postmortem")
        classes={x["finding_ref"]:x["classification"] for x in d["findings"]}
        self.assertEqual(classes["finding:"+"a"*32],"root_cause")
        self.assertEqual(classes["finding:"+"b"*32],"independent_bug")
        self.assertEqual(classes["finding:"+"c"*32],"contributing_factor")

    def test_preventive_observer_change_is_not_reclassified_as_root_cause(self):
        d=scenario("postmortem")
        observer=[x for x in d["findings"] if "observer cadence" in x["summary"]][0]
        self.assertEqual(observer["classification"],"preventive_change")
        self.assertEqual(observer["evidence_relation"],"preventive_only")
        self.assertTrue(scenario("observer_reclassified")["rejected"])

    def test_incomplete_evidence_remains_unknown_and_requests_owner_action(self):
        d=scenario("unknown")
        for state in ("incomplete","contradictory"):
            row=[x for x in d[state]["findings"] if x["classification"]=="unknown"][0]
            self.assertEqual(row["evidence_state"],state)
            self.assertEqual(row["evidence_relation"],"unresolved")
            self.assertTrue(row["owner_action_required"])

    def test_incident_78_recovery_preserves_single_canary_and_serial_queue(self):
        d=scenario("postmortem")
        self.assertEqual(d["recovery"],{
            "canary_issue":86,"mode":"serial","fan_out":False,
            "serial_queue":[72,77,80,71,84,73,75],
        })
        bad=scenario("bad_recovery")
        for key in ("fanout","parallel","duplicate","canary_repeated"):
            self.assertTrue(bad[key],(key,bad))

    def test_postmortem_summary_accepts_iso_date_without_treating_it_as_phone_pii(self):
        self.assertTrue(scenario("dated_summary")["accepted"])

    def test_core_has_no_external_io_or_scheduler_side_effects(self):
        source=" ".join(scenario("source").values()).lower()
        for token in ("new pdo","mysqli","curl_","http://","https://","shell_exec","exec(","file_put_contents","scheduler"):
            self.assertNotIn(token,source)

if __name__=="__main__": unittest.main()

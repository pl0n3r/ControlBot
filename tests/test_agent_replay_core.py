import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests"/"agent_replay_core_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(run.stdout)

class AgentReplayCoreTests(unittest.TestCase):
    def test_replay_is_stable_and_idempotent_with_timestamp_ties(self):
        d=scenario("stable")
        self.assertEqual(d["first"],d["second"])
        self.assertEqual(len(d["first"]["events"]),2)
        self.assertEqual([e["event_id"] for e in d["first"]["events"]],["evt-00000001","evt-00000002"])

    def test_ci_states_remain_distinct_without_reinterpretation(self):
        d=scenario("states")
        self.assertEqual([e["kind"] for e in d["events"]],["success","failure","skipped","startup_failure"])
        self.assertEqual(d["conflicts"],[])

    def test_handoff_preserves_workitem_identity_without_actor_confusion(self):
        d=scenario("handoff")
        self.assertEqual({e["work_item_id"] for e in d["events"]},{"controlbot-365"})
        self.assertEqual([e["actor_ref"] for e in d["events"]],["agent:alpha","agent:beta"])
        self.assertEqual([e["kind"] for e in d["events"]],["handoff","attempted"])

    def test_conflicting_evidence_is_preserved_as_unknown(self):
        d=scenario("conflict")
        self.assertEqual(len(d["events"]),2)
        self.assertEqual({e["kind"] for e in d["events"]},{"success","failure"})
        self.assertEqual(len(d["conflicts"]),1)
        self.assertEqual(d["conflicts"][0]["event_id"],"evt-conflict-1")
        self.assertEqual(d["conflicts"][0]["state"],"unknown")
        self.assertEqual(d["conflicts"][0]["reason"],"conflicting_evidence")
        self.assertEqual(d["conflicts"][0]["variant_count"],2)

    def test_replay_rejects_transcripts_thoughts_secrets_and_pii(self):
        d=scenario("sensitive")
        self.assertTrue(all(d["rejected"]))

    def test_replay_core_is_deterministic_and_external_io_free(self):
        d=scenario("purity")
        self.assertEqual(d["first"],d["second"])
        self.assertRegex(d["first"]["fingerprint"],r"^[0-9a-f]{64}$")
        source=(ROOT/"src"/"AgentReplayCore.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","file_get_contents(","fopen(","file_put_contents(","new pdo","mysqli","shell_exec(","exec(","proc_open(","passthru("):
            self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()

import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    return json.loads(subprocess.run(["php",str(ROOT/"tests"/"agent_replay_lifecycle_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True).stdout)

class AgentReplayLifecycleTests(unittest.TestCase):
    def test_verified_lifecycle_orders_issue_reservation_commits_checks_merge_and_deploy(self):
        d=scenario("complete")
        self.assertEqual([s["name"] for s in d["stages"]],["issue","reservation","plan","commit","review","check","merge","deploy"])
        self.assertTrue(all(s["state"]=="observed" for s in d["stages"]))
        self.assertEqual([s["events"][0]["event_id"] for s in d["stages"]],["evt-issue","evt-reservation","evt-plan","evt-commit","evt-review","evt-check","evt-merge","evt-deploy"])

    def test_missing_evidence_remains_unknown_without_inference(self):
        d=scenario("missing"); states={s["name"]:s["state"] for s in d["stages"]}
        self.assertEqual(states["issue"],"observed"); self.assertEqual(states["plan"],"observed"); self.assertEqual(states["commit"],"observed")
        for stage in ("reservation","review","check","merge","deploy"): self.assertEqual(states[stage],"missing")
        self.assertEqual(d["missing"],["reservation","review","check","merge","deploy"])

    def test_incident_78_preserves_success_then_startup_failure(self):
        d=scenario("incident78"); check=next(s for s in d["stages"] if s["name"]=="check")
        self.assertEqual([e["kind"] for e in check["events"]],["success","startup_failure"])
        self.assertNotIn("application_state",d); self.assertNotIn("step_failure",json.dumps(d).lower())

    def test_pr_86_draft_skipped_is_not_test_success(self):
        d=scenario("pr86"); check=next(s for s in d["stages"] if s["name"]=="check")
        self.assertEqual([e["kind"] for e in check["events"]],["skipped"]); self.assertNotIn("success",[e["kind"] for e in check["events"]])

    def test_manual_and_documental_actions_are_not_ci_execution(self):
        d=scenario("manual"); check=next(s for s in d["stages"] if s["name"]=="check")
        self.assertEqual(check["state"],"missing")
        self.assertEqual([(x["stage"],x["event"]["kind"]) for x in d["auxiliary_evidence"]],[("manual","attempted"),("documental","requested")])

    def test_lifecycle_is_deterministic_pure_and_preserves_conflicts(self):
        d=scenario("determinism"); self.assertEqual(d["first"],d["second"])
        self.assertEqual(len(d["first"]["conflicts"]),1); self.assertEqual(d["first"]["conflicts"][0]["state"],"unknown")
        self.assertRegex(d["first"]["fingerprint"],r"^[0-9a-f]{64}$")
        source=(ROOT/"src"/"AgentReplayLifecycle.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","file_get_contents(","fopen(","file_put_contents(","new pdo","mysqli","shell_exec(","exec(","proc_open(","passthru("): self.assertNotIn(forbidden,source)

if __name__=="__main__": unittest.main()

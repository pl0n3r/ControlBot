import html,json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];SCENARIO=ROOT/"tests/factory_owner_decisions_inbox_scenarios.php"
def scenario(name):
    r=subprocess.run(["php",str(SCENARIO),name],cwd=ROOT,text=True,capture_output=True,timeout=30)
    if r.returncode: raise AssertionError(r.stderr or r.stdout)
    return json.loads(r.stdout)
class OwnerDecisionsInboxTests(unittest.TestCase):
    def test_collector_extracts_only_allowlisted_gate_fields_with_bounded_parsing(self):
        d=scenario("structured");rows={x["data"]["issue_ref"]:x["data"] for x in d["evidence"]["owner_decisions"]};p=rows["github:pl0n3r/ControlBot#744"]
        self.assertEqual(("structured","pl0n3r/ControlBot",744,["A","B"]),(p["format"],p["repository_ref"],p["issue_number"],[x["id"] for x in p["options"]]))
        self.assertNotIn("context",p);self.assertNotIn("category",p);self.assertEqual("2026-10-05T15:00:00Z",rows["github:pl0n3r/Factory#1014"]["expires_at"]);self.assertEqual("legacy",rows["github:pl0n3r/Condor#437"]["format"])
    def test_ui_renders_options_recommendation_default_and_exact_copy_command(self):
        d=scenario("structured");r=html.unescape(d["html"])
        for x in ["Decisiones tuyas","RECOMENDADA","DEFAULT SEGURO",'data-copy-command=','gh issue comment 744 -R pl0n3r/ControlBot --body "/decidir A"','gh issue comment 1014 -R pl0n3r/Factory --body "/decidir B"']: self.assertIn(x,r)
        e=(ROOT/"src/FactoryOrchestratorLiveEndpoint.php").read_text();self.assertIn("navigator.clipboard.writeText",e);self.assertNotIn("api.github.com",e);rel=next(x for x in d["view"]["owner_decisions"] if x["issue_number"]==1014);self.assertEqual((3600,False),(rel["seconds_left"],rel["expired"]))
    def test_untrusted_issue_text_is_escaped_and_hostile_markers_degrade_to_legacy(self):
        d=scenario("hostile");self.assertTrue(d["evidence"]["owner_decisions"]);self.assertTrue(all(x["data"]["format"]=="legacy" for x in d["evidence"]["owner_decisions"]));self.assertNotIn("<script>",d["html"].lower());self.assertIn("&lt;b&gt;Decisión hostil&lt;/b&gt;",d["html"]);self.assertNotIn("data-copy-command",d["html"])
    def test_command_is_built_only_from_validated_repo_number_and_option_id(self):
        self.assertTrue(all(scenario("invalid-command")["checks"]));s=(ROOT/"src/FactoryOrchestratorLiveUi.php").read_text();self.assertIn("decisionRepository",s);self.assertIn("decisionNumber",s);self.assertIn("decisionOptionId",s);self.assertNotIn("workflow_dispatch",s)
if __name__=="__main__": unittest.main()

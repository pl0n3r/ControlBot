import html
import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SCENARIO=ROOT/"tests/factory_owner_decisions_inbox_scenarios.php"

def scenario(name):
    run=subprocess.run(["php",str(SCENARIO),name],cwd=ROOT,text=True,capture_output=True,timeout=30)
    if run.returncode:
        raise AssertionError(run.stderr or run.stdout)
    return json.loads(run.stdout)

class OwnerDecisionsInboxTests(unittest.TestCase):
    def test_collector_extracts_only_allowlisted_gate_fields_with_bounded_parsing(self):
        data=scenario("structured")
        rows={row["data"]["issue_ref"]:row["data"] for row in data["evidence"]["owner_decisions"]}
        product=rows["github:pl0n3r/ControlBot#744"]
        self.assertEqual("structured",product["format"])
        self.assertEqual("pl0n3r/ControlBot",product["repository_ref"])
        self.assertEqual(744,product["issue_number"])
        self.assertEqual(["A","B"],[x["id"] for x in product["options"]])
        self.assertNotIn("context",product)
        self.assertNotIn("category",product)
        release=rows["github:pl0n3r/Factory#1014"]
        self.assertEqual("2026-10-05T15:00:00Z",release["expires_at"])
        legacy=rows["github:pl0n3r/Condor#437"]
        self.assertEqual("legacy",legacy["format"])
        self.assertEqual([],legacy["options"])

    def test_ui_renders_options_recommendation_default_and_exact_copy_command(self):
        data=scenario("structured")
        rendered=html.unescape(data["html"])
        self.assertIn("Decisiones tuyas",rendered)
        self.assertIn("RECOMENDADA",rendered)
        self.assertIn("DEFAULT SEGURO",rendered)
        self.assertIn('data-copy-command=',rendered)
        self.assertIn('gh issue comment 744 -R pl0n3r/ControlBot --body "/decidir A"',rendered)
        self.assertIn('gh issue comment 1014 -R pl0n3r/Factory --body "/decidir B"',rendered)
        endpoint=(ROOT/"src/FactoryOrchestratorLiveEndpoint.php").read_text(encoding="utf-8")
        self.assertIn("navigator.clipboard.writeText",endpoint)
        self.assertIn("data-copy-command",endpoint)
        self.assertNotIn("api.github.com",endpoint)
        release=next(x for x in data["view"]["owner_decisions"] if x["issue_number"]==1014)
        self.assertEqual(3600,release["seconds_left"])
        self.assertFalse(release["expired"])

    def test_untrusted_issue_text_is_escaped_and_hostile_markers_degrade_to_legacy(self):
        data=scenario("hostile")
        self.assertTrue(data["evidence"]["owner_decisions"])
        self.assertTrue(all(row["data"]["format"]=="legacy" for row in data["evidence"]["owner_decisions"]))
        self.assertNotIn("<script>",data["html"].lower())
        self.assertIn("&lt;b&gt;Decisión hostil&lt;/b&gt;",data["html"])
        self.assertNotIn("data-copy-command",data["html"])
        source=(ROOT/"src/FactoryOrchestratorLiveUi.php").read_text(encoding="utf-8").lower()
        self.assertNotIn("onclick=",source)
        self.assertNotIn("api.github.com",source)

    def test_command_is_built_only_from_validated_repo_number_and_option_id(self):
        self.assertTrue(all(scenario("invalid-command")["checks"]))
        source=(ROOT/"src/FactoryOrchestratorLiveUi.php").read_text(encoding="utf-8")
        self.assertIn("decisionRepository",source)
        self.assertIn("decisionNumber",source)
        self.assertIn("decisionOptionId",source)
        self.assertNotIn("workflow_dispatch",source)

if __name__=="__main__":
    unittest.main()

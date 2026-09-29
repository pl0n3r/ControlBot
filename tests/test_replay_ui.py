import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def render(name, active="all"):
    run=subprocess.run(["php",str(ROOT/"tests"/"replay_ui_scenarios.php"),name,active],cwd=ROOT,check=True,text=True,capture_output=True)
    return run.stdout

class ReplayUiTests(unittest.TestCase):
    def test_replay_filters_and_evidence_links_render(self):
        html=render("filters","ci")
        for category in ("all","code","ci","coordination","decisions","production","security"):
            self.assertIn(f"?category={category}",html)
        self.assertIn("CI check evidence",html)
        self.assertNotIn("Code commit evidence",html)
        self.assertNotIn("Production deploy evidence",html)
        self.assertIn('href="https://github.com/pl0n3r/ControlBot/actions/runs/123"',html)

    def test_event_rows_preserve_stage_kind_actor_and_evidence(self):
        html=render("event_rows")
        for text in ("commit","success","agent","agent:alpha","Verified code change","external:change/evt-row"):
            self.assertIn(text,html)
        self.assertIn("<code>external:change/evt-row</code>",html)

    def test_states_and_conflicts_render_without_reinterpretation(self):
        html=render("states")
        for state in ("success","startup_failure","skipped","failure"):
            self.assertIn(state,html)
        self.assertIn("unknown",html)
        self.assertIn("conflicting_evidence",html)
        self.assertNotIn("step_failure",html)

    def test_category_is_explicit_and_not_inferred(self):
        security=render("explicit","security")
        ci=render("explicit","ci")
        self.assertIn("Explicit security category",security)
        self.assertNotIn("Explicit security category",ci)
        self.assertEqual(json.loads(render("contradictory"))["rejected"],True)

    def test_untrusted_content_is_escaped_and_sensitive_material_rejected(self):
        html=render("escape")
        self.assertNotIn("<script>alert(1)</script>",html)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;",html)
        self.assertEqual(json.loads(render("secret"))["rejected"],True)

    def test_mobile_accessibility_and_read_only_contract(self):
        html=render("filters")
        self.assertIn('name="viewport"',html)
        self.assertIn('aria-label="Filtros de replay"',html)
        self.assertIn(":focus-visible",html)
        self.assertIn("prefers-reduced-motion",html)
        self.assertNotIn("<form",html.lower())
        self.assertNotIn("<button",html.lower())

if __name__=="__main__": unittest.main()

import re, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/"src/FactoryOrchestratorLiveUi.php"

def render(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests/factory_orchestrator_live_ui_scenarios.php"),name],
        cwd=ROOT,text=True,capture_output=True,check=True,timeout=30,
    )
    return run.stdout

class FactoryOrchestratorLiveUiTests(unittest.TestCase):
    def test_renders_central_node_fronts_edges_and_event_bus_from_read_model(self):
        html=render("full")
        self.assertIn('data-node="central"',html)
        self.assertIn('data-front="work:controlbot-622"',html)
        self.assertIn('data-from="central"',html)
        self.assertIn('data-section="event_bus"',html)
        self.assertIn("40%",html)
        self.assertIn("--flow:4s",html)

    def test_read_only_ui_exposes_no_mutating_controls_or_sensitive_payloads(self):
        html=render("hostile")
        self.assertIn("solo lectura",html.lower())
        self.assertIn("Kill switch: UNKNOWN",html)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;",html)
        self.assertNotIn("<form",html.lower())
        self.assertNotIn("<button",html.lower())
        self.assertEqual(render("invalid"),"blocked")
        source=SOURCE.read_text().lower()
        for token in ("apiclient","apitransport","api.github.com","curl_","file_get_contents",
                      "workflow_dispatch","'post'","'put'","'patch'","'delete'","/tomar","/decidir"):
            self.assertNotIn(token,source)

    def test_mobile_layout_filters_and_human_latency_are_accessible(self):
        html=render("full")
        for token in ('name="viewport"','data-filter="repository"','data-filter="type"',
                      'data-section="human"',"Antigüedad: 70s",":focus-visible",
                      "@media(min-width:760px)","@media(prefers-reduced-motion:reduce)"):
            self.assertIn(token,html)
        self.assertIn('aria-label="Filtros de vista"',html)
        self.assertIn("https://github.com/pl0n3r/ControlBot/issues/700",html)

    def test_truncated_inventory_is_explicit_and_bad_summary_is_denied(self):
        html = render("truncated")
        self.assertIn('data-signal-truncated="true"', html)
        self.assertIn("Evidencia parcial: señales truncadas", html)
        self.assertIn("bloqueos=230", html)
        self.assertIn("trabajo=256", html)
        self.assertIn("cobertura completa no están verificados", html)
        self.assertEqual(render("invalid_summary"), "blocked")

    def test_stale_and_truncated_metadata_are_visible_and_never_current(self):
        html = render("truncated")
        self.assertIn('data-signal-truncated="true"', html)
        self.assertIn("bloqueos=230", html)
        result = subprocess.run(
            ["php", str(ROOT / "tests/factory_orchestrator_snapshot_refresh_scenarios.php"),
             "stale_fallback"], cwd=ROOT, text=True, capture_output=True,
            timeout=30, check=True,
        )
        import json
        stale = json.loads(result.stdout)
        self.assertTrue(stale["visible_stale"])
        self.assertTrue(stale["visible_last_success"])
        self.assertTrue(stale["no_old_work"])
        self.assertEqual(stale["old_view_state"], "UNKNOWN")
        self.assertEqual(stale["old_view_fronts"], 0)

    def test_stale_and_unknown_are_visually_distinct_and_never_green(self):
        full=render("full")
        self.assertIn("state-stale",full)
        stale=render("stale_unknown")
        self.assertIn('<article class="central state-unknown"',stale)
        self.assertNotIn('<article class="central state-current"',stale)
        css=re.search(r"<style>(.*?)</style>",stale,re.S).group(1)
        self.assertRegex(css,r"state-stale[^}]*var\(--amber\)")
        self.assertRegex(css,r"state-unknown[^}]*var\(--muted\)")

if __name__=="__main__":
    unittest.main()

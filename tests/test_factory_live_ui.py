import re, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/"src/FactoryLiveUi.php"

def render(name):
    r=subprocess.run(["php",str(ROOT/"tests/factory_live_ui_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
    return r.stdout

def luminance(value):
    channels=[int(value[i:i+2],16)/255 for i in (1,3,5)]
    channels=[c/12.92 if c<=.04045 else ((c+.055)/1.055)**2.4 for c in channels]
    return .2126*channels[0]+.7152*channels[1]+.0722*channels[2]

def contrast(a,b):
    x,y=luminance(a),luminance(b)
    return (max(x,y)+.05)/(min(x,y)+.05)

class FactoryLiveUiTests(unittest.TestCase):
    def test_view_renders_all_factory_live_sections(self):
        html=render("full")
        for section in ("owner_decisions","batches","releases","blockers","production","quality","work","learning","tool_usage"):
            self.assertIn(f'data-section="{section}"',html)
        self.assertIn("Fábrica viva",html);self.assertIn("Remote Desktop Commander",html)

    def test_owner_decisions_are_rendered_first_with_issue_refs(self):
        html=render("full")
        self.assertLess(html.index('data-section="owner_decisions"'),html.index('data-section="batches"'))
        self.assertIn('href="https://github.com/pl0n3r/Condor/issues/384"',html)
        self.assertIn("Abrir Issue de decisión",html)

    def test_unknown_and_stale_are_explicit_and_never_green(self):
        empty=render("empty");self.assertIn("UNKNOWN",empty);self.assertNotIn("state-healthy fresh-unknown",empty)
        quality=render("stale").split('data-section="quality"',1)[1]
        self.assertIn("fresh-stale",quality);self.assertIn(">STALE<",quality);self.assertIn("state-degraded",quality)
        self.assertNotIn("state-healthy fresh-stale",quality)
        self.assertEqual(render("incoherent").strip(),"blocked")

    def test_view_is_read_only_secret_free_and_escapes_untrusted_content(self):
        html=render("hostile")
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;",html);self.assertIn("&lt;img src=x onerror=alert(1)&gt;",html)
        self.assertNotIn("<script>alert(1)</script>",html);self.assertNotIn("<img src=x onerror=alert(1)>",html)
        source=SOURCE.read_text()
        for value in ("<form","ApiClient","ApiTransport","curl_","file_get_contents","fsockopen","EntityManager","PDO","'POST'","'PATCH'","'DELETE'"):
            self.assertNotIn(value,source)

    def test_mobile_desktop_and_contrast_follow_existing_visual_contract(self):
        html=render("full")
        for token in ('name="viewport"',"grid-template-columns:1fr","@media(min-width:760px)","@media(prefers-reduced-motion:reduce)",":focus-visible"):
            self.assertIn(token,html)
        colors=dict(re.findall(r"--([a-z-]+):\s*(#[0-9a-fA-F]{6})",html))
        self.assertEqual(colors["bg"].lower(),"#0a0e13");self.assertEqual(colors["panel"].lower(),"#10161d")
        for name in ("text","muted","cyan","green","amber","red"):
            self.assertGreaterEqual(contrast(colors[name],colors["panel"]),4.5,name)

    def test_complete_empty_stale_and_hostile_fixtures_render_without_network(self):
        for name in ("full","empty","stale","hostile"):
            html=render(name);self.assertTrue(html.startswith("<!doctype html>"));self.assertIn("</html>",html)

if __name__=="__main__": unittest.main()

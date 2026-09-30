import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def render(name):
 r=subprocess.run(["php",str(ROOT/"tests"/"executive_cockpit_ui_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return r.stdout
class ExecutiveCockpitUiTests(unittest.TestCase):
 def test_health_and_freshness_are_rendered_without_false_green(self):
  html=render("stale")
  self.assertIn("Business health",html); self.assertIn("Technical health",html)
  self.assertIn("degraded",html); self.assertIn("freshness stale",html); self.assertIn("freshness unknown",html)
  self.assertNotIn('health-healthy fresh-stale',html); self.assertNotIn('health-healthy fresh-unknown',html)
 def test_owner_inbox_classes_and_decision_refs_are_read_only(self):
  html=render("base")
  for cls in ("FYI","WATCH","DECISION","CRITICAL"): self.assertIn(cls,html)
  self.assertIn("controlbot:decision/decide-aa",html); self.assertIn("L4_OWNER",html)
  for forbidden in ("<button","<form","approve","reject"): self.assertNotIn(forbidden,html.lower())
 def test_runtime_responsibility_and_safe_text_come_from_canonical_projection(self):
  html=render("base")
  self.assertIn("owner-alpha",html); self.assertIn("factoryrunner",html); self.assertIn("Runtime",html)
  bad=json.loads(render("unsafe")); self.assertTrue(all(bad.values()))
 def test_nested_projection_shapes_fail_closed(self):
  bad = json.loads(render("unsafe"))
  for key in (
   "responsible_extra",
   "finance_extra",
   "product_health_extra",
   "infrastructure_extra",
   "runtime_extra",
   "counts_extra",
  ):
   self.assertTrue(bad[key], key)
 def test_venture_drilldown_is_scoped_deterministic_and_internal(self):
  html=render("order")
  self.assertLess(html.index("Alpha"),html.index("Beta"))
  self.assertIn('href="#venture-venture-alpha"',html); self.assertIn('id="venture-venture-alpha"',html)
  self.assertNotIn('href="http',html.lower()); self.assertNotIn('href="//',html.lower())
 def test_mobile_desktop_accessibility_contract(self):
  html=render("base")
  self.assertIn('name="viewport"',html); self.assertIn("<main>",html); self.assertIn("<nav",html)
  self.assertIn("aria-label=",html); self.assertIn("@media(min-width:760px)",html)
  self.assertIn("@media(prefers-reduced-motion:reduce)",html)
 def test_renderer_has_no_external_io_or_execution(self):
  source=(ROOT/"src"/"ExecutiveCockpitUi.php").read_text(encoding="utf-8").lower()
  self.assertIn("uitheme::tokenscss()",source)
  for bad in ("curl_","http://","https://","mysqli","pdo(","file_put_contents","fopen(","factoryrunner::","enqueue(","dispatch","scheduler","<script"):
   self.assertNotIn(bad,source)
  self.assertNotIn("executivecockpit::build",source); self.assertNotIn("ownerinbox::collection",source)
if __name__=="__main__": unittest.main()

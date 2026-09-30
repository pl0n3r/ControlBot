import json,re,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
PROJECTS=["Condor","GrindFlow","BRVTAL","FactoryRunner","ControlBot","AutoFactory","Factory"]
DEPS=["governance","sre_infra_dba","security","qa_engineering","legal_privacy"]
STATUS={"GREEN","AMBER","RED","UNKNOWN","STALE"}
def run(name,mode="json"):
 r=subprocess.run(["php",str(ROOT/"tests/factory_live_matrix_scenarios.php"),name,mode],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30);return r.stdout
def matrix(name):return json.loads(run(name))
def lum(h):
 c=[int(h[i:i+2],16)/255 for i in (1,3,5)];c=[x/12.92 if x<=.04045 else ((x+.055)/1.055)**2.4 for x in c];return .2126*c[0]+.7152*c[1]+.0722*c[2]
def ratio(a,b):
 x,y=sorted((lum(a),lum(b)),reverse=True);return (x+.05)/(y+.05)
class FactoryLiveMatrixTests(unittest.TestCase):
 def test_global_header_keeps_provenance(self):
  h=matrix("full")["header"]
  for key in ("batches","owner_decisions","releases","blockers","incidents"):
   self.assertIn(h[key]["status"],STATUS);self.assertGreater(h[key]["count"],0);s=h[key]["signal"]
   for field in ("source_ref","observed_at","freshness","age_seconds","evidence_href"):self.assertIn(field,s)
   self.assertTrue(s["source_ref"]);self.assertIsInstance(s["age_seconds"],int)
  recent=matrix("recent")["header"]["batches"]["signal"]
  self.assertEqual(recent["id"],"batch:tanda-3")
  self.assertEqual(recent["age_seconds"],10)
 def test_matrix_covers_seven_projects_and_operational_departments(self):
  m=matrix("full");self.assertEqual(m["projects"],PROJECTS);self.assertEqual(m["departments"],DEPS)
  for project in PROJECTS:
   self.assertEqual(set(m["cells"][project]),set(DEPS))
 def test_cells_use_closed_status_contract(self):
  m=matrix("full")
  for project in PROJECTS:
   for dep in DEPS:
    cell=m["cells"][project][dep];self.assertIn(cell["status"],STATUS)
    if cell["signal"] is not None:
     self.assertIn(cell["signal"]["status"],STATUS)
     if cell["signal"]["evidence_href"] is not None:self.assertTrue(cell["signal"]["evidence_href"].startswith("https://"))
 def test_missing_or_stale_evidence_never_becomes_green(self):
  empty=matrix("empty")
  self.assertTrue(all(empty["cells"][p][d]["status"]=="UNKNOWN" for p in PROJECTS for d in DEPS))
  self.assertEqual(matrix("stale")["cells"]["Condor"]["governance"]["status"],"STALE")
  self.assertEqual(matrix("error")["cells"]["GrindFlow"]["security"]["status"],"RED")
 def test_ui_is_read_only_secret_free_mobile_first(self):
  html=run("hostile","html");self.assertIn("&lt;img src=x onerror=alert(1)&gt;",html);self.assertNotIn("<img src=x onerror=alert(1)>",html)
  for token in ('data-section="operations_matrix"',"overflow-x:auto","min-width:900px",":focus-visible","prefers-reduced-motion"):self.assertIn(token,html)
  src=(ROOT/"src/FactoryLiveMatrix.php").read_text()+(ROOT/"src/FactoryLiveUi.php").read_text()
  for bad in ("<form","curl_","file_put_contents","fopen(","mysqli","PDO(","'POST'","'PATCH'","'DELETE'"):self.assertNotIn(bad,src)
  colors=dict(re.findall(r"--([a-z-]+):\s*(#[0-9a-fA-F]{6})",html))
  for fg in ("text","muted","cyan","green","amber","red"):self.assertGreaterEqual(ratio(colors[fg],colors["panel"]),4.5)
 def test_simulated_sources_cover_each_section(self):
  for name in ("full","empty","stale","error"):
   m=matrix(name);self.assertEqual(m["projects"],PROJECTS);self.assertEqual(set(m["header"]),{"batches","owner_decisions","releases","blockers","incidents"})
if __name__=="__main__":unittest.main()

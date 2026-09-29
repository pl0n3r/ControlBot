import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"hosting_read_ui_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode or r.stderr.strip(): raise AssertionError(r.stderr.strip() or f"scenario {name} failed")
    return json.loads(r.stdout)

class HostingReadUiTests(unittest.TestCase):
    def test_freshness_source_and_observed_at_render(self):
        d=scenario("render"); row=d["rows"][0]
        self.assertEqual((row["freshness"],row["source"],row["observed_at"]),("fresh","hostinger:ssh",1790713200))
        self.assertEqual(d["mode"],"read_only"); self.assertNotIn("actions",d)

    def test_stale_snapshot_is_visibly_non_ready(self):
        d=scenario("stale"); self.assertEqual(d["state"],"warning")

if __name__=="__main__": unittest.main()

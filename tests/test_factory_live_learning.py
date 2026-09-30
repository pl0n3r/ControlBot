import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SOURCE=ROOT/"src/FactoryLearningSnapshot.php"
UI=ROOT/"src/FactoryLiveUi.php"
LAYERS=["product_business","application","data","infrastructure","ci_cd","quality","security_privacy","production_observability","governance_agents","costs_limits"]
METRICS=["lessons_per_week_project","incidents_by_class","mttr_seconds","blockers_with_cause","fix_feat_ratio","learning_gaps"]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests/factory_live_learning_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
    return r.stdout.strip()

class FactoryLiveLearningTests(unittest.TestCase):
    def test_layers_fail_closed(self):
        d=json.loads(scenario("full")); self.assertEqual(list(d["layers"]),LAYERS)
        self.assertTrue(all(d["layers"][layer]["status"]!="UNKNOWN" for layer in LAYERS))
        missing=json.loads(scenario("missing")); self.assertEqual(missing,{"status":"UNKNOWN","signals":[]})
        html=scenario("ui"); self.assertIn('data-section="learning_layers"',html)
        for layer in LAYERS:self.assertIn(layer,html)

    def test_drill_down_keeps_evidence(self):
        d=json.loads(scenario("full"))
        for layer in LAYERS:
            self.assertTrue(d["layers"][layer]["signals"])
            for signal in d["layers"][layer]["signals"]:
                self.assertIsInstance(signal["source_ref"],str);self.assertIsInstance(signal["age_seconds"],int)
                self.assertTrue(signal["evidence_href"].startswith("https://"))
        html=scenario("ui");self.assertIn("Evidencia",html)
        source=SOURCE.read_text()+UI.read_text()
        for value in ("ApiClient","ApiTransport","curl_","file_get_contents","fsockopen","EntityManager","PDO","'POST'","'PATCH'","'DELETE'"):
            self.assertNotIn(value,source)

    def test_learning_metrics_keep_source_and_age(self):
        d=json.loads(scenario("full"));self.assertEqual(list(d["metrics"]),METRICS)
        self.assertEqual(d["metrics"]["mttr_seconds"]["value"],420)
        for metric in METRICS:
            row=d["metrics"][metric];self.assertNotEqual(row["status"],"UNKNOWN");self.assertIsNotNone(row["value"])
            self.assertIsInstance(row["source_ref"],str);self.assertIsInstance(row["age_seconds"],int)
            self.assertTrue(row["evidence_href"].startswith("https://"))

    def test_unlinked_recurrence_is_unknown(self):
        row=json.loads(scenario("recurrence"))
        self.assertEqual(row["status"],"UNKNOWN");self.assertIsNone(row["value"])
        self.assertIsNone(row["source_ref"]);self.assertEqual(row["freshness"],"unknown")

    def test_auto_notices_are_separate_from_real_incidents(self):
        d=json.loads(scenario("full")); real=d["incidents"]["real"]; auto=d["incidents"]["auto_notices"]
        self.assertEqual([x["title"] for x in real],["Database outage"])
        self.assertEqual([x["title"] for x in auto],["[AUTO] CI warning"])
        self.assertTrue(all(not x["title"].startswith("[AUTO]") for x in real))
        self.assertTrue(all(x["title"].startswith("[AUTO]") for x in auto))

if __name__=="__main__": unittest.main()

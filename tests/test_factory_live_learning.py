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
        self.assertEqual(d["metrics"]["mttr_seconds"]["by_project"]["ControlBot"]["value"],420)
        for metric in METRICS:
            row=d["metrics"][metric];self.assertEqual(row["global"]["status"],"UNKNOWN")
            self.assertIsNone(row["global"]["value"])
            self.assertIn("ControlBot",row["by_project"])
            value=row["by_project"]["ControlBot"];self.assertNotEqual(value["status"],"UNKNOWN");self.assertIsNotNone(value["value"])
            self.assertIsInstance(value["source_ref"],str);self.assertIsInstance(value["age_seconds"],int)
            self.assertTrue(value["evidence_href"].startswith("https://"))

    def test_learning_metrics_preserve_multiple_projects(self):
        metric=json.loads(scenario("full"))["metrics"]["lessons_per_week_project"]
        self.assertEqual(metric["by_project"]["Condor"]["value"],3)
        self.assertEqual(metric["by_project"]["ControlBot"]["value"],7)
        for project in ("Condor","ControlBot"):
            row=metric["by_project"][project]
            self.assertIsInstance(row["source_ref"],str)
            self.assertIsInstance(row["age_seconds"],int)
            self.assertEqual(row["freshness"],"current")

    def test_global_metric_is_unknown_without_global_evidence(self):
        global_metric=json.loads(scenario("full"))["metrics"]["lessons_per_week_project"]["global"]
        self.assertEqual(global_metric["status"],"UNKNOWN")
        self.assertIsNone(global_metric["value"])
        self.assertIsNone(global_metric["source_ref"])
        self.assertEqual(global_metric["freshness"],"unknown")

    def test_layer_drill_down_preserves_project(self):
        d=json.loads(scenario("full"))
        projects={signal["project"] for layer in d["layers"].values() for signal in layer["signals"]}
        self.assertIn("ControlBot",projects)
        self.assertNotIn(None,projects)

    def test_ui_renders_project_and_global_learning(self):
        html=scenario("ui")
        self.assertIn("GLOBAL",html)
        self.assertIn("Condor",html)
        self.assertIn("ControlBot",html)
        self.assertIn("UNKNOWN",html)

    def test_missing_scope_does_not_infer_global_metric(self):
        row=json.loads(scenario("missing_scope"))
        self.assertEqual(row["status"],"UNKNOWN");self.assertIsNone(row["value"])
        self.assertIsNone(row["source_ref"]);self.assertEqual(row["freshness"],"unknown")

    def test_explicit_global_scope_populates_global_metric(self):
        group=json.loads(scenario("explicit_global"))
        self.assertEqual(group["global"]["value"],11)
        self.assertEqual(group["global"]["status"],"GREEN")
        self.assertIsInstance(group["global"]["source_ref"],str)
        self.assertEqual(group["global"]["freshness"],"current")
        self.assertEqual(group["by_project"]["Condor"]["value"],3)
        self.assertNotIn("ControlBot",group["by_project"])

    def test_project_slug_is_normalized_and_unknown_project_rejected(self):
        row=json.loads(scenario("project_contract"))
        self.assertEqual(row["metric_value"],7)
        self.assertEqual(row["layer_project"],"BRVTAL")
        self.assertTrue(row["unknown_rejected"])
        self.assertTrue(row["conflict_rejected"])

    def test_ui_does_not_infer_global_from_missing_dimension(self):
        html=scenario("ui_missing_scope")
        self.assertIn("product_business",html)
        self.assertIn("UNKNOWN",html)
        # The project-less layer must not acquire a synthetic GLOBAL dimension label.
        fragment=html.split("product_business",1)[1].split("</article>",1)[0]
        self.assertNotIn("GLOBAL",fragment)

    def test_layer_without_resolvable_evidence_fails_closed(self):
        d=json.loads(scenario("no_evidence"))
        self.assertEqual(d["layer_status"],"UNKNOWN")
        self.assertEqual(d["signal"]["status"],"UNKNOWN")
        self.assertIsNone(d["signal"]["evidence_href"])
        self.assertIsInstance(d["signal"]["source_ref"],str)

    def test_metric_without_resolvable_evidence_fails_closed(self):
        row=json.loads(scenario("no_evidence"))["metric"]
        self.assertEqual(row["status"],"UNKNOWN");self.assertIsNone(row["value"])
        self.assertIsNone(row["evidence_href"]);self.assertIsInstance(row["source_ref"],str)

    def test_resolvable_evidence_preserves_status(self):
        row=json.loads(scenario("valid_evidence"))
        self.assertEqual(row["status"],"AMBER");self.assertEqual(row["value"],13)
        self.assertTrue(row["evidence_href"].startswith("https://github.com/"))

    def test_ui_never_renders_non_unknown_without_evidence_link(self):
        self.assertTrue(json.loads(scenario("ui_invalid_evidence"))["blocked"])

    def test_multi_project_learning_regressions_remain_green(self):
        d=json.loads(scenario("full"))
        self.assertEqual(set(d["metrics"]["lessons_per_week_project"]["by_project"]),{"Condor","ControlBot"})
        self.assertEqual(d["metrics"]["lessons_per_week_project"]["global"]["status"],"UNKNOWN")

    def test_unlinked_recurrence_is_unknown(self):
        row=json.loads(scenario("recurrence"))
        self.assertEqual(row["status"],"UNKNOWN");self.assertIsNone(row["value"])
        self.assertIsNone(row["source_ref"]);self.assertEqual(row["freshness"],"unknown")


    def test_derived_fingerprint_is_structural_not_pii(self):
        d=json.loads(scenario("fingerprint"))
        self.assertEqual(d,{"valid":True,"invalid_blocked":True})

    def test_auto_notices_are_separate_from_real_incidents(self):
        d=json.loads(scenario("full")); real=d["incidents"]["real"]; auto=d["incidents"]["auto_notices"]
        self.assertEqual([x["title"] for x in real],["Database outage"])
        self.assertEqual([x["title"] for x in auto],["[AUTO] CI warning"])
        self.assertTrue(all(not x["title"].startswith("[AUTO]") for x in real))
        self.assertTrue(all(x["title"].startswith("[AUTO]") for x in auto))

if __name__=="__main__": unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"momentum_experiment_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class MomentumExperimentTests(unittest.TestCase):
    def test_experiment_is_venture_and_campaign_scoped(self):
        data=scenario("scope")
        self.assertEqual(data["valid"]["venture_id"],"venture-condor")
        self.assertEqual(data["valid"]["campaign_id"],"campaign:44444444444444444444444444444444")
        self.assertTrue(data["cross_venture"])

    def test_ready_and_running_require_baseline_variants_metric_and_window(self):
        data=scenario("readiness")
        self.assertEqual(data["ready"]["status"],"ready")
        self.assertEqual(data["running"]["status"],"running")
        self.assertTrue(data["missing_baseline"])
        self.assertTrue(data["missing_metric"])
        self.assertTrue(data["missing_window"])

    def test_result_preserves_observed_inferred_unknown(self):
        data=scenario("result")
        self.assertEqual(data["observed"]["result"]["state"],"observed")
        self.assertEqual(data["observed"]["result"]["effect_bps"],750)
        self.assertEqual(data["inferred"]["result"]["state"],"inferred")
        self.assertEqual(data["unknown"]["result"],{
            "state":"unknown","winner_ref":None,"effect_bps":None,"source_ref":None,
            "observed_at":None,"freshness":"unknown","evidence_refs":[],
        })

    def test_invalid_duplicates_windows_extra_fields_and_sensitive_refs_fail_closed(self):
        data=scenario("invalid")
        self.assertTrue(all(data.values()),data)

    def test_representation_is_deterministic(self):
        data=scenario("deterministic")
        self.assertEqual(data["first"],data["second"])
        self.assertEqual(data["first"]["variant_refs"],[
            "creative:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
            "creative:cccccccccccccccccccccccccccccccc",
        ])

    def test_core_has_no_runner_tracking_spend_or_side_effects(self):
        self.assertEqual(scenario("pure")["methods"],["experiment"])
        source=(ROOT/"src"/"MomentumExperiment.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_","http://","https://","factoryrunner","scheduler","traffic allocation","spend("):
            self.assertNotIn(forbidden,source)


if __name__=="__main__":
    unittest.main()

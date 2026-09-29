import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"market_institution_segment_scenarios.php"),name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(run.stdout)


class MarketInstitutionSegmentTests(unittest.TestCase):
    def test_momentum_and_capital_signals_are_market_scoped_and_deterministic(self):
        data=scenario("scoped")
        self.assertEqual(data["projection"],data["reordered"])
        self.assertTrue(data["cross_venture"])
        projection=data["projection"]
        self.assertEqual(
            (projection["venture_id"],projection["market_id"],projection["geography"]),
            ("venture-condor","market-colombia",{"kind":"country","code":"CO"}),
        )
        self.assertEqual(
            [item["institution"] for item in projection["signals"]],
            ["capital","momentum"],
        )
        for item in projection["signals"]:
            self.assertEqual(item["market_id"],"market-colombia")
            self.assertEqual(item["geography"],{"kind":"country","code":"CO"})

    def test_scope_namespace_duplicates_and_extra_fields_fail_closed(self):
        data=scenario("invalid")
        self.assertTrue(all(data.values()))

    def test_multiple_markets_remain_distinct_and_global_never_expands_countries(self):
        data=scenario("markets")
        self.assertEqual(data["country"]["market_id"],"market-colombia")
        self.assertEqual(data["mexico"]["market_id"],"market-mexico")
        self.assertEqual(data["mexico"]["geography"],{"kind":"country","code":"MX"})
        self.assertEqual(data["global"]["market_id"],"market-global")
        self.assertEqual(data["global"]["geography"],{"kind":"global","code":None})
        self.assertEqual(data["global"]["signals"][0]["geography"],{"kind":"global","code":None})
        self.assertNotIn("countries",data["global"]["signals"][0]["geography"])

    def test_evidence_and_freshness_are_preserved_without_invention(self):
        data=scenario("freshness")
        self.assertTrue(data["unknown_with_time"])
        self.assertTrue(data["known_without_time"])
        signals={item["institution"]:item for item in data["projection"]["signals"]}
        self.assertEqual(signals["capital"]["freshness"],"unknown")
        self.assertIsNone(signals["capital"]["observed_at"])
        self.assertEqual(signals["capital"]["evidence_refs"],[])
        self.assertEqual(signals["momentum"]["freshness"],"stale")
        self.assertEqual(signals["momentum"]["observed_at"],1500)
        self.assertEqual(
            signals["momentum"]["evidence_refs"],
            [
                "controlbot:evidence/0123456789abcdef0123456789abcdef",
                "controlbot:evidence/22222222222222222222222222222222",
            ],
        )

    def test_projection_carries_no_authority_budget_or_domain_payload(self):
        data=scenario("authority")
        self.assertTrue(all(data["rejected"].values()))
        serialized=json.dumps(data["projection"],sort_keys=True).lower()
        for forbidden in (
            "authority","policy","approval","budget","spend","forecast",
            "revenue","campaign_payload","decision",
        ):
            self.assertNotIn(forbidden,serialized)

    def test_contract_has_no_persistence_engines_workitems_or_parallel_queue(self):
        data=scenario("pure")
        self.assertEqual(data["methods"],["project"])
        self.assertEqual(data["projection"]["signals"],[])
        source=(ROOT/"src"/"MarketInstitutionSegment.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "curl_","http://","https://","mysqli","pdo(","setcookie","localstorage",
            "factoryrunner","scheduler","workitem","provider","persist","queue",
        ):
            self.assertNotIn(forbidden,source)


if __name__=="__main__":
    unittest.main()

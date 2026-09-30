import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SCENARIO=ROOT/"tests/factory_live_product_costs_scenarios.php"

def scenario(name):
    result=subprocess.run(
        ["php",str(SCENARIO),name],
        cwd=ROOT,check=True,text=True,capture_output=True,timeout=30,
    )
    return result.stdout.strip()

class FactoryLiveProductCostsTests(unittest.TestCase):
    def test_product_and_analytics_keep_provenance(self):
        rows=json.loads(scenario("full"))["product_analytics"]
        self.assertEqual({r["department"] for r in rows},{"product","data_analytics"})
        for row in rows:
            self.assertEqual(row["project"],"ControlBot")
            self.assertEqual(row["source_ref"],"github:pl0n3r/ControlBot#566")
            self.assertEqual(row["observed_at"],2990)
            self.assertEqual(row["freshness"],"current")
            self.assertEqual(row["age_seconds"],10)
        self.assertEqual(next(r for r in rows if r["department"]=="product")["value"],0)

    def test_missing_cost_source_is_unknown(self):
        view=json.loads(scenario("missing"))
        self.assertEqual(view["product_analytics"],[])
        self.assertEqual(view["costs"],{"status":"unknown","items":[]})
        self.assertEqual(view["limits"],{"status":"unknown","items":[]})
        self.assertEqual(view["tool_usage"]["status"],"unknown")

    def test_secret_values_are_rejected(self):
        self.assertTrue(json.loads(scenario("secret"))["blocked"])

    def test_zero_is_not_missing(self):
        view=json.loads(scenario("zero"))
        self.assertEqual(view["tool_usage"]["status"],"measured")
        self.assertEqual(view["tool_usage"]["used"],0)
        self.assertEqual(view["tool_usage"]["limit"],10000)
        self.assertEqual(view["limits"]["status"],"measured")
        self.assertEqual(view["limits"]["items"][0]["used"],0)

    def test_tool_usage_stays_separate_from_money(self):
        view=json.loads(scenario("full"))
        self.assertEqual(view["tool_usage"]["status"],"measured")
        self.assertEqual(view["costs"],{"status":"unknown","items":[]})
        self.assertNotIn("amount_minor",view["tool_usage"])

    def test_partial_or_incoherent_usage_is_unknown(self):
        for name in ("partial_usage","over_limit"):
            view=json.loads(scenario(name))
            self.assertEqual(view["tool_usage"]["status"],"unknown")
            self.assertEqual(view["limits"],{"status":"unknown","items":[]})

    def test_ui_is_optional_and_renders_unknown_costs(self):
        html=scenario("ui")
        self.assertIn('data-section="product_analytics_costs"',html)
        self.assertIn("Producto y analítica",html)
        self.assertIn("Costes medidos",html)
        self.assertIn("Uso / límite",html)
        self.assertIn("UNKNOWN",html)

if __name__=="__main__":
    unittest.main()

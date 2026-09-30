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
        row=json.loads(scenario("full"))["product_analytics"][0]
        self.assertEqual(row["status"],"measured")
        self.assertEqual(row["source_ref"],"aggregate:analytics/activation")
        self.assertEqual(row["evidence_ref"],"evidence:product/activation-2026w40")
        self.assertEqual(row["observed_at"],2000)
        self.assertEqual(row["freshness"],"fresh")

    def test_missing_cost_source_is_unknown(self):
        view=json.loads(scenario("missing_cost"))
        self.assertEqual(view["costs"],{"status":"unknown","items":[]})
        self.assertEqual(view["limits"],{"status":"unknown","items":[]})

    def test_secret_values_are_rejected(self):
        result=json.loads(scenario("secret"))
        self.assertTrue(result["product_secret_rejected"])
        self.assertTrue(result["finance_secret_rejected"])

    def test_zero_is_not_missing(self):
        costs=json.loads(scenario("zero_cost"))["costs"]
        self.assertEqual(costs["status"],"measured")
        self.assertEqual(costs["items"][0]["amount_minor"],0)
        self.assertEqual(costs["items"][0]["currency"],"COP")

    def test_tool_usage_stays_separate_from_money(self):
        view=json.loads(scenario("full"))
        self.assertEqual(view["tool_usage"]["status"],"measured")
        self.assertEqual(view["tool_usage"]["data"]["usage"],4)
        self.assertNotIn("amount_minor",view["tool_usage"]["data"])

    def test_ui_is_optional_and_renders_unknowns(self):
        html=scenario("ui")
        self.assertIn('data-section="product_analytics_costs"',html)
        self.assertIn("Producto y analítica",html)
        self.assertIn("Costes medidos",html)
        self.assertIn("Límites",html)
        self.assertIn("UNKNOWN",html)

if __name__=="__main__":
    unittest.main()

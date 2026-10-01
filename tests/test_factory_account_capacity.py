import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SCENARIO=ROOT/"tests/factory_account_capacity_scenarios.php"
SOURCE=ROOT/"src/FactoryAccountCapacitySnapshot.php"
UI=ROOT/"src/FactoryLiveUi.php"

def scenario(name):
    result=subprocess.run(
        ["php",str(SCENARIO),name],cwd=ROOT,check=True,text=True,
        capture_output=True,timeout=30,
    )
    return result.stdout.strip()

class FactoryAccountCapacityTests(unittest.TestCase):
    def test_projection_keeps_account_budget_usage_limits_and_provenance(self):
        view=json.loads(scenario("full"))
        self.assertEqual(view["status"],"FRESH")
        self.assertEqual([x["accountAlias"] for x in view["accounts"]],["dsn-monitor","token_bucket"])
        for row in view["accounts"]:
            self.assertEqual(row["budget"]["limit"],10)
            self.assertEqual(row["sent"],4);self.assertEqual(row["remaining"],6)
            self.assertEqual(row["limitEvents"],2)
            self.assertEqual(row["source_ref"],"github:pl0n3r/Factory#584")
            self.assertEqual(row["freshness"],"current");self.assertEqual(row["age_seconds"],10)

    def test_closed_contract_rejects_identifying_incoherent_and_future_evidence(self):
        result=json.loads(scenario("invalid"))
        self.assertTrue(result);self.assertTrue(all(result.values()))

    def test_unknown_and_stale_capacity_never_claim_available_quota(self):
        view=json.loads(scenario("unknown_stale"))
        self.assertEqual(view["status"],"UNKNOWN")
        rows={x["accountAlias"]:x for x in view["accounts"]}
        self.assertIsNone(rows["unknown"]["remaining"]);self.assertIsNone(rows["unknown"]["budget"])
        self.assertEqual(rows["unknown"]["freshness"],"unknown")
        self.assertIsNone(rows["stale"]["remaining"]);self.assertEqual(rows["stale"]["freshness"],"stale")
        self.assertEqual(json.loads(scenario("empty")),{
            "version":1,"observed_at":3000,"status":"UNKNOWN","accounts":[],
            "fingerprint":json.loads(scenario("empty"))["fingerprint"],
        })

    def test_live_factory_ui_renders_read_only_secret_free_account_capacity(self):
        html=scenario("ui")
        self.assertIn('data-section="account_capacity"',html)
        self.assertIn("Capacidad de cuentas",html);self.assertIn("primary",html)
        self.assertIn("4 / 10",html);self.assertIn("Restante: 6",html)
        self.assertIn("Eventos de límite: 2",html)
        self.assertIn("github:pl0n3r/Factory#584",html)
        self.assertIn("UNKNOWN",scenario("ui_unknown"))
        self.assertTrue(json.loads(scenario("ui_tamper"))["blocked"])
        self.assertTrue(all(json.loads(scenario("ui_recomputed_tamper")).values()))
        source=(SOURCE.read_text()+UI.read_text()).lower()
        for token in ("curl_","file_get_contents","fsockopen","entitymanager","pdo","'post'","'patch'","'delete'"):
            self.assertNotIn(token,source)

    def test_projection_and_ui_are_deterministic_mobile_first(self):
        self.assertEqual(json.loads(scenario("full")),json.loads(scenario("reverse")))
        html=scenario("ui")
        for token in ('name="viewport"',"grid-template-columns:1fr","@media(min-width:760px)",":focus-visible"):
            self.assertIn(token,html)

if __name__=="__main__": unittest.main()

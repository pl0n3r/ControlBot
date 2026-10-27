"""ControlBot #977: synthetic-only, fail-closed executable contract for #750."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    completed = subprocess.run(
        ["php", str(ROOT / "tests" / "definition_of_done_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, check=True,
    )
    return json.loads(completed.stdout)


class Cb750DodTrackerTests(unittest.TestCase):
    def test_contrato_principal(self):
        cases = scenario("main")
        ids = cases["ids"]
        self.assertEqual(set(ids), {"product_v1", "live", "terminal_acceptance"})
        self.assertEqual([len(ids[k]) for k in ids], [8, 7, 6])
        self.assertEqual(len(set(sum(ids.values(), []))), 21)
        self.assertEqual(cases["all_pass"]["overall"], "DONE")
        self.assertEqual(cases["failed"]["overall"], "NOT_DONE")
        self.assertEqual(cases["unknown"]["overall"], "UNKNOWN")
        self.assertEqual(cases["missing"]["overall"], "UNKNOWN")
        self.assertEqual(len(cases["all_pass"]["criteria"]), 21)
        self.assertTrue(all(r["state"] == "DONE" for r in cases["all_pass"]["criteria"]))
        self.assertEqual(cases["failed"]["criteria"][0]["state"], "NOT_DONE")
        self.assertEqual(cases["unknown"]["criteria"][0]["state"], "UNKNOWN")
        self.assertEqual(cases["missing"]["criteria"][0]["reason"], "missing_evidence")

    def test_falla_cerrado(self):
        cases = scenario("invalid")
        self.assertEqual(len(cases), 8)
        for name, response in cases.items():
            with self.subTest(name=name):
                self.assertEqual(response["overall"], "UNKNOWN")
                self.assertEqual(len(response["criteria"]), 21)
                self.assertTrue(all(x["state"] == "UNKNOWN" for x in response["criteria"]))
                self.assertTrue(all(x["reason"] == "invalid_or_ambiguous_input" for x in response["criteria"]))

    def test_sin_datos_reales(self):
        for group in (scenario("main"), scenario("invalid"), scenario("freshness")):
            for value in group.values():
                if not isinstance(value, dict) or "criteria" not in value:
                    continue
                self.assertFalse(value["execution"])
                self.assertFalse(value["can_go_live"])
                self.assertFalse(value["real_world_evidence"])
                self.assertEqual(value["source"], "synthetic_only")
                payload = json.dumps(value, ensure_ascii=True)
                for forbidden in ("person@example.com", "secret-example", "https://", "token", "password"):
                    self.assertNotIn(forbidden, payload)
                self.assertEqual(set(value), {"version", "source", "overall", "criteria", "can_go_live", "execution", "real_world_evidence"})
                self.assertTrue(all(set(r) == {"id", "group", "state", "reason"} for r in value["criteria"]))
        self.assertFalse(scenario("main")["all_pass"]["can_go_live"])

    def test_estados_unknown_explicitos(self):
        cases = scenario("freshness")
        for name in ("stale", "future", "unknown", "type_confusion"):
            with self.subTest(name=name):
                self.assertEqual(cases[name]["overall"], "UNKNOWN")
                self.assertEqual(cases[name]["criteria"][0]["state"], "UNKNOWN")
        self.assertEqual(cases["stale"]["criteria"][0]["reason"], "stale_or_future_evidence")
        self.assertEqual(cases["future"]["criteria"][0]["reason"], "stale_or_future_evidence")
        self.assertEqual(cases["unknown"]["criteria"][0]["reason"], "unverified")
        self.assertEqual(cases["type_confusion"]["criteria"][0]["reason"], "invalid_or_ambiguous_input")
        self.assertEqual(cases["boundary"]["criteria"][0]["state"], "DONE")
        self.assertEqual(cases["boundary"]["overall"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()

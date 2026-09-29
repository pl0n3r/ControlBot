import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"product_discovery_decision_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode or r.stderr.strip(): raise AssertionError(r.stderr.strip() or f"{name} failed")
    return json.loads(r.stdout)
class ProductDiscoveryDecisionTests(unittest.TestCase):
    def test_decision_binds_exactly_to_assessment(self):
        self.assertTrue(all(scenario("bindings").values()))
    def test_decision_catalog_reason_and_evidence_are_explicit(self):
        d=scenario("catalog")
        self.assertEqual([d[k] for k in ("build","iterate","park","stop","research_more")],["BUILD","ITERATE","PARK","STOP","RESEARCH_MORE"])
        self.assertTrue(all(d[k] for k in ("invalid","reason","empty_evidence","duplicate")))
    def test_build_requires_validated_and_is_never_inferred(self):
        d=scenario("build"); self.assertEqual(d["validated"]["decision"],"BUILD")
        self.assertTrue(d["invalidated_reject"]); self.assertTrue(d["inconclusive_reject"])
        self.assertEqual(d["validated_not_inferred"],"ITERATE")
    def test_build_keeps_authority_budget_aegis_lex_pending(self):
        d=scenario("gates"); b=d["build"]
        self.assertEqual(b["required_gates"],["authority","budget","aegis","lex"])
        self.assertFalse(b["factory_handoff_ready"]); self.assertFalse(b["execution"]); self.assertTrue(d["self_certify"])
    def test_non_build_preserves_scope_evidence_without_workitem(self):
        for row in scenario("nonbuild").values():
            self.assertEqual(row["required_gates"],[]); self.assertFalse(row["factory_handoff_ready"]); self.assertFalse(row["execution"])
            self.assertEqual((row["venture_id"],row["product_id"]),("venture-alpha","product-alpha"))
            self.assertEqual(row["classification"],"INVALIDATED"); self.assertEqual(row["freshness"],"fresh")
    def test_contract_is_closed_deterministic_secret_free_and_non_executing(self):
        d=scenario("schema"); self.assertTrue(d["extra"]); self.assertTrue(d["deterministic"]); self.assertEqual(d["sorted"],sorted(d["sorted"]))
        self.assertFalse(d["valid"]["execution"])
        source=d["source"].lower()
        for value in ("new pdo","mysqli","curl_","http://","https://","file_put_contents","fopen(","workitem","factoryrunner","dispatchworkflow","enqueue("):
            self.assertNotIn(value,source)
        serialized=json.dumps(d["valid"]).lower()
        for value in ("password","bearer ","authorization","@"): self.assertNotIn(value,serialized)
if __name__=="__main__": unittest.main()

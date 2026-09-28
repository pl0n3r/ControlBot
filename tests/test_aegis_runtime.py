import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SCENARIO=ROOT/"tests"/"aegis_runtime_scenarios.php"

def scenario(name):
    r=subprocess.run(["php",str(SCENARIO),name],cwd=ROOT,text=True,capture_output=True,timeout=20)
    if r.returncode: raise AssertionError(r.stdout+"\n"+r.stderr)
    return json.loads(r.stdout)

class AegisRuntimeTests(unittest.TestCase):
    def test_remediable_finding_uses_canonical_factory_work_item(self):
        d=scenario("workitem"); i=d["work_item"]
        self.assertEqual(("automatic","aegis","infrastructure"),(i["origin_mode"],i["origin_system"],i["work_type"]))
        self.assertEqual(["infrastructure_remediation"],i["requested_capabilities"])
        self.assertEqual(["infraestructura","sre"],i["required_roles"])
        self.assertEqual("l0_ai_autonomous",i["authority_level"])
        self.assertEqual("fresh",d["source_freshness"]); self.assertTrue(d["ready_hint"])
        self.assertNotIn("freshness",i); self.assertNotIn("provider",i); self.assertNotIn("executor",i)

    def test_factory_handoff_preserves_security_provenance_without_secrets(self):
        i=scenario("workitem")["work_item"]
        self.assertEqual(("high","high"),(i["severity"],i["priority_class"]))
        self.assertEqual("controlbot:policy/aegis-safe-remediation",i["policy_ref"])
        self.assertEqual(["controlbot:aegis/finding-worker-down"],i["evidence_refs"])
        self.assertRegex(i["idempotency_key"],r"^aegis:[0-9a-f]{64}$")
        self.assertRegex(i["work_id"],r"^aegis-work:[0-9a-f]{32}$")
        identity=scenario("identity")
        self.assertNotEqual(identity["first"]["work_item"]["idempotency_key"],identity["second"]["work_item"]["idempotency_key"])
        self.assertNotEqual(identity["first"]["work_item"]["work_id"],identity["second"]["work_item"]["work_id"])
        self.assertTrue(scenario("secret")["rejected"])
        self.assertTrue(scenario("timestamp")["rejected"])

    def test_stale_or_unknown_evidence_is_never_ready(self):
        d=scenario("stale")
        for key in ("stale","unknown"):
            self.assertFalse(d[key]["ready_hint"])
            self.assertEqual(key,d[key]["source_freshness"])
            self.assertEqual(key,d[key]["living_feedback"]["freshness"])

    def test_owner_required_path_never_auto_executes(self):
        d=scenario("owner")
        self.assertFalse(d["ready_hint"]); self.assertFalse(d["owner_gate"]["auto_execute"])
        self.assertEqual(d["owner_gate"]["gate_ref"],d["work_item"]["approval_ref"])
        self.assertIn("risk_legal",d["owner_gate"]["reason_codes"])

    def test_living_feedback_is_sanitized_idempotent_and_non_authoritative(self):
        f=scenario("living")["living_feedback"]
        self.assertEqual(("lesson_candidate","none","none"),(f["kind"],f["authority_effect"],f["policy_effect"]))
        self.assertRegex(f["candidate_id"],r"^aegis-feedback:[0-9a-f]{64}$")
        self.assertRegex(f["idempotency_key"],r"^living:[0-9a-f]{64}$")
        s=json.dumps(f).lower()
        for word in ("password","secret","token","credential"): self.assertNotIn(word,s)

    def test_duplicate_findings_do_not_duplicate_work_items(self):
        d=scenario("dedupe"); self.assertEqual(1,len(d))
        self.assertRegex(d[0]["work_item"]["idempotency_key"],r"^aegis:[0-9a-f]{64}$")

    def test_finding_to_evidence_flow_is_deterministic(self):
        a,b=scenario("verified"),scenario("verified")
        self.assertEqual(a,b); self.assertEqual("success",a["verification"]["state"]); self.assertTrue(a["verification"]["verified"])
        self.assertEqual(["controlbot:aegis/verification-worker"],a["verification"]["evidence_refs"])
        self.assertIn("controlbot:aegis/verification-worker",a["work_item"]["evidence_refs"])

if __name__=="__main__": unittest.main()

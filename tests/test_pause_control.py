import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(script, name):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / script), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=60,
    )
    return json.loads(run.stdout)


def pause(name):
    return scenario("pause_control_scenarios.php", name)


def production(name):
    return scenario("pause_production_gate_scenarios.php", name)


def audit(name):
    return scenario("pause_transition_audit_scenarios.php", name)


def scheduler(name):
    return scenario("scheduler_core_scenarios.php", name)


def resume(name):
    return scenario("pause_resume_plan_scenarios.php", name)


def resume_fixture_inputs():
    source=(ROOT/"tests"/"pause_resume_plan_scenarios.php").read_text(encoding="utf-8")
    prefix=source.split("$scenario=$argv[1]??'';",1)[0]
    probe=prefix+"\necho json_encode(['work'=>work(),'order'=>order()],JSON_THROW_ON_ERROR),PHP_EOL;\n"
    path=None
    try:
        with tempfile.NamedTemporaryFile("w",suffix=".php",dir=ROOT/"tests",delete=False,encoding="utf-8") as tmp:
            tmp.write(probe); path=Path(tmp.name)
        run=subprocess.run(["php",str(path)],cwd=ROOT,check=True,text=True,capture_output=True,timeout=60)
        return json.loads(run.stdout)
    finally:
        if path is not None: path.unlink(missing_ok=True)


class PauseControlTests(unittest.TestCase):
    def test_project_freeze_blocks_mutations_but_allows_authorized_readonly(self):
        data = production("project")
        self.assertTrue(data["write"]["pause_blocked"])
        self.assertFalse(data["write"]["pause_allows"])
        self.assertFalse(data["read"]["pause_blocked"])
        self.assertTrue(data["read"]["pause_allows"])
        self.assertEqual(data["read"]["reason"], "typed_read_during_pause")

    def test_global_pause_stops_new_mutating_assignments_within_sla(self):
        global_pause = pause("scopes")["global"]
        readiness = scheduler("readiness")["freeze"]
        self.assertTrue(global_pause["blocked"])
        self.assertFalse(global_pause["mutation_allowed"])
        self.assertFalse(readiness["ready"])
        self.assertIn("freeze_active", readiness["reasons"])

    def test_active_sessions_respect_preemptibility_and_safe_points(self):
        data = pause("preemptibility")
        self.assertTrue(data["non_preemptible_missing"])
        self.assertTrue(data["safe_point_missing"])
        self.assertEqual(data["non_preemptible"]["safe_point_at"], 105)
        self.assertIsNone(data["immediate"]["safe_point_at"])

    def test_pause_scopes_compose_deterministically(self):
        data = pause("precedence")
        self.assertEqual(data["forward"], data["reverse"])
        self.assertEqual(data["forward"]["effective_scope"], "global")
        self.assertEqual(data["without_global"]["effective_scope"], "project")

    def test_resume_does_not_duplicate_workitems_or_orders(self):
        release = pause("release")
        fencing = scheduler("fencing")
        plan = resume("valid")
        replay = resume("replay")
        drift = resume("drift")
        deterministic = resume("deterministic")
        self.assertTrue(release["idempotent"])
        self.assertFalse(release["effective"]["blocked"])
        self.assertTrue(fencing["ready"]["ready"])
        self.assertTrue(fencing["double_owner"])
        self.assertIn("incompatible_reservation", fencing["conflict"]["reasons"])
        self.assertFalse(plan["create_work_item"])
        self.assertFalse(plan["create_order"])
        self.assertTrue(plan["reuse_current_attempt"])
        inputs=resume_fixture_inputs()
        for field in ("work_item_id","generation","attempt"):
            self.assertEqual(plan[field],inputs["work"][field])
        for field in ("order_id","attempt_id","generation","attempt","runner_id","capability","scope","issued_at","expires_at","instruction_ref"):
            self.assertEqual(plan[field],inputs["order"][field])
        self.assertEqual(plan["fingerprint"],resume("valid")["fingerprint"])
        self.assertTrue(all(drift.values()))
        self.assertTrue(deterministic["same"])
        self.assertTrue(deterministic["fingerprint"])
        self.assertEqual(deterministic["hits"], [])
        self.assertTrue(replay["exact"])
        self.assertTrue(replay["conflict"])

    def test_unfreeze_does_not_expand_capabilities_or_bypass_gates(self):
        data = production("release")
        self.assertTrue(data["active"]["pause_blocked"])
        self.assertFalse(data["released"]["pause_blocked"])
        self.assertTrue(data["released"]["pause_allows"])
        for row in data.values():
            self.assertTrue(row["requires_existing_authority"])
            self.assertEqual(row["authorization"], "not_granted")

    def test_automatic_freeze_requires_versioned_policy_and_incident_evidence(self):
        data = pause("policy")
        self.assertEqual(data["valid"]["source"], "policy")
        self.assertEqual(data["valid"]["policy_version"], "policy:v1")
        self.assertEqual(data["valid"]["incident_id"], "incident:330")
        self.assertEqual(data["valid"]["evidence_ref"], "controlbot:evidence/330")
        self.assertTrue(all(data["missing"].values()))

    def test_transitions_are_audited_idempotently(self):
        transition = audit("transition")
        appended = audit("append")
        self.assertTrue(transition["same_create"])
        self.assertEqual(transition["release"]["before_state"], "active")
        self.assertEqual(transition["release"]["after_state"], "releasing")
        self.assertEqual(appended["count"], 2)
        self.assertTrue(appended["replay_same"])
        self.assertTrue(appended["conflict"])


if __name__ == "__main__":
    unittest.main()

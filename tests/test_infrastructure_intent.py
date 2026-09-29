import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "infrastructure_intent_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(run.stdout)


class InfrastructureIntentTests(unittest.TestCase):
    def test_safe_intent_reuses_authority_budget_and_factory_work_item(self):
        data = scenario("safe")
        self.assertEqual(data["status"], "planned")
        self.assertFalse(data["execution"])
        self.assertEqual(data["authority"]["decision"], "allow")
        self.assertEqual(data["authority"]["policy"]["decision"], "automatic")
        self.assertIsNone(data["capital"])

        work = data["work_item"]
        self.assertEqual(
            set(work),
            {
                "work_id",
                "origin_mode",
                "origin_system",
                "group_id",
                "work_type",
                "requested_capabilities",
                "required_roles",
                "authority_level",
                "producer_ref",
                "priority_class",
                "depends_on",
                "claims",
                "policy_ref",
                "evidence_refs",
                "idempotency_key",
                "venture_id",
                "project_id",
                "repository_ref",
            },
        )
        self.assertEqual(work["origin_system"], "controlbot")
        self.assertEqual(work["work_type"], "infrastructure")
        self.assertEqual(work["requested_capabilities"], ["hostinger.read"])
        self.assertEqual(work["priority_class"], "high")
        self.assertEqual(work["repository_ref"], "pl0n3r/ControlBot")
        self.assertEqual(
            work["required_roles"],
            [
                "arquitectura",
                "infraestructura",
                "ingenieria-software",
                "qa",
                "seguridad",
                "sre",
            ],
        )
        for forbidden in ("state", "executor", "provider", "model", "runner_id"):
            self.assertNotIn(forbidden, work)

    def test_high_risk_or_over_budget_intent_fails_closed_to_decision(self):
        data = scenario("gates")
        for key in ("high", "authority", "over_budget"):
            row = data[key]
            self.assertEqual(row["status"], "owner_decision_required", (key, row))
            self.assertFalse(row["execution"])
            self.assertIsNotNone(row["work_item"])
            self.assertIsNone(row["runner_request"])
            self.assertIn("factory-human-gate", row["owner_decision_gate"])
            self.assertIn('"safe_default":"B"', row["owner_decision_gate"])

        self.assertIn("high_blast_radius", data["high"]["reasons"])
        self.assertIn("venture_access_owner_required", data["authority"]["reasons"])
        self.assertIn("budget_limit_exceeded", data["over_budget"]["reasons"])

        for key in ("unknown", "scope_mismatch"):
            row = data[key]
            self.assertEqual(row["status"], "denied", (key, row))
            self.assertFalse(row["execution"])
            self.assertIsNone(row["work_item"])
            self.assertIsNone(row["runner_request"])
            self.assertIsNone(row["owner_decision_gate"])

    def test_runner_payload_uses_typed_refs_without_secrets(self):
        data = scenario("runner")
        request = data["safe"]["runner_request"]
        self.assertEqual(
            set(request),
            {
                "version",
                "work_item_id",
                "capability",
                "scope",
                "instruction_ref",
                "evidence_refs",
                "verify_after_write_ref",
            },
        )
        for forbidden in ("runner_id", "provider", "model", "executor"):
            self.assertNotIn(forbidden, request)

        order = data["order"]
        self.assertEqual(order["work_item_id"], request["work_item_id"])
        self.assertEqual(order["capability"], request["capability"])
        self.assertEqual(
            order["runner_id"],
            "33333333-3333-7333-8333-333333333333",
        )
        self.assertTrue(data["instruction_secret_rejected"])
        self.assertTrue(data["authority_secret_rejected"])

        serialized = json.dumps(data["safe"]).lower()
        for forbidden in ("password", "bearer ", "github_pat_", "private key"):
            self.assertNotIn(forbidden, serialized)

    def test_mutating_intent_requires_plan_rollback_and_verification_contract(self):
        data = scenario("mutation")
        valid = data["valid"]
        self.assertEqual(valid["status"], "planned")
        self.assertTrue(valid["intent"]["mutating"])
        self.assertTrue(valid["authority"]["policy"]["requires_backup"])
        self.assertEqual(
            valid["evidence_contract"]["verify_ref"],
            "controlbot:verify/post-write-189",
        )
        self.assertEqual(
            valid["evidence_contract"]["safe_point_ref"],
            "controlbot:backup/rollback-189",
        )
        self.assertIsNotNone(valid["runner_request"])

        for key in (
            "missing_plan_rejected",
            "missing_verify_rejected",
            "missing_recovery_rejected",
            "read_mutation_rejected",
        ):
            self.assertTrue(data[key], (key, data))

        self.assertEqual(data["missing_backup"]["status"], "denied")
        self.assertIn(
            "backup_required_without_safe_point",
            data["missing_backup"]["reasons"],
        )

        self.assertEqual(data["weak_capability"]["status"], "denied")
        self.assertIn(
            "mutating_capability_too_weak",
            data["weak_capability"]["reasons"],
        )
        self.assertIsNone(data["weak_capability"]["runner_request"])

        self.assertEqual(data["readonly_write"]["status"], "denied")
        self.assertIn(
            "readonly_intent_with_write_capability",
            data["readonly_write"]["reasons"],
        )

        self.assertEqual(data["cost_unknown"]["status"], "denied")
        self.assertIn(
            "financial_evidence_unknown",
            data["cost_unknown"]["reasons"],
        )


    def test_owner_decision_materializes_approval_gate_without_runner(self):
        data = scenario("gates")
        for key in ("high", "authority", "over_budget"):
            row = data[key]
            self.assertEqual(row["status"], "owner_decision_required")
            self.assertIsNone(row["runner_request"])
            approval = row["work_item"]["approval_ref"]
            self.assertEqual(approval, f"controlbot:approval/infra-{row['intent']['intent_id']}")
            self.assertIn(f'"approval_ref":"{approval}"', row["owner_decision_gate"])
            factory_payload = row["owner_decision_gate"].split(
                "<!-- factory-human-gate ", 1
            )[1].split(" -->", 1)[0]
            self.assertNotIn("approval_ref", json.loads(factory_payload))

        self.assertNotEqual(
            data["high"]["work_item"]["approval_ref"],
            "controlbot:approval/caller-supplied",
        )

    def test_planned_work_does_not_invent_approval_ref(self):
        work = scenario("safe")["work_item"]
        self.assertNotIn("approval_ref", work)

    def test_factory_roles_use_catalog_slugs(self):
        self.assertEqual(
            scenario("safe")["work_item"]["required_roles"],
            ["arquitectura", "infraestructura", "ingenieria-software", "qa", "seguridad", "sre"],
        )

    def test_hard_deny_dominates_owner_escalation(self):
        row = scenario("gates")["deny_owner"]
        self.assertEqual(row["status"], "denied")
        self.assertIn("authority_scope_mismatch", row["reasons"])
        self.assertIsNone(row["work_item"])
        self.assertIsNone(row["runner_request"])
        self.assertIsNone(row["owner_decision_gate"])

    def test_predispatch_request_has_no_runner_identity(self):
        request = scenario("safe")["runner_request"]
        self.assertNotIn("runner_id", request)
        self.assertNotIn("order_id", request)
        self.assertNotIn("attempt_id", request)

    def test_slice_stays_under_size_budget(self):
        event_path = Path(__import__("os").environ.get("GITHUB_EVENT_PATH", ""))
        if not event_path.is_file():
            self.skipTest("PR diff budget is enforced from GitHub pull_request metadata.")
        event = json.loads(event_path.read_text(encoding="utf-8"))
        pull_request = event.get("pull_request")
        if not isinstance(pull_request, dict):
            self.skipTest("PR diff budget only applies to pull_request events.")
        changed = int(pull_request["additions"]) + int(pull_request["deletions"])
        self.assertLessEqual(changed, 400)


if __name__ == "__main__":
    unittest.main()

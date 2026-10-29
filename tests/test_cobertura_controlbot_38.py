"""CB-0610..CB-0622: conductas de InfrastructureIntent (datos sintéticos).

CB-0623 es una limitación documental C: no acredita un despliegue real.
La suite usa los escenarios PHP existentes que invocan las clases de producción.
"""

import json
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


@lru_cache(maxsize=8)
def exercise(script: str, scenario: str) -> dict:
    assert script in {"infrastructure_intent_scenarios.php", "capability_policy_scenarios.php"}
    proc = subprocess.run(
        ["php", str(ROOT / "tests" / script), scenario],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=30,
    )
    return json.loads(proc.stdout)


def infra(name: str) -> dict:
    return exercise("infrastructure_intent_scenarios.php", name)


class CoberturaControlbot38Tests(unittest.TestCase):
    def test_contrato_principal(self):
        safe = infra("safe")
        runner = infra("runner")
        gates = infra("gates")
        mutation = infra("mutation")
        provenance = infra("provenance")

        with self.subTest(row="CB-0610"):
            self.assertEqual(safe["authority"]["decision"], "allow")
            self.assertEqual(safe["status"], "planned")
            self.assertEqual(runner["order"]["work_item_id"], safe["work_item"]["work_id"])
            self.assertFalse(safe["execution"])

        with self.subTest(row="CB-0611"):
            self.assertEqual(provenance["trusted_allow"]["authority"]["decision"], "allow")
            self.assertEqual(provenance["trusted_owner"]["status"], "owner_decision_required")
            self.assertEqual(provenance["trusted_deny"]["status"], "denied")
            self.assertTrue(provenance["fabricated_array_rejected"])

        with self.subTest(row="CB-0612"):
            policy = exercise("capability_policy_scenarios.php", "unknown")
            self.assertEqual(policy["restricted"]["decision"], "owner_required")
            self.assertEqual(policy["unknown"]["decision"], "forbidden")
            self.assertEqual(safe["authority"]["policy"]["decision"], "automatic")

        with self.subTest(row="CB-0613"):
            self.assertTrue(mutation["valid"]["authority"]["policy"]["requires_backup"])
            self.assertIsNotNone(mutation["valid"]["evidence_contract"]["safe_point_ref"])
            self.assertEqual(mutation["missing_backup"]["status"], "denied")
            self.assertIn("backup_required_without_safe_point", mutation["missing_backup"]["reasons"])

        with self.subTest(row="CB-0614"):
            for key in ("unknown", "scope_mismatch", "deny_owner"):
                self.assertEqual(gates[key]["status"], "denied")
                self.assertIsNone(gates[key]["runner_request"])
            self.assertEqual(mutation["cost_unknown"]["status"], "denied")

        with self.subTest(row="CB-0615"):
            self.assertEqual(gates["over_budget"]["status"], "owner_decision_required")
            self.assertIn("budget_limit_exceeded", gates["over_budget"]["reasons"])
            self.assertIsNone(gates["over_budget"]["runner_request"])

        with self.subTest(row="CB-0616"):
            self.assertEqual(gates["high"]["status"], "owner_decision_required")
            self.assertEqual(gates["unknown"]["status"], "denied")
            self.assertTrue(mutation["read_mutation_rejected"])
            self.assertIn("high_blast_radius", gates["high"]["reasons"])

        with self.subTest(row="CB-0617"):
            work = safe["work_item"]
            self.assertEqual(work["work_type"], "infrastructure")
            self.assertEqual(work["requested_capabilities"], ["hostinger.read"])
            for required in ("work_id", "group_id", "required_roles", "authority_level",
                             "depends_on", "claims", "policy_ref", "evidence_refs",
                             "idempotency_key", "producer_ref", "priority_class"):
                self.assertIn(required, work)
            for forbidden in ("runner_id", "state", "executor", "provider"):
                self.assertNotIn(forbidden, work)

        with self.subTest(row="CB-0618"):
            request = safe["runner_request"]
            self.assertEqual(request["work_item_id"], safe["work_item"]["work_id"])
            self.assertEqual(request["scope"], safe["intent"]["scope"])
            self.assertIn("verify_after_write_ref", request)
            self.assertNotIn("runner_id", request)

        with self.subTest(row="CB-0619"):
            order = runner["order"]
            self.assertEqual(order["runner_id"], "33333333-3333-7333-8333-333333333333")
            self.assertEqual(order["capability"], runner["safe"]["runner_request"]["capability"])
            self.assertGreater(order["expires_at"], order["issued_at"])
            self.assertNotIn("runner_id", runner["safe"]["runner_request"])

        with self.subTest(row="CB-0620"):
            owner = gates["high"]
            self.assertIsNone(owner["runner_request"])
            approval = owner["work_item"]["approval_ref"]
            self.assertEqual(approval, "controlbot:approval/infra-intent-high")
            self.assertIn('"safe_default":"B"', owner["owner_decision_gate"])
            self.assertIn('"approval_ref":"' + approval + '"', owner["owner_decision_gate"])

        with self.subTest(row="CB-0621"):
            self.assertTrue(runner["instruction_secret_rejected"])
            self.assertTrue(runner["authority_secret_rejected"])
            self.assertTrue(provenance["copied_object_rejected"])
            self.assertTrue(provenance["cross_capability_rejected"])

        with self.subTest(row="CB-0622"):
            self.assertTrue(provenance["context_copy_rejected"])
            self.assertTrue(provenance["context_replay_rejected"])
            self.assertTrue(provenance["projection_replay_rejected"])
            self.assertEqual(provenance["scope_mismatch"]["status"], "denied")

    def test_falla_cerrado(self):
        gates, mutation, provenance = infra("gates"), infra("mutation"), infra("provenance")
        for value in (gates["unknown"], gates["scope_mismatch"], gates["deny_owner"],
                      mutation["weak_capability"], mutation["readonly_write"],
                      mutation["cost_unknown"]):
            self.assertEqual(value["status"], "denied")
            self.assertIsNone(value["runner_request"])
        for key in ("missing_plan_rejected", "missing_verify_rejected",
                    "missing_recovery_rejected", "read_mutation_rejected"):
            self.assertTrue(mutation[key])
        for key in ("fabricated_array_rejected", "copied_object_rejected",
                    "cross_capability_rejected", "authority_level_mismatch_rejected",
                    "context_copy_rejected", "context_replay_rejected"):
            self.assertTrue(provenance[key])

    def test_sin_datos_reales(self):
        safe, runner = infra("safe"), infra("runner")
        self.assertFalse(safe["execution"])
        self.assertFalse(runner["safe"]["execution"])
        self.assertIsNone(safe["capital"])
        self.assertNotIn("runner_id", safe["runner_request"])
        serialized = json.dumps({"safe": safe, "runner": runner}, ensure_ascii=False).lower()
        for secret in ("password", "bearer ", "github_pat_", "private key", "sk-"):
            self.assertNotIn(secret, serialized)

    def test_ci_gate_contract(self):
        # El success exact-SHA de Validar lo acredita GitHub, no el propio test.
        row = infra("safe")
        self.assertEqual(row["version"], 1)
        self.assertEqual(row["status"], "planned")
        self.assertFalse(row["execution"])


if __name__ == "__main__":
    unittest.main()

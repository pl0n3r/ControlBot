"""Pruebas de comportamiento AEGIS (ControlBot #1186), solo con datos sintéticos.

CB-0002 y CB-0010 son límites documentales, clase C; no se afirma poder
verificar ausencia global de operaciones, restores reales ni asesoría jurídica.
"""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

EVIDENCE_PHP = r"""
require 'src/AegisEvidence.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    $result = ['ok' => true, 'value' => \ControlBot\Security\AegisEvidence::normalize($input)];
} catch (\Throwable $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
"""


def scenario(kind: str, action: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / f"aegis_{kind}_scenarios.php"), action],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=True,
    )
    return json.loads(result.stdout)


def fixture() -> dict:
    scope = "venture:condor"
    return {
        "version": 1, "scope": scope,
        "compliance": [{
            "obligation_id": "privacy-register", "state": "compliant", "scope": scope,
            "evidence_refs": ["controlbot:aegis/evidence-privacy"],
            "source_ref": "controlbot:aegis/source-compliance", "observed_at": 2000,
            "freshness": "fresh", "version": "obligation-v1",
            "owner_ref": "controlbot:aegis/owner-security",
            "review_at": 3000, "expires_at": 4000,
        }],
        "backup_evidence": {
            "scope": scope, "state": "healthy",
            "evidence_refs": ["controlbot:aegis/backup"],
            "source_ref": "controlbot:aegis/source-backup",
            "observed_at": 2000, "freshness": "fresh",
        },
        "restore_evidence": {
            "scope": scope, "state": "unknown",
            "evidence_refs": ["controlbot:aegis/restore"],
            "source_ref": "controlbot:aegis/source-restore",
            "observed_at": 2000, "freshness": "fresh",
        },
        "incidents": [],
    }


RUNTIME_PHP = r"""
$argv[1] = 'workitem';
ob_start();
require 'tests/aegis_runtime_scenarios.php';
ob_end_clean();
$options = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$input = base(
    ['category' => $options['category'] ?? 'infrastructure'],
    ['policy_ref' => $options['policy_ref'] ?? 'controlbot:policy/aegis-safe-remediation']
);
try {
    $result = ['ok' => true, 'value' => \ControlBot\Security\AegisRuntime::integrate($input, 1200)];
} catch (\Throwable $e) {
    $result = ['ok' => false, 'error' => $e->getMessage()];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
"""


def runtime_variant(**options) -> dict:
    result = subprocess.run(
        ["php", "-r", RUNTIME_PHP], cwd=ROOT, input=json.dumps(options),
        text=True, capture_output=True, timeout=20, check=True,
    )
    return json.loads(result.stdout)


class CoberturaControlbot03Tests(unittest.TestCase):
    def normalize(self, data: dict) -> dict:
        result = subprocess.run(
            ["php", "-r", EVIDENCE_PHP], input=json.dumps(data),
            cwd=ROOT, text=True, capture_output=True, timeout=20, check=True,
        )
        return json.loads(result.stdout)

    def test_contrato_principal(self):
        """Aserciones reales por fila (no se acepta solo afirmar NO_VERIFICABLE)."""
        normalized = self.normalize(fixture())
        self.assertTrue(normalized["ok"], normalized)
        value = normalized["value"]

        # CB-0001/0003: proyección estructurada y scopes consistentes.
        self.assertEqual(set(value), {
            "version", "scope", "compliance", "backup_evidence",
            "restore_evidence", "incidents",
        })
        self.assertEqual(value["scope"], "venture:condor")
        for row in (value["compliance"][0], value["backup_evidence"], value["restore_evidence"]):
            self.assertEqual(row["scope"], "venture:condor")

        # CB-0004/0006: obligación preserva todos los campos, sin fechas invertidas.
        obligation = value["compliance"][0]
        self.assertEqual(set(obligation), {
            "obligation_id", "state", "reported_state", "scope", "evidence_refs",
            "source_ref", "observed_at", "freshness", "version", "owner_ref",
            "review_at", "expires_at",
        })
        self.assertEqual((obligation["review_at"], obligation["expires_at"]), (3000, 4000))
        self.assertEqual((obligation["state"], obligation["reported_state"]),
                         ("compliant", "compliant"))

        # CB-0005: todos los estados permitidos, no promover compliance stale.
        stale = scenario("evidence", "stale")["compliance"][0]
        self.assertEqual(
            (stale["reported_state"], stale["freshness"], stale["state"]),
            ("compliant", "stale", "unknown"),
        )
        for state in ("compliant", "gap", "unknown", "not_applicable"):
            with self.subTest(row="CB-0005", state=state):
                data = fixture()
                data["compliance"][0]["state"] = state
                checked = self.normalize(data)
                self.assertTrue(checked["ok"], checked)
                self.assertEqual(checked["value"]["compliance"][0]["state"], state)

        # CB-0007/0008: enumeraciones distintas backup vs restore.
        for kind, states in (
            ("backup", ("healthy", "degraded", "failed", "unknown")),
            ("restore", ("verified", "failed", "unknown")),
        ):
            for state in states:
                with self.subTest(kind=kind, state=state):
                    data = fixture()
                    data[f"{kind}_evidence"]["state"] = state
                    checked = self.normalize(data)
                    self.assertTrue(checked["ok"], checked)
                    self.assertEqual(checked["value"][f"{kind}_evidence"]["state"], state)

        # CB-0009: frescura, reported_state, fuente y hora, sin inferir restore.
        continuity = scenario("evidence", "continuity")
        self.assertEqual(continuity["backup_evidence"]["state"], "healthy")
        self.assertEqual(continuity["restore_evidence"]["reported_state"], "verified")
        self.assertEqual(continuity["restore_evidence"]["state"], "unknown")
        self.assertEqual(continuity["restore_evidence"]["freshness"], "unknown")
        data = fixture()
        data["backup_evidence"]["freshness"] = "stale"
        backup = self.normalize(data)["value"]["backup_evidence"]
        self.assertEqual((backup["reported_state"], backup["state"]), ("healthy", "unknown"))
        self.assertEqual(backup["observed_at"], 2000)
        self.assertEqual(backup["source_ref"], "controlbot:aegis/source-backup")
        self.assertTrue(backup["evidence_refs"])

        # CB-0011/0012: resultado de integrar finding, plan y WorkItem Factory.
        integrated = scenario("runtime", "workitem")
        item = integrated["work_item"]
        self.assertEqual(
            (item["origin_mode"], item["origin_system"], item["work_type"]),
            ("automatic", "aegis", "infrastructure"),
        )
        self.assertEqual(item["severity"], "high")
        self.assertEqual(item["claims"], ["aegis:venture:condor"])
        self.assertEqual(item["authority_level"], "l0_ai_autonomous")
        self.assertEqual(item["policy_ref"], "controlbot:policy/aegis-safe-remediation")
        self.assertRegex(item["idempotency_key"], r"^aegis:[0-9a-f]{64}$")
        self.assertTrue(item["evidence_refs"])
        self.assertEqual(integrated["source_freshness"], "fresh")
        self.assertTrue(integrated["ready_hint"])
        for category, capability, roles in (
            ("security", "security_remediation", ["seguridad", "sre"]),
            ("compliance_review", "compliance_review", ["legal_privacidad", "seguridad"]),
            ("infrastructure", "infrastructure_remediation", ["infraestructura", "sre"]),
        ):
            with self.subTest(row="CB-0012", category=category):
                tested = runtime_variant(category=category)
                self.assertTrue(tested["ok"], tested)
                item = tested["value"]["work_item"]
                self.assertEqual(item["work_type"], category)
                self.assertEqual(item["requested_capabilities"], [capability])
                self.assertEqual(item["required_roles"], roles)
                self.assertTrue(tested["value"]["ready_hint"])


        # CB-0013/0014: stale, unknown y gates owner nunca auto-ready.
        for freshness, result in scenario("runtime", "stale").items():
            with self.subTest(row="CB-0013", freshness=freshness):
                self.assertFalse(result["ready_hint"])
                self.assertEqual(result["source_freshness"], freshness)
        owner = scenario("runtime", "owner")
        self.assertFalse(owner["ready_hint"])
        self.assertFalse(owner["owner_gate"]["auto_execute"])
        self.assertEqual(owner["owner_gate"]["gate_ref"],
                         owner["work_item"]["approval_ref"])

        # CB-0015: candidato de Living Feedback es determinista y sin autoridad.
        lesson = integrated["living_feedback"]
        self.assertEqual((lesson["authority_effect"], lesson["policy_effect"]), ("none", "none"))
        self.assertEqual((lesson["scope"], lesson["freshness"]), ("venture:condor", "fresh"))
        self.assertRegex(lesson["candidate_id"], r"^aegis-feedback:[0-9a-f]{64}$")
        self.assertEqual(integrated, scenario("runtime", "workitem"))

        # CB-0016: deduplicación y preservación de verification/evidence.
        duplicate = scenario("runtime", "dedupe")
        self.assertEqual(len(duplicate), 1)
        self.assertEqual(duplicate[0]["work_item"]["idempotency_key"],
                         item["idempotency_key"])
        verified = scenario("runtime", "verified")
        self.assertEqual((verified["verification"]["state"],
                          verified["verification"]["verified"]), ("success", True))
        self.assertEqual(verified["verification"]["evidence_refs"],
                         ["controlbot:aegis/verification-worker"])
        self.assertIn("controlbot:aegis/verification-worker",
                      verified["work_item"]["evidence_refs"])

    def test_falla_cerrado(self):
        """Datos inválidos y permisos/scope ambiguos fallan cerrado."""
        cases = []

        def reject(label, change):
            data = fixture()
            change(data)
            cases.append((label, data))

        reject("CB-0001 extra", lambda d: d.update({"operation": "restore"}))
        reject("CB-0003 scope", lambda d: d.update({"scope": "tenant:wrong"}))
        reject("CB-0003 permiso", lambda d: d["compliance"][0].update({"scope": "project:other"}))
        reject("CB-0004 campo", lambda d: d["compliance"][0].pop("owner_ref"))
        reject("CB-0005 sin evidencia", lambda d: d["compliance"][0].update({"evidence_refs": []}))
        reject("CB-0005 estado", lambda d: d["compliance"][0].update({"state": "approved"}))
        reject("CB-0006 caducidad", lambda d: d["compliance"][0].update({"expires_at": 2000}))
        reject("CB-0007 backup", lambda d: d["backup_evidence"].update({"state": "verified"}))
        reject("CB-0008 restore", lambda d: d["restore_evidence"].update({"state": "healthy"}))
        reject("CB-0009 restore-scope", lambda d: d["restore_evidence"].update({"scope": "project:other"}))
        reject("CB-0010 extra", lambda d: d["compliance"][0].update({"payload": "ficticio"}))
        reject("CB-0015 secrets", lambda d: d["compliance"][0].update({
            "source_ref": "controlbot:aegis/secret-value",
        }))
        for label, data in cases:
            with self.subTest(label=label):
                result = self.normalize(data)
                self.assertFalse(result["ok"], (label, result))
                self.assertTrue(result.get("error"), (label, result))

        self.assertTrue(scenario("runtime", "secret")["rejected"])
        self.assertTrue(scenario("runtime", "timestamp")["rejected"])
        policy = runtime_variant(policy_ref="controlbot:policy/other")
        self.assertFalse(policy["ok"], policy)  # CB-0013: provenance/policy mismatch
        for result in scenario("runtime", "stale").values():
            self.assertFalse(result["ready_hint"])
        self.assertFalse(scenario("runtime", "owner")["owner_gate"]["auto_execute"])

    def test_sin_datos_reales(self):
        """Solo entradas ficticias: sin provider, datos personales ni credenciales."""
        normalized = self.normalize(fixture())
        self.assertTrue(normalized["ok"])
        integrated = scenario("runtime", "workitem")
        self.assertNotIn("provider", integrated["work_item"])
        self.assertNotIn("executor", integrated["work_item"])
        self.assertNotIn("scheduler", integrated["work_item"])
        self.assertNotIn("payload", normalized["value"]["compliance"][0])
        self.assertEqual(integrated["living_feedback"]["authority_effect"], "none")
        self.assertEqual(integrated["living_feedback"]["policy_effect"], "none")
        payload = json.dumps({"normalized": normalized["value"], "integrated": integrated}).lower()
        for pattern in ("password", "bearer ", "private_key", "api_key", "@", "https://"):
            with self.subTest(pattern=pattern):
                self.assertNotIn(pattern, payload)


if __name__ == "__main__":
    unittest.main()

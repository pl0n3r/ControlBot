"""#1042: contraste sintético CB-0518..CB-0532 sin autoridad live.

Los contratos de documentos, PHP y plan Hostinger se prueban offline.
Una evidencia de fixture, una revisión de forma o un CI verde nunca
demuestran backup/restore real, D-059 ni autorización de producción.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
from functools import lru_cache
import json
from pathlib import Path
import re
import runpy
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CLAUSES = tuple(f"CB-{i:04d}" for i in range(518, 533))
TARGET = "control.condorapp.com.co"
SHA = "a" * 40
BACKUP = ROOT / "docs/hostinger-d059-backup.md"
GOLIVE = ROOT / "docs/hostinger-go-live-plan.md"
PART = ROOT / "docs/matriz-cobertura-parte-02.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
CLAUSE_TEXT = {
    "CB-0518": "secretos/credenciales: nunca se copian",
    "CB-0519": "La mera capacidad de lectura no autoriza",
    "CB-0520": "**Fecha/hora UTC**",
    "CB-0521": "**Referencia opaca**",
    "CB-0522": "**Verificación**:",
    "CB-0523": "**Restauración**:",
    "CB-0524": "No son evidencia suficiente:",
    "CB-0525": "Antes de sacar PR #630 de draft",
    "CB-0526": "La verificación del backup no reemplaza",
    "CB-0527": "verificar que el sitio vuelve",
    "CB-0528": "si el despliegue/revert no restaura",
    "CB-0529": "No se borran sitios",
    "CB-0530": "**Backup real existente**:",
    "CB-0531": "**D-059 satisfecho**:",
    "CB-0532": "**Autoridad:** #914/A",
}
GATES = (
    "owner_approval", "hostinger_operator", "legal_privacy", "backup_restore",
    "rollback", "artifact_provenance", "owner_only_https",
    "production_observation",
)
UNKNOWN = "UNKNOWN"


def matrix_rows(part: str, index: str) -> dict[str, tuple[str, ...]]:
    """Exige presencia exacta, fuente, estado y no duplicación de 15 IDs."""
    rows: dict[str, tuple[str, ...]] = {}
    for text in (part, index):
        for line in text.splitlines():
            if not line.startswith("| CB-"):
                continue
            fields = tuple(field.strip() for field in line.strip().strip("|").split("|"))
            if not fields or fields[0] not in CLAUSES:
                continue
            clause = fields[0]
            if clause in rows or len(fields) != 6:
                raise ValueError("UNKNOWN: row duplicated/malformed " + clause)
            if CLAUSE_TEXT[clause] not in fields[2] or fields[3] != "NO_VERIFICABLE":
                raise ValueError("UNKNOWN: row source or status drift " + clause)
            if not fields[1].startswith("[docs/") or "UNKNOWN" not in fields[4]:
                raise ValueError("UNKNOWN: incomplete evidence " + clause)
            rows[clause] = fields
    if set(rows) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing or unexpected clause set")
    return rows


@lru_cache(maxsize=1)
def plan_module() -> dict:
    # run_name is not __main__: exercise the real pure evaluator without I/O.
    return runpy.run_path(str(ROOT / "scripts/hostinger_go_live_plan.py"))


def handoff() -> dict:
    return {
        "version": 1, "target": TARGET, "sha": SHA, "phase": "construccion",
        "evidence": [
            {
                "gate": gate, "source_ref": f"controlbot:fixture/{gate}",
                "scope": TARGET, "sha": SHA, "verified": True, "fresh": True,
            }
            for gate in GATES
        ],
    }


def evaluate(payload: object) -> dict:
    return plan_module()["evaluate"](payload)


@lru_cache(maxsize=8)
def backup_scenario(name: str) -> dict:
    """Existing PHP contract; no Hostinger, database or network connections."""
    allowed = {"missing", "scope", "causality", "readonly", "freshness", "evidence"}
    if name not in allowed:
        raise ValueError("UNKNOWN: unrecognized fixture")
    result = subprocess.run(
        ["php", str(ROOT / "tests/backup_gate_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: PHP backup fixture failed: " + result.stderr[-500:])
    parsed = json.loads(result.stdout)
    if not isinstance(parsed, dict):
        raise AssertionError("UNKNOWN: malformed PHP fixture")
    return parsed


def synthetic_receipt() -> dict:
    return {
        "created_utc": "2026-10-26T10:00:00Z",
        "scope": "public_html", "reference": "restore-demo-001",
        "mechanism": "hPanel-synthetic", "verification": "fixture-only",
        "restore_steps": ["select", "restore", "verify-403-or-404"],
    }


def receipt_shape(receipt: object) -> bool:
    """Valida SOLO forma local; nunca observa ni certifica un backup real."""
    if not isinstance(receipt, dict) or set(receipt) != {
        "created_utc", "scope", "reference", "mechanism", "verification",
        "restore_steps",
    }:
        return False
    if not all(isinstance(receipt[k], str) for k in (
        "created_utc", "scope", "reference", "mechanism", "verification"
    )):
        return False
    if receipt["scope"] != "public_html":
        return False
    if re.fullmatch(r"[a-z][a-z0-9_-]{5,60}", receipt["reference"]) is None:
        return False
    if receipt["mechanism"] != "hPanel-synthetic" or receipt["verification"] != "fixture-only":
        return False
    try:
        stamp = datetime.fromisoformat(receipt["created_utc"].replace("Z", "+00:00"))
    except ValueError:
        return False
    if stamp.utcoffset() != timedelta(0) or stamp.tzinfo is None:
        return False
    steps = receipt["restore_steps"]
    return isinstance(steps, list) and steps == ["select", "restore", "verify-403-or-404"]


def operational_verdict(_clause: str, _evidence: object = None) -> dict[str, str]:
    """No hay fuente externa verificada: ningún fixture eleva a cumplimiento."""
    return {"offline": UNKNOWN, "live": UNKNOWN, "operating": UNKNOWN}


class BrechasControlbot35Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.backup = BACKUP.read_text(encoding="utf-8")
        cls.golive = GOLIVE.read_text(encoding="utf-8")
        cls.part = PART.read_text(encoding="utf-8")
        cls.index = MATRIX.read_text(encoding="utf-8")
        cls.rows = matrix_rows(cls.part, cls.index)
        cls.decisions = json.loads(
            (ROOT / "decisiones.yml").read_text(encoding="utf-8")
        )
        cls.d059 = next(
            row for row in cls.decisions["decisions"] if row["id"] == "D-059"
        )
        cls.gate = evaluate(handoff())

    def test_contrato_principal(self) -> None:
        """AC-01: caso ejecutable/documentado explícito para las 15 filas."""
        missing = backup_scenario("missing")
        scope = backup_scenario("scope")
        causal = backup_scenario("causality")
        readonly = backup_scenario("readonly")
        fresh = backup_scenario("freshness")
        evidence = backup_scenario("evidence")
        record = synthetic_receipt()
        report = self.gate
        no_backup = handoff()
        no_backup["evidence"] = [
            x for x in no_backup["evidence"] if x["gate"] != "backup_restore"
        ]
        denied = evaluate(no_backup)
        checks = {
            "CB-0518": (
                not evidence["contains_payload_key"]
                and not evidence["contains_artifact_ref"]
                and evaluate({**handoff(), "unexpected": "fixture"})["decision"] == "NO_GO"
            ),
            "CB-0519": (
                readonly["read"]["allowed"]
                and not readonly["owner"]["allowed"]
                and readonly["owner"]["reason"] == "owner_approval_required"
            ),
            "CB-0520": receipt_shape(record)
            and record["created_utc"].endswith("Z"),
            "CB-0521": (
                receipt_shape(record)
                and evaluate({
                    **handoff(),
                    "evidence": [{**handoff()["evidence"][0],
                                  "source_ref": "https://example.invalid/ref"}],
                })["decision"] == "NO_GO"
            ),
            "CB-0522": (
                scope["resource"]["reason"] == "backup_scope_mismatch"
                and scope["project"]["reason"] == "backup_scope_mismatch"
            ),
            "CB-0523": (
                receipt_shape(record)
                and not missing["allowed"]
                and missing["reason"] == "backup_receipt_required"
            ),
            "CB-0524": (
                denied["decision"] == "NO_GO"
                and "backup_restore" in denied["missing_gates"]
                and not causal["not_ready"]["allowed"]
            ),
            "CB-0525": (
                denied["decision"] == "NO_GO"
                and denied["gate_status"]["backup_restore"] == "MISSING"
            ),
            "CB-0526": (
                report["decision"] == "REVIEW_ONLY"
                and report["go_live_authorized"] is False
                and report["deploy_authorized"] is False
            ),
            "CB-0527": (
                "403/404" in self.backup
                and "deny-by-default" in self.backup
                and report["execution"] is False
            ),
            "CB-0528": (
                "restore point D-059" in self.backup
                and not fresh["expired"]["allowed"]
                and fresh["expired"]["reason"] == "backup_not_usable_for_write"
            ),
            "CB-0529": (
                "autorización explícita del dueño" in self.backup
                and "borrar sitios" in self.d059["text"]
                and report["runtime_authorized"] is False
            ),
            "CB-0530": (
                denied["decision"] == "NO_GO"
                and "backup_restore" in denied["missing_gates"]
                and not missing["allowed"]
            ),
            "CB-0531": (
                "contrato preparado" in self.backup.lower()
                and "backup real existente" in self.backup.lower()
                and "no satisface D-059 por sí solo" in self.backup
                and report["decision"] != "GO"
            ),
            "CB-0532": (
                report["decision"] == "REVIEW_ONLY"
                and not report["runtime_authorized"]
                and not report["execution"]
                and "no operar la infraestructura" in self.golive
                and "no autoriza publicación" in self.golive
            ),
        }
        self.assertEqual(set(checks), set(CLAUSES))
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertIn(clause, self.rows)
                source = self.golive if clause == "CB-0532" else self.backup
                self.assertIn(CLAUSE_TEXT[clause], source)
                self.assertTrue(checks[clause], f"{clause}: evidence UNKNOWN")

    def test_falla_cerrado(self) -> None:
        """AC-02: ausencia, duplicación, ambigüedad y stale no son éxito."""
        part_line = next(
            s for s in self.part.splitlines() if s.startswith("| CB-0520 |")
        )
        for bad_part in (
            self.part.replace(part_line, ""),
            self.part + "\n" + part_line,
            self.part.replace(part_line, part_line.replace(
                "NO_VERIFICABLE", "CUBIERTO", 1
            )),
        ):
            if not bad_part:
                continue
            if bad_part == self.part:
                continue
            with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                matrix_rows(bad_part, self.index)
        record = synthetic_receipt()
        mutations = (
            {"created_utc": "2026-10-26T10:00:00"},
            {"created_utc": "not-UTC"},
            {"scope": "other_site"},
            {"reference": "https://example.invalid/ref"},
            {"reference": ""},
            {"restore_steps": []},
            {"verification": "untrusted-external-assertion"},
        )
        for delta in mutations:
            with self.subTest(delta=delta):
                self.assertFalse(receipt_shape({**record, **delta}))
        self.assertFalse(receipt_shape(None))
        self.assertFalse(receipt_shape({}))
        for gate in ("owner_approval", "backup_restore", "production_observation"):
            payload = handoff()
            payload["evidence"] = [
                x for x in payload["evidence"] if x["gate"] != gate
            ]
            report = evaluate(payload)
            self.assertEqual(report["decision"], "NO_GO")
            self.assertIn(gate, report["missing_gates"])
            self.assertFalse(report["go_live_authorized"])
        payload = handoff()
        payload["evidence"][0]["fresh"] = False
        self.assertEqual(evaluate(payload)["decision"], "NO_GO")
        self.assertFalse(backup_scenario("freshness")["no_expiration"]["allowed"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: salida y fixtures no contienen credenciales/PII ni I/O live."""
        payload = handoff()
        report = evaluate(payload)
        self.assertEqual(payload["phase"], "construccion")
        self.assertEqual(set(report["next_steps"]), set(GATES))
        self.assertFalse(report["execution"])
        self.assertFalse(report["runtime_authorized"])
        self.assertFalse(report["deploy_authorized"])
        self.assertFalse(report["go_live_authorized"])
        for secret in ("@", "https://", "Bearer ", "password=", "session="):
            self.assertNotIn(secret, json.dumps(payload))
            self.assertNotIn(secret, json.dumps(report))
        self.assertFalse(backup_scenario("evidence")["contains_digest"])
        self.assertFalse(backup_scenario("evidence")["contains_payload_key"])
        source = (ROOT / "scripts/hostinger_go_live_plan.py").read_text(encoding="utf-8")
        for unsafe in ("urllib.request", "requests.", "subprocess.", "socket."):
            self.assertNotIn(unsafe, source)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: validación local jamás prueba respaldo, D-059 ni live."""
        record = synthetic_receipt()
        self.assertTrue(receipt_shape(record))
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertEqual(
                    operational_verdict(clause, record),
                    {"offline": UNKNOWN, "live": UNKNOWN, "operating": UNKNOWN},
                )
                self.assertEqual(self.rows[clause][3], "NO_VERIFICABLE")
        self.assertEqual(operational_verdict("CB-0532", None)["live"], UNKNOWN)
        self.assertEqual(self.gate["decision"], "REVIEW_ONLY")
        self.assertFalse(self.gate["go_live_authorized"])


if __name__ == "__main__":
    unittest.main()

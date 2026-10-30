#!/usr/bin/env python3
"""ControlBot #1011 · CB-0046..0061 (salvo CB-0058), 15 contratos offline.

No certifica backup B2, proveedor, restore, deploy ni operación real. Cada
resultado local exige la fila normativa única y la prueba sintética verificable;
la condición productiva continúa UNKNOWN y la matriz no se modifica.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
IDS = tuple(f"CB-{i:04d}" for i in range(46, 62) if i != 58)
MATRICES = (
    "docs/matriz-cobertura.md",
    "docs/matriz-cobertura-parte-01.md",
    "docs/matriz-cobertura-parte-02.md",
    "docs/matriz-cobertura-parte-03.md",
    "docs/matriz-cobertura-parte-04.md",
)
DOCS = {
    "autofactory": "docs/autofactory-recovery-rollout.md",
    "b2": "docs/backblaze-b2-preflight.md",
    "receipt": "docs/backup-receipt-integrity.md",
}
SOURCE_FOR_ID = {
    **{f"CB-{i:04d}": "autofactory" for i in range(46, 50)},
    **{f"CB-{i:04d}": "b2" for i in range(50, 58)},
    **{f"CB-{i:04d}": "receipt" for i in range(59, 62)},
}
# Cada requisito tiene un ancla única del texto normativo. Los tres dominios
# se comprueban además con la ejecución de sus escenarios PHP reales.
ANCHORS = {
    "CB-0046": "AutoFactory usa los mismos objetivos declarativos",
    "CB-0047": "Por la arquitectura vigente de AutoFactory",
    "CB-0048": "No existe evidencia canónica que permita afirmar backup",
    "CB-0049": "La evidencia operativa futura debe llegar por los contratos canónicos",
    "CB-0050": "**Autoridad:** la opción A autenticada",
    "CB-0051": "BackblazeB2Preflight::assess evalúa solamente",
    "CB-0052": "Un bucket de backup debe ser allPrivate",
    "CB-0053": "Los metadatos de defaultServerSideEncryption",
    "CB-0054": "Si la API oculta los valores por falta de permiso",
    "CB-0055": "Los únicos resultados del contrato son",
    "CB-0056": "**Para un preflight real** faltan operador autorizado",
    "CB-0057": "Comprobación determinista sin red",
    "CB-0059": "El método puro ",
    "CB-0060": "**Límite de autoridad:** esta prueba compara entradas sintéticas",
    "CB-0061": "Pruebas: ",
}


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def rows_from_matrix(documents: object) -> dict[str, tuple[str, ...]]:
    """Rechaza IDs duplicados, filas incompletas o promoción no sustentada."""
    if (not isinstance(documents, dict) or set(documents) != set(MATRICES)
            or any(not isinstance(value, str) or not value.strip()
                   for value in documents.values())):
        raise ValueError("UNKNOWN: matriz incompleta o ilegible")
    found: dict[str, tuple[str, ...]] = {}
    for document in documents.values():
        for line in document.splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if not match or match.group(1) not in IDS:
                continue
            key = match.group(1)
            cells = tuple(value.strip() for value in line.strip().strip("|").split("|"))
            if (key in found or len(cells) != 7 or cells[0] != key
                    or not cells[1].startswith("[docs/")
                    or not cells[2].startswith(ANCHORS[key])
                    or not credible_offline_coverage_row(cells)
                    or (cells[3] == "NO_VERIFICABLE" and
                        ("Offline=UNKNOWN; live=UNKNOWN; operando=UNKNOWN" not in cells[4]
                         or "Falta contraste de comportamiento con prueba y PR concretos"
                         not in cells[4])) or not cells[5].startswith("[#182]")
                    or cells[6] != ""):
                # GitHub matrix has six data columns (the final pipe is structural).
                if (key in found or len(cells) != 6 or cells[0] != key
                        or not cells[1].startswith("[docs/")
                        or not cells[2].startswith(ANCHORS[key])
                        or not credible_offline_coverage_row(cells)
                        or (cells[3] == "NO_VERIFICABLE" and
                            ("Offline=UNKNOWN; live=UNKNOWN; operando=UNKNOWN" not in cells[4]
                             or "Falta contraste de comportamiento con prueba y PR concretos"
                             not in cells[4])) or not cells[5].startswith("[#182]")):
                    raise ValueError("UNKNOWN: fila contradictoria " + key)
            expected_path = DOCS[SOURCE_FOR_ID[key]]
            if not cells[1].startswith("[" + expected_path + ":"):
                raise ValueError("UNKNOWN: fuente equivocada " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: IDs ausentes en matriz")
    return found


@lru_cache(maxsize=24)
def scenario(name: str, fixture: str) -> dict:
    if name not in ("autofactory", "b2", "receipt"):
        raise ValueError("UNKNOWN: fuente externa")
    scripts = {
        "autofactory": "autofactory_recovery_rollout_scenarios.php",
        "b2": "b2_recovery_preflight_scenarios.php",
        "receipt": "backup_integrity_scenarios.php",
    }
    if name == "autofactory" and fixture != "profile":
        raise ValueError("UNKNOWN: fixture desconocido")
    args = ["php", str(ROOT / "tests" / scripts[name])]
    if name != "autofactory":
        args.append(fixture)
    completed = subprocess.run(
        args, cwd=ROOT, check=False, capture_output=True, text=True, timeout=20,
    )
    if completed.returncode or completed.stderr.strip():
        raise AssertionError("UNKNOWN: escenario sintético falló " + name)
    data = json.loads(completed.stdout)
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: escenario no estructurado")
    return data


def observations() -> dict[str, bool]:
    """Cada ID tiene un predicado sobre outputs de PHP real, nunca texto solo."""
    a = scenario("autofactory", "profile")
    profile = a.get("profile", {})
    recovery = a.get("recovery", {})
    bounds = a.get("boundaries", {})
    b2 = {key: scenario("b2", key) for key in (
        "positive", "public", "redacted", "no-retention", "lock-disabled",
        "encryption-redacted", "encryption-off", "missing",
        "secret-injection", "id-injection", "nested-injection", "malformed",
    )}
    receipt = {key: scenario("receipt", key) for key in
               ("shape", "content", "guard", "privacy")}
    safe = b2["positive"]
    incomplete = ("public", "redacted", "no-retention", "lock-disabled",
                  "encryption-redacted", "encryption-off", "missing")
    results = {
        "CB-0046": (
            a.get("profile_status") == "configured"
            and profile.get("targets") == {"rpo_minutes": 15, "rto_minutes": 60}
            and profile.get("retention") == {
                "hourly": 24, "daily": 7, "weekly": 8, "monthly": 12}
            and profile.get("restore_drill_cadence_hours") == 168
            and profile.get("encryption_required") is True
            and profile.get("strategy") == {
                "copies_required": 3, "media_types_required": 2,
                "offsite_required": True, "immutable_required": True,
                "undetected_restore_failures_target": 0}
        ),
        "CB-0047": profile.get("sources") == {
            "repository": "required", "configuration": "required",
            "database": "not_applicable", "media": "not_applicable",
        },
        "CB-0048": (
            all(recovery.get(k) == "unknown" for k in (
                "canonical_backup_receipt", "offsite_copy", "immutable_copy",
                "canonical_restore_drill"))
            and recovery.get("observed_rpo_minutes", False) is None
            and recovery.get("demonstrated_rto_minutes", False) is None
            and recovery.get("dr_status") == UNKNOWN
            and recovery.get("restorable") is False
        ),
        "CB-0049": (
            recovery.get("execution") is False
            and recovery.get("dr_status") == UNKNOWN
            and bounds == {
                "runtime_bridge_live": False, "provider_provisioned": False,
                "d061_lifted": False}
        ),
        "CB-0050": (
            safe.get("operational_status") == UNKNOWN
            and a.get("profile_status") == "configured"
            and bounds.get("provider_provisioned") is False
            and recovery.get("execution") is False
        ),
        "CB-0051": (
            safe == {"version": 1, "configuration_status": "consistent",
                     "operational_status": UNKNOWN, "reasons": []}
            and all(b2[k] == {
                "error_code": "invalid_shape", "operational_status": UNKNOWN}
                for k in ("secret-injection", "id-injection",
                          "nested-injection", "malformed"))
        ),
        "CB-0052": (
            safe.get("configuration_status") == "consistent"
            and b2["public"].get("reasons") == ["private_bucket_required"]
            and "default_retention_missing" in b2["no-retention"].get("reasons", [])
            and "object_lock_disabled" in b2["lock-disabled"].get("reasons", [])
        ),
        "CB-0053": (
            safe.get("configuration_status") == "consistent"
            and "encryption_missing" in b2["encryption-off"].get("reasons", [])
            and "encryption_unreadable" in b2["encryption-redacted"].get("reasons", [])
        ),
        "CB-0054": (
            "retention_unreadable" in b2["redacted"].get("reasons", [])
            and "default_retention_missing" in b2["no-retention"].get("reasons", [])
            and b2["redacted"].get("configuration_status") == "incomplete"
            and b2["no-retention"].get("configuration_status") == "incomplete"
        ),
        "CB-0055": (
            set(safe) == {"version", "configuration_status",
                          "operational_status", "reasons"}
            and all(b2[k].get("configuration_status") == "incomplete"
                    and b2[k].get("operational_status") == UNKNOWN
                    and isinstance(b2[k].get("reasons"), list)
                    and all(isinstance(x, str) and len(x) < 40
                            for x in b2[k]["reasons"])
                    for k in incomplete)
        ),
        "CB-0056": (
            safe.get("operational_status") == UNKNOWN
            and bounds.get("provider_provisioned") is False
            and recovery.get("canonical_backup_receipt") == "unknown"
            and receipt["privacy"]["result"].get("status") == "MATCH"
            and recovery.get("restorable") is False
        ),
        "CB-0057": (
            safe.get("version") == 1
            and b2["missing"].get("configuration_status") == "incomplete"
            and b2["missing"].get("operational_status") == UNKNOWN
            and b2["secret-injection"].get("error_code") == "invalid_shape"
        ),
        "CB-0059": (
            receipt["shape"].get("valid") is True
            and all(receipt["shape"].get(k) is True for k in (
                "short", "long", "non_hex"))
            and receipt["content"].get("match") == {
                "status": "MATCH", "reason": "checksum_match"}
            and receipt["content"].get("mismatch") == {
                "status": "MISMATCH", "reason": "checksum_mismatch"}
            and all(receipt["guard"].get(k) == {
                "status": UNKNOWN, "reason": reason}
                for k, reason in (
                    ("scope", "scope_mismatch"),
                    ("premature", "receipt_unusable"),
                    ("expired", "receipt_unusable"),
                    ("stale", "receipt_stale")))
        ),
        "CB-0060": (
            receipt["privacy"]["result"] == {
                "status": "MATCH", "reason": "checksum_match"}
            and "integrity" not in receipt["privacy"].get("safe_receipt", {})
            and receipt["privacy"]["safe_receipt"].get("restore_capability")
                == "unknown"
            and recovery.get("dr_status") == UNKNOWN
        ),
        "CB-0061": (
            receipt["shape"].get("valid") is True
            and receipt["guard"].get("invalid_scope") is True
            and receipt["guard"].get("invalid_time") is True
            and receipt["privacy"]["result"].get("status") == "MATCH"
        ),
    }
    return {k: bool(v) for k, v in results.items()}


def local_verdict(key: object, rows: object, results: object) -> str:
    if (not isinstance(key, str) or key not in IDS
            or not isinstance(rows, dict) or key not in rows
            or not isinstance(results, dict) or set(results) != set(IDS)
            or results.get(key) is not True):
        return UNKNOWN
    if len(rows[key]) not in (6, 7) or not credible_offline_coverage_row(rows[key]):
        return UNKNOWN
    return LOCAL


def operational_verdict(_key: object, _evidence: object = None) -> str:
    """La prueba offline nunca demuestra proveedor, offsite ni restore real."""
    return UNKNOWN


class BrechasControlbot04Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.documents = {path: read(path) for path in MATRICES}
        cls.rows = rows_from_matrix(cls.documents)
        cls.results = observations()

    def test_contrato_principal(self) -> None:
        """AC-01: 15 IDs, un predicado PHP y una fila única por requisito."""
        self.assertEqual(set(IDS), set(SOURCE_FOR_ID))
        self.assertEqual(set(self.results), set(IDS))
        self.assertEqual(len(IDS), 15)
        for key in IDS:
            with self.subTest(clause=key):
                self.assertEqual(
                    local_verdict(key, self.rows, self.results), LOCAL)
                self.assertTrue(credible_offline_coverage_row(self.rows[key]))
                self.assertIn(ANCHORS[key], read(DOCS[SOURCE_FOR_ID[key]]))
                self.assertEqual(operational_verdict(key, LOCAL), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: fila/fixture ausente, duplicada o promovida -> UNKNOWN."""
        for key in IDS:
            path = next(p for p in MATRICES if f"| {key} |" in self.documents[p])
            line = next(x for x in self.documents[path].splitlines()
                        if x.startswith(f"| {key} |"))
            for malformed in (
                self.documents[path].replace(line, "", 1),
                self.documents[path] + "\n" + line,
                self.documents[path].replace(
                    line, (line.replace("MERGED", "PENDING", 1) if "CUBIERTO" in line
                            else line.replace("NO_VERIFICABLE", "CUBIERTO", 1)), 1),
                self.documents[path].replace(
                    line, line.replace(ANCHORS[key], "texto-ambiguo", 1), 1),
            ):
                mutated = {**self.documents, path: malformed}
                with self.subTest(clause=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows_from_matrix(mutated)
            missing = dict(self.results)
            missing.pop(key)
            self.assertEqual(local_verdict(key, self.rows, missing), UNKNOWN)
            invalid = {**self.results, key: False}
            self.assertEqual(local_verdict(key, self.rows, invalid), UNKNOWN)
            self.assertEqual(local_verdict(key, {}, self.results), UNKNOWN)
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            rows_from_matrix({})
        self.assertEqual(local_verdict("CB-0058", self.rows, self.results), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixture PHP ficticio, sin credenciales ni proveedor/red."""
        for path in (*MATRICES, *DOCS.values()):
            self.assertTrue((ROOT / path).is_file(), path)
        for name in (
            "b2_recovery_preflight_scenarios.php",
            "backup_integrity_scenarios.php",
        ):
            source = read("tests/" + name)
            for forbidden in ("curl_", "fsockopen(", "new PDO(", "mysqli(",
                              "file_put_contents(", "shell_exec(", "getenv("):
                self.assertNotIn(forbidden, source)
        for name, fixture in (
            ("autofactory", "profile"), ("b2", "positive"),
            ("b2", "secret-injection"), ("receipt", "privacy"),
        ):
            encoded = json.dumps(scenario(name, fixture), sort_keys=True).lower()
            for forbidden in ('"password"', '"api_key"', '"applicationkey"',
                              '"buckettype"', '"bucketid"', '"token"',
                              '"credentials"', '"integrity"'):
                if name == "autofactory" and forbidden == '"buckettype"':
                    continue
                self.assertNotIn(forbidden, encoded)
        self.assertEqual(
            scenario("receipt", "privacy")["safe_receipt"].get("restore_capability"),
            "unknown",
        )

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: prueba offline ≠ certificación operativa/PR reconciliado."""
        for key in IDS:
            self.assertEqual(operational_verdict(
                key, local_verdict(key, self.rows, self.results)), UNKNOWN)
        self.assertEqual(scenario("b2", "positive")["operational_status"], UNKNOWN)
        self.assertEqual(scenario("autofactory", "profile")["recovery"]["dr_status"],
                         UNKNOWN)
        self.assertEqual(scenario("receipt", "content")["mismatch"]["status"],
                         "MISMATCH")
        self.assertNotEqual(
            scenario("receipt", "privacy")["safe_receipt"]["restore_capability"],
            "validated",
        )


if __name__ == "__main__":
    unittest.main()

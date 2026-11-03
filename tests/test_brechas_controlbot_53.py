"""ControlBot #1060: quince brechas CB-0805..CB-0819, offline/fail-closed.

Los fixtures no prueban backups, despliegues, rollback real, D-043/D-059,
Hostinger, integridad de datos, producción ni go-live: todo ello sigue UNKNOWN.
"""
from __future__ import annotations

import ast
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from tempfile import TemporaryDirectory
import unittest

from test_brechas_controlbot_20 import credible_e3_coverage_row as e3_coverage

ROOT = Path(__file__).resolve().parents[1]
MATRICES = (ROOT / "docs/matriz-cobertura.md",
            ROOT / "docs/matriz-cobertura-parte-03.md")
BACKUP = ROOT / "ops/factory/backup"
DEPLOY = ROOT / "ops/factory/deploy"
ROLLBACK = ROOT / "ops/factory/rollback"
BACKUP_DOC = ROOT / "docs/ops/factory-backup-preflight.md"
DEPLOY_DOC = ROOT / "docs/ops/factory-deploy-preflight.md"
ROLLBACK_DOC = ROOT / "docs/ops/factory-rollback-preflight.md"
BACKUP_TEST = ROOT / "tests/test_factory_backup_preflight.py"
ROLLBACK_TEST = ROOT / "tests/test_factory_rollback_preflight.py"
IDS = tuple(f"CB-{i:04d}" for i in range(805, 820))
UNKNOWN = "UNKNOWN"


def backup_fixture(**changes: object) -> dict[str, object]:
    sample: dict[str, object] = {
        "kind": "synthetic", "source_sha": "a" * 40,
        "artifact_sha256": "b" * 64, "baseline_verified": True,
        "artifact_verified": True, "rollback_verified": True,
    }
    sample.update(changes)
    return sample


def deploy_fixture(**changes: object) -> dict[str, object]:
    sample: dict[str, object] = {
        "kind": "synthetic", "phase": "construccion", "transport": "none",
        "candidate_sha": "a" * 40, "expected_candidate_sha": "a" * 40,
        "artifact_sha256": "c" * 64, "baseline_sha": "b" * 40,
        "expected_baseline_sha": "b" * 40, "baseline_verified": True,
        "artifact_verified": True, "backup_verified": True,
        "rollback_verified": True,
    }
    sample.update(changes)
    return sample


def rollback_fixture(**changes: object) -> dict[str, object]:
    sample: dict[str, object] = {
        "kind": "synthetic", "phase": "construccion", "plan_kind": "git_revert",
        "candidate_sha": "a" * 40, "previous_healthy_sha": "b" * 40,
        "expected_healthy_sha": "b" * 40, "baseline_verified": True,
        "previous_artifact_verified": True, "rollback_plan_verified": True,
    }
    sample.update(changes)
    return sample


def execute(path: Path, payload: object, *, args: tuple[str, ...] = ("--offline-dry-run",),
            raw: str | None = None, cwd: Path = ROOT,
            env: dict[str, str] | None = None) -> tuple[subprocess.CompletedProcess[str], dict[str, object]]:
    document = json.dumps(payload) if raw is None else raw
    process = subprocess.run(
        [sys.executable, str(path), *args], input=document,
        cwd=cwd, env=env, capture_output=True, text=True, check=False, timeout=10,
    )
    return process, json.loads(process.stdout)


def matrix_rows() -> dict[str, tuple[str, ...]]:
    rows: dict[str, tuple[str, ...]] = {}
    for document in MATRICES:
        for line in document.read_text(encoding="utf-8").splitlines():
            if not re.match(r"^\| CB-08(?:0[5-9]|1[0-9]) \|", line):
                continue
            fields = tuple(part.strip() for part in line.strip().strip("|").split("|"))
            name = fields[0]
            if name in rows or len(fields) != 6 or not all(fields):
                raise ValueError("UNKNOWN: fila duplicada o malformada " + name)
            rows[name] = fields
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: faltan filas CB-0805..CB-0819")
    for name, fields in rows.items():
        if (not e3_coverage(fields)):
            raise ValueError("UNKNOWN: evidencia no autorizada " + name)
    return rows


def operational_status(_evidence: object = None) -> str:
    """Fixtures locales nunca autentican el estado del mundo externo."""
    return UNKNOWN


def no_external_imports(source: str) -> bool:
    try:
        tree = ast.parse(source)
    except SyntaxError:
        return False
    modules = {alias.name for node in ast.walk(tree) if isinstance(node, ast.Import)
               for alias in node.names}
    foreign = [node for node in ast.walk(tree) if isinstance(node, ast.ImportFrom)
               and node.module != "__future__"]
    return modules == {"json", "re", "sys"} and not foreign


class BrechasControlbot53Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 15 cláusulas y 15 aserciones de comportamiento o límite."""
        rows = matrix_rows()
        backup_doc = BACKUP_DOC.read_text(encoding="utf-8")
        deploy_doc = DEPLOY_DOC.read_text(encoding="utf-8")
        rollback_doc = ROLLBACK_DOC.read_text(encoding="utf-8")
        backup_test = BACKUP_TEST.read_text(encoding="utf-8")
        rollback_test = ROLLBACK_TEST.read_text(encoding="utf-8")
        backup_source = BACKUP.read_text(encoding="utf-8")
        deploy_source = DEPLOY.read_text(encoding="utf-8")
        rollback_source = ROLLBACK.read_text(encoding="utf-8")
        bp, b = execute(BACKUP, backup_fixture())
        dp, d = execute(DEPLOY, deploy_fixture())
        rp, r = execute(ROLLBACK, rollback_fixture())
        _, mismatch = execute(DEPLOY, deploy_fixture(expected_baseline_sha="c" * 40))
        _, same = execute(DEPLOY, deploy_fixture(baseline_sha="a" * 40,
                                                expected_baseline_sha="a" * 40))
        _, rejected = execute(ROLLBACK, rollback_fixture(expected_healthy_sha="c" * 40))
        original_tests = [n for n in ast.walk(ast.parse(rollback_test))
                          if isinstance(n, ast.FunctionDef) and n.name.startswith("test_")]
        checks = {
            "CB-0805": ("ejecutables señuelo dentro de un directorio temporal" in backup_doc
                        and "test_no_network_secrets_or_hostinger_side_effects" in backup_test),
            "CB-0806": ("migraciones, datos, servicios ni efectos exteriores que restaurar" in backup_doc
                        and '"backup_verified": False' in backup_source
                        and no_external_imports(backup_source)),
            "CB-0807": ("#760 y el entregable #625 permanecen abiertos" in backup_doc
                        and b["readiness"] == UNKNOWN),
            "CB-0808": ("verdadera con evidencia independiente, nunca por un fixture verde" in backup_doc
                        and b["backup_verified"] is False and operational_status(b) == UNKNOWN),
            "CB-0809": ("El adaptador ops/factory/deploy evalúa fixtures sintéticos" in deploy_doc
                        and dp.returncode == 0 and d["deploy_executed"] is False
                        and no_external_imports(deploy_source)),
            "CB-0810": ("nunca evidencia productiva autenticada" in deploy_doc
                        and d["production_ready"] is False and operational_status(d) == UNKNOWN),
            "CB-0811": (mismatch["reason"] == "baseline_sha_mismatch"
                        and same["reason"] == "no_release_transition"),
            "CB-0812": all(execute(DEPLOY, deploy_fixture(**{key: value}))[1]["status"] == "REJECTED"
                            for key, value in (("kind", "production"), ("phase", "live"),
                                               ("transport", "ssh"))),
            "CB-0813": (set(d) == {"mode", "status", "reason", "deploy_executed",
                                   "production_ready", "readiness"}
                        and d["deploy_executed"] is False
                        and d["production_ready"] is False and d["readiness"] == UNKNOWN),
            "CB-0814": ("NO realiza un rollback verdadero" in rollback_doc
                        and rp.returncode == 0 and r["rollback_executed"] is False),
            "CB-0815": ("Solo " in rollback_doc and "8192 bytes" in rollback_doc
                        and set(rollback_fixture()) == {
                            "kind", "phase", "plan_kind", "candidate_sha",
                            "previous_healthy_sha", "expected_healthy_sha",
                            "baseline_verified", "previous_artifact_verified",
                            "rollback_plan_verified"}),
            "CB-0816": ("NO se contrastan contra GitHub/Hostinger" in rollback_doc
                        and rejected["reason"] == "healthy_sha_mismatch"
                        and r["reason"] == "synthetic_checks_passed"
                        and operational_status(r) == UNKNOWN),
            "CB-0817": (set(r) == {"mode", "status", "reason", "rollback_executed",
                                   "production_ready", "readiness"}
                        and rp.stdout.count("\n") == 1 and rp.stderr == ""
                        and (rp.returncode, r["status"], r["readiness"])
                        == (0, "SYNTHETIC_ONLY", UNKNOWN)),
            "CB-0818": ("No llama a procesos externos, red, SSH, Hostinger, git" in rollback_doc
                        and "DEPLOY_ENABLED apagado" in rollback_doc
                        and no_external_imports(rollback_source)
                        and all(marker not in rp.stdout for marker in (
                            "ROLLBACK_VERIFIED", "receipt_id", "restored_at"))),
            "CB-0819": ("python3 -m unittest tests/test_factory_rollback_preflight.py -v" in rollback_doc
                        and len(original_tests) == 4
                        and "test_real_mode_and_external_side_effects_denied" in rollback_test),
        }
        self.assertEqual(set(rows), set(IDS))
        self.assertEqual(set(checks), set(IDS))
        self.assertEqual((bp.returncode, b["status"]), (0, "SYNTHETIC_ONLY"))
        for name, passed in checks.items():
            with self.subTest(clause=name):
                self.assertTrue(passed, "sin evidencia offline suficiente: " + name)
                self.assertTrue(e3_coverage(rows[name]))

    def test_falla_cerrado(self) -> None:
        """AC-02: mode, tamaño, extras, SHA, flags y duplicados no pasan."""
        cases = (
            (BACKUP, backup_fixture(artifact_verified=False), "artifact_unverified"),
            (BACKUP, backup_fixture(rollback_verified=False), "rollback_unverified"),
            (DEPLOY, deploy_fixture(candidate_sha="a" * 39), "invalid_synthetic_fixture"),
            (DEPLOY, deploy_fixture(backup_verified=False), "backup_unverified"),
            (ROLLBACK, rollback_fixture(baseline_verified=False), "baseline_unverified"),
            (ROLLBACK, rollback_fixture(previous_artifact_verified=False), "previous_artifact_unverified"),
            (ROLLBACK, rollback_fixture(rollback_plan_verified=False), "rollback_plan_unverified"),
            (ROLLBACK, rollback_fixture(plan_kind="delete_files"), "invalid_synthetic_fixture"),
            (ROLLBACK, rollback_fixture(rollback_plan_verified=1), "invalid_synthetic_fixture"),
            (ROLLBACK, rollback_fixture(path="/var/secret"), "invalid_synthetic_fixture"),
        )
        for path, sample, reason in cases:
            with self.subTest(adapter=path.name, reason=reason):
                process, result = execute(path, sample)
                self.assertEqual((process.returncode, result["status"], result["reason"]),
                                 (2, "REJECTED", reason))
                self.assertEqual(result["readiness"], UNKNOWN)
        for flags in ((), ("--real",), ("--live",), ("--offline-dry-run", "--real")):
            process, result = execute(ROLLBACK, rollback_fixture(), args=flags)
            self.assertEqual((process.returncode, result["reason"]), (2, "mode_not_authorized"))
        for bad in ("", "{", "x" * 8193, '{"kind":"synthetic","kind":"real"}',
                    '{"a":NaN}', "[1,2,3]"):
            with self.subTest(invalid=bad[:25]):
                process, result = execute(ROLLBACK, rollback_fixture(), raw=bad)
                self.assertEqual((process.returncode, result["reason"]),
                                 (2, "invalid_synthetic_fixture"))
                self.assertEqual(process.stdout.count("\n"), 1)
                self.assertEqual(process.stderr, "")
                self.assertEqual(result["readiness"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: un PATH señuelo demuestra cero procesos externos o ficheros."""
        with TemporaryDirectory(prefix="controlbot-1060-offline-") as tmp:
            work = Path(tmp)
            bin_dir = work / "bin"
            bin_dir.mkdir()
            sentinel = work / "outside-invoked"
            for command in ("ssh", "scp", "curl", "wget", "rsync", "git", "gh", "hostinger"):
                tool = bin_dir / command
                tool.write_text('#!/bin/sh\nprintf invoked > "' + str(sentinel) + '"\nexit 97\n',
                                encoding="utf-8")
                tool.chmod(0o700)
            env = os.environ.copy()
            env["PATH"] = str(bin_dir) + os.pathsep + env.get("PATH", "")
            marker = "synthetic-secret-never-echo"
            env["HOSTINGER_SSH_HOST"] = "invalid.example"
            env["DEPLOY_SSH_KEY"] = marker
            for path, sample in ((BACKUP, backup_fixture()),
                                 (DEPLOY, deploy_fixture()),
                                 (ROLLBACK, rollback_fixture())):
                with self.subTest(adapter=path.name):
                    proc, result = execute(path, sample, cwd=work, env=env)
                    self.assertEqual((proc.returncode, result["status"]), (0, "SYNTHETIC_ONLY"))
                    self.assertEqual(operational_status(result), UNKNOWN)
                    self.assertFalse(sentinel.exists())
                    proc, result = execute(path, {**sample, "password": marker},
                                           cwd=work, env=env)
                    self.assertEqual((proc.returncode, result["reason"]),
                                     (2, "invalid_synthetic_fixture"))
                    self.assertNotIn(marker, proc.stdout + proc.stderr)
                    self.assertFalse(sentinel.exists())
            self.assertEqual([p.name for p in work.iterdir()], ["bin"])
        for path in (BACKUP, DEPLOY, ROLLBACK):
            self.assertTrue(no_external_imports(path.read_text(encoding="utf-8")))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: nunca inferir operación live de una fila o recibo sintético."""
        rows = matrix_rows()
        self.assertEqual(set(rows), set(IDS))
        for name in IDS:
            with self.subTest(clause=name):
                self.assertTrue(e3_coverage(rows[name]))
                self.assertEqual(operational_status(rows[name]), UNKNOWN)
        outputs = ((BACKUP, backup_fixture(), "backup_verified", "live_authorized"),
                   (DEPLOY, deploy_fixture(), "deploy_executed", "production_ready"),
                   (ROLLBACK, rollback_fixture(), "rollback_executed", "production_ready"))
        for path, sample, executed, authorized in outputs:
            _, result = execute(path, sample)
            self.assertIs(result[executed], False)
            self.assertIs(result[authorized], False)
            self.assertEqual(result["readiness"], UNKNOWN)
            self.assertEqual(operational_status(result), UNKNOWN)
        for forged in (None, {}, {"run": "success", "sha": "a" * 40},
                       {"status": "VALIDATED_IN_PRODUCTION"},
                       {"rollback_executed": True, "receipt": "synthetic"}):
            self.assertEqual(operational_status(forged), UNKNOWN)


if __name__ == "__main__":
    unittest.main()

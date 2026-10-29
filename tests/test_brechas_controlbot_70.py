#!/usr/bin/env python3
"""ControlBot #1077: quince brechas CB-1072..1086, evidencia local cerrada.

Los fixtures prueban solo código local sintético: no validan mantenimiento real,
perímetro, autorización del dueño, CI, despliegue ni producción.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest
from tests import test_owner_login_gate as owner

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
PARTIAL = "CONTRATO_LOCAL_PARCIAL"
GAP = "BRECHA_DOCUMENTADA"
IDS = tuple(f"CB-{i:04d}" for i in range(1072, 1087))
MAIN = "docs/matriz-cobertura.md"
PART4 = "docs/matriz-cobertura-parte-04.md"
SECURITY = "docs/security-event-v1.md"
THROTTLE = "docs/security/owner-login-throttle.md"
STAFF = "docs/staff-control-plane.md"
OWNER_CODE = "src/OwnerLoginGate.php"
STAFF_CODE = "src/StaffControlPlane.php"
SOURCE = {
    **{f"CB-{i:04d}": (THROTTLE, line) for i, line in {
        1073: 6, 1074: 7, 1075: 8, 1076: 12, 1077: 15,
        1078: 16, 1079: 17, 1080: 19, 1081: 23,
        1082: 25, 1083: 27,
    }.items()},
    "CB-1072": (SECURITY, 25),
    "CB-1084": (STAFF, 3),
    "CB-1085": (STAFF, 5),
    "CB-1086": (STAFF, 7),
}
CLAUSE = {
    "CB-1072": "DB, red, provider/email calls",
    "CB-1073": "El contador persistente se registra únicamente por IP",
    "CB-1074": "Se elimina el bloqueo global basado en",
    "CB-1075": "el límite por IP no elimina ataques distribuidos",
    "CB-1076": "Este documento solo describe la",
    "CB-1077": "El operador autorizado verifica que el directorio",
    "CB-1078": "Inspeccionar",
    "CB-1079": "Para recuperación del dueño",
    "CB-1080": "La implementación de una operación de purga",
    "CB-1081": "Se revisó",
    "CB-1082": "trabajo OFFLINE fase",
    "CB-1083": "revertir el squash de #922",
    "CB-1084": "El boundary read-only consume únicamente",
    "CB-1085": "normaliza conteos agregados y freshness",
    "CB-1086": "Este slice no ejecuta invitaciones",
}
FILES = {MAIN, PART4, SECURITY, THROTTLE, STAFF, OWNER_CODE, STAFF_CODE, "datos.yml"}
LOCAL = ("CB-1073", "CB-1074", "CB-1084", "CB-1085")


def read_sources() -> dict[str, str]:
    """Solo fuentes versionadas; ningún servicio externo ni dato de cliente."""
    return {p: (ROOT / p).read_text(encoding="utf-8") for p in FILES}


def matrix_rows(files: object) -> dict[str, tuple[str, ...]]:
    if type(files) is not dict or any(type(files.get(p)) is not str for p in (MAIN, PART4)):
        raise ValueError("UNKNOWN: faltan matrices")
    found = {}
    for path in (MAIN, PART4):
        for line in files[path].splitlines():
            match = re.match(r"^\|\s*(CB-\d{4})\s*\|", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
            src, number = SOURCE[key]
            expected = MAIN if key in ("CB-1073", "CB-1084") else PART4
            if (key in found or path != expected or len(cells) != 6
                    or cells[0] != key or f"{src}#L{number}" not in cells[1]
                    or CLAUSE[key] not in cells[2]
                    or cells[3] != "NO_VERIFICABLE"
                    or "Falta contraste de comportamiento con prueba y PR concretos." not in cells[4]
                    or not all(f"{x}=unknown" in cells[4].lower()
                               for x in ("offline", "live", "operando"))
                    or "/issues/1" not in cells[5]):
                raise ValueError("UNKNOWN: fila inválida o duplicada " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: faltan filas de cobertura")
    return found


@lru_cache(maxsize=None)
def php_scenario(name: str) -> dict:
    if name not in {"summary", "search", "invalid", "authority"}:
        raise AssertionError("UNKNOWN: escenario no autorizado")
    executable = shutil.which("php")
    if executable is None:
        raise AssertionError("UNKNOWN: PHP no disponible")
    result = subprocess.run(
        [executable, str(ROOT / "tests/staff_control_plane_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: fixture PHP falló")
    try:
        output = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: salida PHP inválida") from exc
    if type(output) is not dict or not output:
        raise AssertionError("UNKNOWN: salida PHP vacía")
    return output


@lru_cache(maxsize=1)
def local_probes() -> dict[str, bool]:
    """Ejercita las pruebas PHP de login existentes y la proyección Staff sintética."""
    owner.OwnerLoginGateTests("test_blocked_ip_does_not_create_username_files").test_blocked_ip_does_not_create_username_files()
    owner.OwnerLoginGateTests("test_cross_ip_owner_login_is_not_globally_locked").test_cross_ip_owner_login_is_not_globally_locked()
    summary, search, invalid = (php_scenario(x) for x in ("summary", "search", "invalid"))
    s, records = summary["valid"], search["valid"]
    return {
        "CB-1073": True, "CB-1074": True,
        "CB-1084": (s["population"] == "staff_only" and summary["customer_boundary"] is True
                    and all(invalid[x] is True for x in
                            ("customer", "ambiguous", "cross_project", "extra_sensitive"))),
        "CB-1085": (s["active_staff"] == 2 and s["suspended_staff"] == 1
                    and records["directory_persisted"] is False
                    and search["raw_email_leaked"] is False
                    and all("email" not in row and "masked_email" in row
                            for row in records["records"])),
    }


def evidence(key: object, files: object, probes: object) -> dict[str, str]:
    out = {"local": UNKNOWN, "ci": UNKNOWN, "live": UNKNOWN,
           "production": UNKNOWN, "maintenance": UNKNOWN, "approval": UNKNOWN}
    if type(key) is not str or key not in IDS or type(files) is not dict or type(probes) is not dict:
        return out
    if set(files) != FILES or any(type(files[p]) is not str or not files[p] for p in FILES):
        return out
    try:
        matrix_rows(files)
    except ValueError:
        return out
    doc, _ = SOURCE[key]
    if CLAUSE[key] not in files[doc]:
        return out
    if key in LOCAL:
        src = files[OWNER_CODE] if key in ("CB-1073", "CB-1074") else files[STAFF_CODE]
        markers = {
            "CB-1073": ("hash_hmac('sha256', $ip, $hash)", "hash('sha256', $ipKey)", "flock($handle, LOCK_EX)"),
            "CB-1074": ("$ipKey = 'ip:'", "hash_equals($config['owner_login'], $login)"),
            "CB-1084": ("'population'=>'staff_only'", "Staff summary boundary invalid."),
            "CB-1085": ("'masked_email'=>self::maskEmail", "'directory_persisted'=>false"),
        }[key]
        if probes.get(key) is True and all(s in src for s in markers):
            out["local"] = PARTIAL
    else:
        # No hay ejecución verificable de cron, purga, accesos, producción,
        # rescate de owner ni exclusividad del mantenimiento: no inventarla.
        out["local"] = GAP
    return out


def operational_attestation(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasControlbot70Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        files = read_sources()
        self.assertEqual(set(matrix_rows(files)), set(IDS))
        probes = local_probes()
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(evidence(key, files, probes)["local"],
                                 PARTIAL if key in LOCAL else GAP)
                self.assertEqual(operational_attestation(key), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        files = read_sources()
        probes = {key: True for key in LOCAL}
        for key in IDS:
            path = MAIN if key in ("CB-1073", "CB-1084") else PART4
            line = next(s for s in files[path].splitlines() if s.startswith("| " + key + " |"))
            for changed in (files[path].replace(line, "", 1), files[path] + "\n" + line,
                            files[path].replace(line, line.replace("NO_VERIFICABLE", "VERIFICADO"), 1)):
                altered = {**files, path: changed}
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
                    self.assertEqual(evidence(key, altered, probes)["local"], UNKNOWN)
            doc, _ = SOURCE[key]
            self.assertEqual(evidence(key, {**files, doc: ""}, probes)["local"], UNKNOWN)
        for key in LOCAL:
            self.assertEqual(evidence(key, files, {**probes, key: False})["local"], UNKNOWN)
        self.assertEqual(evidence("CB-9999", files, probes)["local"], UNKNOWN)
        self.assertEqual(evidence("CB-1072", None, probes)["local"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        code = (ROOT / "tests/test_brechas_controlbot_70.py").read_text(encoding="utf-8")
        imported = set()
        for node in ast.walk(ast.parse(code)):
            if isinstance(node, ast.Import):
                imported.update(x.name.split(".")[0] for x in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "functools", "json", "pathlib", "re",
                                        "shutil", "subprocess", "unittest", "tests"})
        self.assertNotIn("requests", imported)
        self.assertNotIn("httpx", imported)
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(data["phase"], "construccion")
        self.assertEqual(data["d063_attestation"], {"nothing_live": True, "no_real_customer_data": True})
        account = next(row for row in data["treatments"] if row["id"] == "owner_login_credentials")
        self.assertEqual(account["fields"], ["password_hash"])
        search = php_scenario("search")
        self.assertFalse(search["raw_email_leaked"])
        self.assertNotIn("ada@example.com", json.dumps(search["valid"]))

    def test_estados_unknown_explicitos(self) -> None:
        files, probes = read_sources(), {key: True for key in LOCAL}
        for key in IDS:
            row = evidence(key, files, probes)
            for field in ("ci", "live", "production", "maintenance", "approval"):
                self.assertEqual(row[field], UNKNOWN, (key, field))
            for forged in (None, True, {"ci": "success"}, {"approved": True},
                           {"deployed": True}, {"status": "VALIDATED_IN_PRODUCTION"}):
                self.assertEqual(operational_attestation(key, forged), UNKNOWN)
        self.assertEqual(evidence(None, files, probes)["local"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()

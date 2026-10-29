#!/usr/bin/env python3
"""ControlBot #1070: independent, synthetic contract for CB-0967..CB-0981.

Local behavior in repository PHP scenarios is not evidence of Hostinger
configuration, live HTTP, backup, authorization to deploy or owner approval.
Missing, ambiguous and historical evidence remains UNKNOWN (fail-closed).
No matrix row is promoted by this suite.
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

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
PART4 = "docs/matriz-cobertura-parte-04.md"
MAIN = "docs/matriz-cobertura.md"
ENTRY_DOC = "docs/public-orchestrator-entrypoint.md"
RECON_DOC = "docs/reconciliacion-issues-20261024.md"
RECOVERY_DOC = "docs/recovery-center-web.md"
WEB = "src/FactoryOrchestratorWebEntrypoint.php"
ENDPOINT = "src/FactoryOrchestratorLiveEndpoint.php"
CACHE = "src/FactoryOrchestratorLiveCache.php"
OWNER = "src/ControlBotWebEntrypoint.php"
RECOVERY = "src/RecoveryCenterWebEntrypoint.php"
WEB_CASES = "tests/factory_orchestrator_web_entrypoint_scenarios.php"
RECOVERY_CASES = "tests/recovery_center_web_scenarios.php"

IDS = tuple(f"CB-{i:04d}" for i in range(967, 982))
# Each entry anchors exactly one historical matrix row to its canonical document.
CLAUSES = {
    "CB-0967": (PART4, ENTRY_DOC, "caché local atómica"),
    "CB-0968": (PART4, ENTRY_DOC, "Antes de fusionar el frente live"),
    "CB-0969": (PART4, ENTRY_DOC, "Una plantilla incompleta"),
    "CB-0970": (PART4, ENTRY_DOC, "En hPanel: proteger"),
    "CB-0971": (PART4, ENTRY_DOC, "sin credenciales no debe devolver 200"),
    "CB-0972": (PART4, ENTRY_DOC, "/src/"),
    "CB-0973": (PART4, ENTRY_DOC, "snapshot ausente/corrupto/>300 segundos"),
    "CB-0974": (PART4, ENTRY_DOC, "Nada aquí autoriza modificar DNS"),
    "CB-0975": (MAIN, RECON_DOC, "Fuente canónica:"),
    "CB-0976": (PART4, RECON_DOC, "Regla de reconciliación"),
    "CB-0977": (PART4, RECON_DOC, "Resultado del corte:"),
    "CB-0978": (PART4, RECON_DOC, "Control de autoridad:"),
    "CB-0979": (MAIN, RECOVERY_DOC, "TOTP válidos del dueño"),
    "CB-0980": (PART4, RECOVERY_DOC, "La página consulta solo el perfil declarativo"),
    "CB-0981": (PART4, RECOVERY_DOC, "RPO/RTO como"),
}
LOCAL_KEYS = frozenset(("CB-0967", "CB-0971", "CB-0973",
                        "CB-0979", "CB-0980", "CB-0981"))
# These require an external observation, a historical authorization, or live
# owner evidence. A document and a fake HTTP response cannot satisfy them.
EXTERNAL_KEYS = frozenset(IDS) - LOCAL_KEYS
FILES = frozenset((MAIN, PART4, ENTRY_DOC, RECON_DOC, RECOVERY_DOC,
                   WEB, ENDPOINT, CACHE, OWNER, RECOVERY,
                   WEB_CASES, RECOVERY_CASES))


def read_sources() -> dict[str, str]:
    """Only committed UTF-8 sources; no service, database or network."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in FILES}


def matrix_rows(data: object) -> dict[str, tuple[str, ...]]:
    """Fail closed if any of the 15 rows is missing, duplicated, or promoted."""
    if (type(data) is not dict or set(data) != set(FILES)
            or any(type(data[path]) is not str or not data[path]
                   for path in FILES)):
        raise ValueError("UNKNOWN: missing source")
    result: dict[str, tuple[str, ...]] = {}
    for matrix in (MAIN, PART4):
        for line in data[matrix].splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if match is None or match.group(1) not in CLAUSES:
                continue
            key = match.group(1)
            expected_matrix, doc, phrase = CLAUSES[key]
            cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
            if (key in result or matrix != expected_matrix
                    or len(cells) != 6 or not all(cells)
                    or cells[0] != key or doc not in cells[1]
                    or phrase not in cells[2]
                    or cells[3] != "NO_VERIFICABLE"
                    or "Falta contraste de comportamiento con prueba y PR concretos."
                       not in cells[4]
                    or not all(f"{state}=UNKNOWN" in cells[4]
                               for state in ("Offline", "live", "operando"))
                    or "/issues/" not in cells[5]):
                raise ValueError("UNKNOWN: malformed or promoted " + key)
            result[key] = cells
    if set(result) != set(IDS):
        raise ValueError("UNKNOWN: missing IDs")
    return result


@lru_cache(maxsize=8)
def scenario(group: str, case: str) -> dict[str, object]:
    """Invoke an allowlisted synthetic local PHP fixture, never live HTTP."""
    choices = {"orchestrator": ("auth", "unknown"),
               "recovery": ("owner", "guards", "invalid-profile")}
    if group not in choices or case not in choices[group]:
        raise ValueError("UNKNOWN: disallowed fixture")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP runner unavailable")
    file = WEB_CASES if group == "orchestrator" else RECOVERY_CASES
    result = subprocess.run(
        [php, str(ROOT / file), case], cwd=ROOT, capture_output=True,
        text=True, timeout=30, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError("UNKNOWN: fixture failed")
    try:
        decoded = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: fixture not JSON") from exc
    if type(decoded) is not dict or not decoded:
        raise AssertionError("UNKNOWN: empty fixture evidence")
    return decoded


def sources_support(key: object, data: object) -> bool:
    if (type(key) is not str or key not in LOCAL_KEYS
            or type(data) is not dict or set(data) != set(FILES)):
        return False
    markers = {
        "CB-0967": ((WEB, "controlbot-orchestrator-live-"),
                    (WEB, "hash('sha256', $source['cache_identity'])"),
                    (CACHE, "tempnam("), (CACHE, "rename(")),
        "CB-0971": ((ENDPOINT, "hash_equals($config['owner_login'], $user)"),
                    (ENDPOINT, "self::response(403,"),
                    (ENDPOINT, "self::response(503,")),
        "CB-0973": ((WEB, "MAX_AGE = 300"),
                    (WEB, "validConfiguredPath("),
                    (WEB, "private static function unknown(")),
        "CB-0979": ((OWNER, "OwnerAuthenticatedRouter::verifiedOwner("),
                    (OWNER, "RecoveryCenterWebEntrypoint::handle($server)"),
                    (RECOVERY_CASES, "'forged_user'")),
        "CB-0980": ((RECOVERY, "/config/recovery/controlbot.json"),
                    (RECOVERY, "32768"),
                    (RECOVERY, "RecoveryCenterUi::project($profile, null, null, null, null)")),
        "CB-0981": ((RECOVERY, "'dr_status'"),
                    (RECOVERY, "'UNKNOWN'"),
                    (RECOVERY, "RPO objetivo"),
                    (RECOVERY, "RTO objetivo")),
    }
    try:
        return (all(type(data[path]) is str and token in data[path]
                    for path, token in markers[key])
                and CLAUSES[key][2] in data[CLAUSES[key][1]])
    except (TypeError, KeyError):
        return False


def probe_result(key: object, probes: object) -> bool:
    if type(key) is not str or key not in LOCAL_KEYS or type(probes) is not dict:
        return False
    try:
        if key == "CB-0967":
            auth = probes["orchestrator.auth"]
            unknown = probes["orchestrator.unknown"]
            return (auth["wrong"]["status"] == 403
                    and set(unknown) == {"missing", "invalid", "stale"}
                    and all(x["status"] == 200
                            and json.loads(x["body"])["snapshot"]["central"]["activity_state"] == UNKNOWN
                            for x in unknown.values()))
        if key == "CB-0971":
            data = probes["orchestrator.auth"]
            return (data["wrong"]["status"] == 403
                    and data["missing"]["status"] == 503
                    and data["config"]["status"] == 503
                    and all(x["status"] != 200 for x in data.values()))
        if key == "CB-0973":
            data = probes["orchestrator.unknown"]
            return (set(data) == {"missing", "invalid", "stale"}
                    and all(x["status"] == 200
                            and json.loads(x["body"])["snapshot"]["central"]["activity_state"] == UNKNOWN
                            and json.loads(x["body"])["snapshot"]["fronts"] == []
                            for x in data.values()))
        if key == "CB-0979":
            guards = probes["recovery.guards"]
            return (guards["anonymous"]["status"] == 403
                    and guards["post"]["status"] == 405
                    and guards["forged_user"]["status"] != 200)
        if key == "CB-0980":
            owner = probes["recovery.owner"]
            invalid = probes["recovery.invalid-profile"]
            return (owner["status"] == 200 and "no-store" == owner["headers"]["Cache-Control"]
                    and all(v["status"] == 503 and v["body"] == "Service unavailable.\n"
                            for v in invalid.values()))
        if key == "CB-0981":
            owner = probes["recovery.owner"]
            html = owner["body"]
            return (owner["status"] == 200
                    and all(term in html for term in
                            ("UNKNOWN", "RPO objetivo", "RTO objetivo", "Copia externa"))
                    and "HEALTHY" not in html
                    and not any(fragment in html.lower()
                                for fragment in ("<script", "<form", "<iframe")))
    except (KeyError, TypeError, ValueError, AttributeError):
        return False
    return False


def evidence(key: object, data: object, probes: object) -> str:
    """Prove only a bounded local contract; NEVER attest live readiness."""
    if type(key) is not str or key not in CLAUSES or type(data) is not dict:
        return UNKNOWN
    try:
        matrix_rows(data)
    except ValueError:
        return UNKNOWN
    doc = CLAUSES[key][1]
    if type(data.get(doc)) is not str or CLAUSES[key][2] not in data[doc]:
        return UNKNOWN
    if key not in LOCAL_KEYS:
        return UNKNOWN
    return LOCAL if sources_support(key, data) and probe_result(key, probes) else UNKNOWN


def operational_state(_evidence: object) -> str:
    """Real-tenant, DNS, backup, production and approval are unavailable here."""
    return UNKNOWN


def local_probes() -> dict[str, dict[str, object]]:
    return {f"{group}.{case}": scenario(group, case)
            for group, cases in (("orchestrator", ("auth", "unknown")),
                                 ("recovery", ("owner", "guards", "invalid-profile")))
            for case in cases}


class BrechasControlbot63Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = read_sources()
        cls.probes = local_probes()

    def test_contrato_principal(self) -> None:
        rows = matrix_rows(self.data)
        self.assertEqual(set(IDS), set(rows))
        self.assertEqual(15, len(rows))
        self.assertEqual(set(LOCAL_KEYS) | set(EXTERNAL_KEYS), set(IDS))
        self.assertFalse(set(LOCAL_KEYS) & set(EXTERNAL_KEYS))
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertEqual("NO_VERIFICABLE", rows[key][3])
                self.assertIn(CLAUSES[key][2], rows[key][2])
                expected = LOCAL if key in LOCAL_KEYS else UNKNOWN
                self.assertEqual(expected, evidence(key, self.data, self.probes))
                self.assertEqual(UNKNOWN, operational_state(key))

    def test_falla_cerrado(self) -> None:
        rows = matrix_rows(self.data)
        for key in IDS:
            matrix = CLAUSES[key][0]
            line = next(line for line in self.data[matrix].splitlines()
                        if line.startswith(f"| {key} |"))
            mutations = (
                self.data[matrix].replace(line, "", 1),
                self.data[matrix] + "\n" + line,
                self.data[matrix].replace(line, line.replace(
                    "NO_VERIFICABLE", "CUBIERTO"), 1),
                self.data[matrix].replace(line, line.replace(
                    "Offline=UNKNOWN", "Offline=GREEN"), 1),
            )
            for changed in mutations:
                with self.subTest(brecha=key, changed=changed[-32:]):
                    altered = {**self.data, matrix: changed}
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(altered)
                    self.assertEqual(UNKNOWN, evidence(key, altered, self.probes))
            doc = CLAUSES[key][1]
            broken = {**self.data, doc: ""}
            self.assertEqual(UNKNOWN, evidence(key, broken, self.probes))
        for key in LOCAL_KEYS:
            altered = {**self.data}
            del altered[CLAUSES[key][1]]
            self.assertEqual(UNKNOWN, evidence(key, altered, self.probes))
            with self.subTest(probe=key):
                self.assertEqual(UNKNOWN, evidence(key, self.data, {}))
        self.assertEqual(UNKNOWN, evidence(None, self.data, self.probes))
        self.assertEqual(UNKNOWN, evidence("CB-9999", self.data, self.probes))
        self.assertEqual(UNKNOWN, evidence("CB-0971", None, self.probes))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            scenario("remote", "owner")

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(FILES, set(self.data))
        self.assertTrue(all((ROOT / path).is_file() for path in FILES))
        self.assertTrue(all(not Path(path).is_absolute()
                            and ".." not in Path(path).parts for path in FILES))
        source = (ROOT / "tests/test_brechas_controlbot_63.py").read_text(
            encoding="utf-8")
        parsed = ast.parse(source)
        imports = {alias.name for node in ast.walk(parsed)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module or "" for node in ast.walk(parsed)
                    if isinstance(node, ast.ImportFrom)}
        for bad in ("requests", "httpx", "urllib", "socket",
                    "mysql", "pymysql", "psycopg2"):
            self.assertFalse(any(v == bad or v.startswith(bad + ".")
                                 for v in imports))
        self.assertNotIn("BEGIN " + "PRIVATE KEY", source)
        self.assertNotIn("DATABASE" + "_URL=", source)
        self.assertEqual({"orchestrator.auth", "orchestrator.unknown",
                          "recovery.owner", "recovery.guards",
                          "recovery.invalid-profile"}, set(self.probes))

    def test_estados_unknown_explicitos(self) -> None:
        rows = matrix_rows(self.data)
        for key in EXTERNAL_KEYS:
            with self.subTest(externo=key):
                self.assertEqual("NO_VERIFICABLE", rows[key][3])
                self.assertEqual(UNKNOWN, evidence(key, self.data, self.probes))
                self.assertEqual(UNKNOWN, operational_state({"id": key, "CI": "success"}))
        for fake in (None, "", {}, {"status": "GREEN"}, {"approved": True},
                     {"pr_merged": True, "owner": "fixture"},
                     {"hpanel": "configured", "backup": "complete"},
                     {"rpo_minutes": 15, "rto_minutes": 60}):
            with self.subTest(fake=repr(fake)):
                self.assertEqual(UNKNOWN, operational_state(fake))
        for key in LOCAL_KEYS:
            self.assertEqual(UNKNOWN, evidence(key, self.data,
                             {**self.probes,
                              "orchestrator.auth": {}} if key in
                             {"CB-0967", "CB-0971"} else {}))


if __name__ == "__main__":
    unittest.main()

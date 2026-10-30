#!/usr/bin/env python3
"""Coverage for ControlBot #1082, CB-1147..CB-1161: explicit fail-closed gaps.

This suite is synthetic and offline. Matching documentation, a green CI or
a caller-supplied receipt never attests to a live/operating integration.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import re
import unittest

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
PARTIAL = "CONTRATO_LOCAL_PARCIAL"
MAIN = "docs/matriz-cobertura.md"
PART4 = "docs/matriz-cobertura-parte-04.md"
AGENTS = "AGENTES.md"
DATA = "datos.yml"
CONTRAST = "docs/contraste-cb_0901_1189.md"
FILES = frozenset({MAIN, PART4, AGENTS, DATA, CONTRAST})
IDS = tuple(f"CB-{number:04d}" for number in range(1147, 1162))
POLICY_IDS = set(IDS[:5])
LOCAL_IDS = {"CB-1149", "CB-1150"}

# The short clauses are from the original normative sources, not an invented
# conclusion that the end-to-end obligations have been implemented.
CLAUSES = {
    "CB-1147": "se guardan ni escriben contraseñas de ChatGPT",
    "CB-1148": "Los tokens de GitHub y Sentry viven solo en",
    "CB-1149": "alias de cuentas, estados de agentes, bitácora",
    "CB-1150": "deploy y observación permanecen fail-closed",
    "CB-1151": "es el puente externo estable del navegador",
    "CB-1152": "Los proyectos actuales (ControlBot, Condor, BRVTAL y GrindFlow)",
    "CB-1153": "Se puede crear una idea sin repo",
    "CB-1154": "El dueño ve salud de proyectos, agentes y cuentas",
    "CB-1155": "Un mensaje/orden llega a la Session correcta",
    "CB-1156": "Rate limit/login/offline retiran capacidad automáticamente",
    "CB-1157": "El scheduler respeta dependencias, prioridades, reservas y políticas",
    "CB-1158": "Fallos de producción generan incidentes correlacionados",
    "CB-1159": "GitHub se administra mediante intenciones tipadas y auditadas",
    "CB-1160": "Pausar todos detiene nuevo trabajo de agentes en menos de 30 s",
    "CB-1161": "Ningún secreto/contraseña/token web queda expuesto",
}
# Every unproven clause has its own named missing proof; no silent success.
MISSING_PROOFS = {
    "CB-1147": "perimeter_password_and_captcha_noninterference",
    "CB-1148": "server_env_secret_storage_verified",
    "CB-1149": "privacy_notice_and_owner_approval",
    "CB-1150": "deployment_and_observer_runtime_gate",
    "CB-1151": "external_autofactory_change_boundary",
    "CB-1152": "four_real_project_repo_environment_links",
    "CB-1153": "idea_to_factory_governed_repo_end_to_end",
    "CB-1154": "owner_dashboard_live_multisource_health",
    "CB-1155": "correct_session_delivery_and_return_receipt",
    "CB-1156": "rate_limit_login_offline_work_recovery",
    "CB-1157": "live_scheduler_dependency_priority_lease_policy",
    "CB-1158": "production_incident_correlation_no_spam",
    "CB-1159": "authenticated_typed_audited_github_mutations",
    "CB-1160": "pause_all_under_30_seconds_and_inflight_safety",
    "CB-1161": "full_web_secret_nonexposure_audit",
}
SOURCE_REFS = {
    "CB-1147": "AGENTES.md:L10",
    "CB-1148": "AGENTES.md:L11",
    "CB-1149": "AGENTES.md:L15",
    "CB-1150": "AGENTES.md:L16",
    "CB-1151": "AGENTES.md:L17",
    **{f"CB-{number:04d}": f"issues/1:L{number - 1151}"
       for number in range(1152, 1162)},
}


def read_sources() -> dict[str, str]:
    """Read only tracked, non-sensitive project policy and matrix files."""
    return {name: (ROOT / name).read_text(encoding="utf-8") for name in FILES}


def matrix_rows(files: object) -> dict[str, tuple[str, ...]]:
    """Validate 15 unique original rows across the two canonical partitions."""
    if type(files) is not dict or any(type(files.get(p)) is not str or not files[p]
                                      for p in (MAIN, PART4)):
        raise ValueError("UNKNOWN: missing coverage partition")
    found: dict[str, tuple[str, ...]] = {}
    for path in (MAIN, PART4):
        for line in files[path].splitlines():
            match = re.match(r"^\s*\|\s*(CB-\d{4})\s*\|", line)
            if match is None or match.group(1) not in IDS:
                continue
            key = match.group(1)
            cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
            expected_partition = PART4 if key in POLICY_IDS else MAIN
            expected_reason = ("Fuente normativa sin verificación integral del comportamiento."
                               if key in POLICY_IDS else
                               "Aceptación integral o evidencia terminal no comprobada; NO cuenta como hoja ejecutable.")
            if (key in found or path != expected_partition or len(cells) != 6
                    or cells[0] != key or SOURCE_REFS[key] not in cells[1]
                    or CLAUSES[key].casefold() not in cells[2].casefold()
                    or not credible_offline_coverage_row(cells)
                    or (cells[3] == "NO_VERIFICABLE"
                        and expected_reason not in cells[4]
                        and not (cells[4].startswith(("B:", "C:", "D:"))
                                 and "falta evidencia" in cells[4]))
                    or "/issues/1" not in cells[5]):
                raise ValueError("UNKNOWN: invalid, promoted or duplicated row " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing IDs from coverage matrix")
    return found


def privacy_and_preproduction_contract(text: object) -> dict[str, bool]:
    """Structural evidence only: privacy declarations and no-live phase."""
    result = {"CB-1149": False, "CB-1150": False}
    if type(text) is not str:
        return result
    try:
        obj = json.loads(text)
        if type(obj) is not dict:
            return result
        treatments = obj.get("treatments")
        if type(treatments) is not list:
            return result
        by_id = {x["id"]: x for x in treatments
                 if type(x) is dict and type(x.get("id")) is str}
        if len(by_id) != len(treatments):
            return result
        expected = {
            "account_profiles": ("account_alias", "profile_alias"),
            "agent_status": ("agent_id", "last_heartbeat_at", "account_alias"),
            "audit_log": ("actor", "action", "evidence"),
            "chat_response_opt_in": ("response_text", "opt_in"),
        }
        controller = obj.get("controller")
        declared = all(
            key in by_id and type(by_id[key].get("fields")) is list
            and all(field in by_id[key]["fields"] for field in fields)
            and by_id[key].get("basis") == "review_required"
            and by_id[key].get("retention") == "review_required"
            for key, fields in expected.items()
        )
        required_keys = {"name", "identifier", "address", "rights_email"}
        placeholders = (type(controller) is dict
                        and set(controller) == required_keys
                        and all(value == "[COMPLETAR POR EL DUEÑO]"
                                for value in controller.values()))
        result["CB-1149"] = bool(declared and placeholders)
        result["CB-1150"] = (
            obj.get("phase") == "construccion"
            and obj.get("d063_attestation")
            == {"nothing_live": True, "no_real_customer_data": True}
            and placeholders
        )
    except (ValueError, KeyError, TypeError):
        return {"CB-1149": False, "CB-1150": False}
    return result


def evidence(key: object, files: object) -> dict[str, str]:
    """No fabricated green: structural contract != end-to-end/live proof."""
    out = {"local": UNKNOWN, "ci": UNKNOWN, "live": UNKNOWN,
           "operando": UNKNOWN, "production": UNKNOWN, "approval": UNKNOWN,
           "security": UNKNOWN}
    if type(key) is not str or key not in IDS or type(files) is not dict:
        return out
    if set(files) != FILES or any(type(files[p]) is not str or not files[p] for p in FILES):
        return out
    try:
        rows = matrix_rows(files)
    except ValueError:
        return out
    origin = AGENTS if key in POLICY_IDS else CONTRAST
    if (key in POLICY_IDS and CLAUSES[key].casefold() not in files[AGENTS].casefold()
            or key not in POLICY_IDS and re.search(
                r"(?m)^\|\s*" + re.escape(key) + r"\s*\|",
                files[CONTRAST],
            ) is None):
        return out
    if CLAUSES[key].casefold() not in rows[key][2].casefold():
        return out
    if key in LOCAL_IDS:
        if privacy_and_preproduction_contract(files[DATA])[key]:
            out["local"] = PARTIAL
    else:
        # A policy sentence or an indexed epic is not a runtime observation.
        out["local"] = GAP
    return out


def gap(key: object, files: object) -> dict[str, str]:
    """One explicit, unique unknown reason for every unproven acceptance."""
    if type(key) is not str or key not in MISSING_PROOFS:
        return {"status": UNKNOWN, "missing": "invalid_or_ambiguous_id"}
    if evidence(key, files)["local"] == UNKNOWN:
        return {"status": UNKNOWN, "missing": "source_or_local_evidence_missing"}
    return {"status": UNKNOWN, "missing": MISSING_PROOFS[key]}


def runtime_attestation(_key: object, _receipt: object = None) -> str:
    """Caller data cannot attest a live connector, secrets or a timed pause."""
    return UNKNOWN


class BrechasControlbot75Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: all 15 real clauses get exercised or an explicit gap."""
        files = read_sources()
        rows = matrix_rows(files)
        self.assertEqual(set(rows), set(IDS))
        self.assertEqual(set(CLAUSES), set(IDS))
        self.assertEqual(set(MISSING_PROOFS), set(IDS))
        self.assertEqual(len(set(MISSING_PROOFS.values())), len(IDS))
        for key in IDS:
            with self.subTest(id=key):
                self.assertTrue(credible_offline_coverage_row(rows[key]))
                self.assertEqual(PARTIAL if key in LOCAL_IDS else GAP,
                                 evidence(key, files)["local"])
                self.assertEqual(
                    {"status": UNKNOWN, "missing": MISSING_PROOFS[key]},
                    gap(key, files),
                )
                self.assertEqual(runtime_attestation(key), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicated, promoted and ambiguous evidence."""
        files = read_sources()
        for key in IDS:
            path = PART4 if key in POLICY_IDS else MAIN
            original = next(
                line for line in files[path].splitlines()
                if re.match(r"^\|\s*" + re.escape(key) + r"\s*\|", line)
            )
            alterations = (
                files[path].replace(original, "", 1),
                files[path] + "\n" + original,
                files[path] + "\n   " + original,
                files[path].replace(original, original.replace(
                    "NO_VERIFICABLE", "CUBIERTO", 1), 1),
                files[path].replace(original, re.sub(
                    r"(?i)offline=unknown", "offline=GREEN", original), 1),
            )
            for altered_text in alterations:
                altered = {**files, path: altered_text}
                with self.subTest(id=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
                    self.assertEqual(UNKNOWN, evidence(key, altered)["local"])
                    self.assertEqual("source_or_local_evidence_missing",
                                     gap(key, altered)["missing"])
            origin = AGENTS if key in POLICY_IDS else CONTRAST
            altered = {**files, origin: files[origin].replace(
                CLAUSES[key] if key in POLICY_IDS else key, "")}
            self.assertEqual(UNKNOWN, evidence(key, altered)["local"])
        self.assertEqual(UNKNOWN, evidence("CB-0000", files)["local"])
        self.assertEqual(UNKNOWN, evidence("CB-1147", None)["local"])
        self.assertEqual(UNKNOWN, evidence("CB-1152", {},)["local"])
        for key in LOCAL_IDS:
            self.assertEqual(UNKNOWN, evidence(
                key, {**files, DATA: "{}"})["local"])

    def test_controller_placeholder_requires_exact_shape(self) -> None:
        """AC-01: no partial privacy evidence for extra or missing owner keys."""
        files = read_sources()
        original = json.loads(files[DATA])
        valid_controller = original["controller"]
        expected_keys = {"name", "identifier", "address", "rights_email"}
        self.assertEqual(set(valid_controller), expected_keys)
        self.assertEqual(
            privacy_and_preproduction_contract(files[DATA]),
            {"CB-1149": True, "CB-1150": True},
        )

        invalid_controllers = (
            {"other": "[COMPLETAR POR EL DUEÑO]"},
            {**valid_controller, "other": "[COMPLETAR POR EL DUEÑO]"},
            {k: v for k, v in valid_controller.items() if k != "name"},
            {k: v for k, v in valid_controller.items() if k != "rights_email"},
            {},
            {**valid_controller, "address": "synthetic-not-placeholder"},
        )
        for controller in invalid_controllers:
            with self.subTest(controller=controller):
                altered_data = json.dumps({**original, "controller": controller})
                self.assertEqual(
                    privacy_and_preproduction_contract(altered_data),
                    {"CB-1149": False, "CB-1150": False},
                )
                altered = {**files, DATA: altered_data}
                for key in LOCAL_IDS:
                    self.assertEqual(evidence(key, altered)["local"], UNKNOWN)
                    self.assertEqual(
                        gap(key, altered)["missing"],
                        "source_or_local_evidence_missing",
                    )

    def test_contrast_requires_actual_table_row(self) -> None:
        """AC-02: an isolated ID mention is not a provenance table row."""
        files = read_sources()
        for key in IDS[5:]:
            with self.subTest(id=key):
                exact_row = r"(?m)^\|\s*" + re.escape(key) + r"\s*\|[^\n]*$"
                self.assertRegex(files[CONTRAST], exact_row)
                without_row = re.sub(exact_row, "", files[CONTRAST], count=1)
                # Keep the ID visible outside a table to reproduce the former bug.
                altered = {
                    **files,
                    CONTRAST: without_row + "\nReferencia aislada: " + key,
                }
                self.assertIn(key, altered[CONTRAST])
                self.assertEqual(evidence(key, altered)["local"], UNKNOWN)
                self.assertEqual(
                    gap(key, altered)["missing"],
                    "source_or_local_evidence_missing",
                )

    def test_sin_datos_reales(self) -> None:
        """AC-03: no network, secrets, credentials, personal data or IO writes."""
        files = read_sources()
        self.assertEqual(set(files), FILES)
        path = ROOT / "tests/test_brechas_controlbot_75.py"
        module = ast.parse(path.read_text(encoding="utf-8"))
        imported = set()
        for node in ast.walk(module):
            if isinstance(node, ast.Import):
                imported.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "json",
                                        "pathlib", "re", "unittest"})
        self.assertTrue(all(not Path(x).is_absolute() and ".." not in Path(x).parts
                            for x in FILES))
        checks = privacy_and_preproduction_contract(files[DATA])
        self.assertEqual(checks, {"CB-1149": True, "CB-1150": True})
        self.assertEqual(UNKNOWN, runtime_attestation("CB-1161", {"exposed": False}))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: fake success cannot certify a real end-to-end obligation."""
        files = read_sources()
        for key in IDS:
            with self.subTest(id=key):
                status = evidence(key, files)
                self.assertTrue(all(status[field] == UNKNOWN for field in
                                    ("ci", "live", "operando", "production",
                                     "approval", "security")))
                for forged in (None, True, {"ci": "success"},
                               {"deploy": "GREEN", "owner_approved": True},
                               {"pause_latency_seconds": 1},
                               {"all_secrets_protected": True},
                               {"verified": True, "scope": "entire_product"}):
                    self.assertEqual(UNKNOWN, runtime_attestation(key, forged))
                self.assertEqual(UNKNOWN, gap(key, files)["status"])
        self.assertEqual(
            {"CB-1149": False, "CB-1150": False},
            privacy_and_preproduction_contract('{"phase":"live"}'),
        )
        self.assertEqual(UNKNOWN, gap("CB-9999", files)["status"])


if __name__ == "__main__":
    unittest.main()

"""Cobertura offline de 15 brechas CB-0774..CB-0789, sin acreditar producción.

Las matrices mantienen NO_VERIFICABLE hasta contraste y PR fusionado;
los escenarios PHP ejercitan contratos con datos exclusivamente sintéticos.
"""

from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests"
MATRICES = (
    ROOT / "docs/matriz-cobertura.md",
    ROOT / "docs/matriz-cobertura-parte-03.md",
)
CLAUSES = {
    "CB-0774", "CB-0775", "CB-0776", "CB-0777", "CB-0778",
    "CB-0779", "CB-0781", "CB-0782", "CB-0783", "CB-0784",
    "CB-0785", "CB-0786", "CB-0787", "CB-0788", "CB-0789",
}


def scenario(script: str, case: str, *, html: bool = False):
    """Solo fixtures PHP del repositorio, sin conexiones externas."""
    result = subprocess.run(
        ["php", str(SCENARIOS / script), case],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=40,
    )
    return result.stdout if html else json.loads(result.stdout)


def rows_by_clause() -> dict[str, list[str]]:
    rows: dict[str, list[str]] = {}
    for matrix in MATRICES:
        for line in matrix.read_text(encoding="utf-8").splitlines():
            if not line.startswith("| CB-"):
                continue
            cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
            if cells[0] in CLAUSES:
                if cells[0] in rows:
                    raise AssertionError(f"Fila duplicada: {cells[0]}")
                rows[cells[0]] = cells
    return rows


def incident(case: str):
    return scenario("observability_incident_scenarios.php", case)


def router(case: str):
    return scenario("observability_policy_router_scenarios.php", case)


def projects(case: str):
    return scenario("observability_project_status_scenarios.php", case)


def backup(case: str):
    return scenario("controlbot_backup_scenarios.php", case)


def ui(case: str):
    return scenario("observability_ui_scenarios.php", case, html=True)


def source(data: dict, project_id: str, kind: str):
    return next(
        row["sources"][kind]
        for row in data["projects"] if row["project_id"] == project_id
    )


class BrechasControlbot51Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cada brecha tiene predicado ejecutado, no solo texto."""
        self.assertEqual(set(rows_by_clause()), CLAUSES)
        sample = incident("dedupe")["incidents"][0]
        freeze = router("freeze")
        blocked = router("blocked")
        missing = projects("missing-stale")
        backup_receipt = backup("backup")["valid"]
        restore_receipt = backup("restore")["valid"]
        page = ui("complete")
        ui_pure = scenario("observability_ui_scenarios.php", "pure")

        # Cada clave corresponde a una cláusula completa de las matrices.
        verdicts = {
            "CB-0774": (
                sample["version"] == 1
                and sample["incident_id"].startswith("incident:")
                and sample["project_id"] == "controlbot"
                and sample["status"] == "open"
                and sample["severity"] == "error"
                and re.fullmatch(r"[0-9a-f]{64}", sample["primary_fingerprint"])
                and sample["event_fingerprints"]
                and sample["correlation_reason"] == "new_incident"
                and sample["occurrence_count"] == 2
                and sample["opened_at"] <= sample["last_event_at"]
                and bool(sample["timeline_events"])
            ),
            "CB-0775": (
                not ({"_keys", "_evidence_keys", "raw_events", "correlation_state"}
                     & set(sample))
                and not any(key.startswith("_") for key in sample)
            ),
            "CB-0776": incident("pure")["hits"] == [],
            "CB-0777": (
                freeze["freeze"]["state"] == "unknown"
                and freeze["freeze"]["activated_at"] is None
                and freeze["write"]["pause_blocked"] is True
                and freeze["write"]["pause_allows"] is False
                and freeze["read"]["pause_allows"] is True
            ),
            "CB-0778": (
                blocked["stale"] is None
                and blocked["unknown"] is None
                and blocked["resolved"] is None
            ),
            "CB-0779": (
                router("classes")["critical"]["policy_ref"]
                == "policy:observability-v1"
                and blocked["scope_mismatch"] is True
            ),
            "CB-0781": (
                source(missing, "project-condor", "health")["freshness"] == "unknown"
                and source(missing, "project-condor", "health")["severity"] == "unknown"
            ),
            "CB-0782": (
                source(missing, "project-controlbot", "health")["freshness"] == "stale"
            ),
            "CB-0783": (
                "Vista read-only" in page
                and not any(ui_pure[key] for key in ("form", "input", "button"))
            ),
            "CB-0784": (
                backup_receipt["status"] == "completed"
                and len(backup_receipt["checksum"]) == 64
                and backup_receipt["size_bytes"] > 0
            ),
            "CB-0785": (
                restore_receipt["status"] == "passed"
                and restore_receipt["target_environment"] == "restore-drill"
                and restore_receipt["source_checksum"]
                == restore_receipt["destination_checksum"]
            ),
            "CB-0786": ui_pure["hits"] == []
                and not any(ui_pure[key] for key in ("form", "input", "button")),
            "CB-0787": all(
                text in page for text in (
                    "Monitor: healthy", "Último probe", "1.4.0",
                    "Último backup", "backup:controlbot-001",
                    "Último restore probado", "restore:controlbot-001",
                    "Restorable</dt><dd>sí", "Tamaño", "Cifrado",
                    "Entorno aislado", "Latencia", "HTTP", "SHA",
                )
            ) and all(
                scenario("observability_ui_scenarios.php", "redaction")[key]
                for key in (
                    "storage_ref_absent", "checksum_absent",
                    "evidence_ref_absent", "no_http_links",
                )
            ),
            "CB-0788": (
                "Monitor: unknown" in ui("missing")
                and "Sin evidencia de probe." in ui("missing")
                and "Sin evidencia de backup." in ui("missing")
                and "Restorable</dt><dd>no" in ui("missing")
            ),
            "CB-0789": (
                'name="viewport"' in page
                and ":focus-visible" in page
                and "prefers-reduced-motion" in page
                and "Canal externo</dt><dd>requerido" in ui("alert")
                and not any(ui_pure[key] for key in ("form", "input", "button"))
            ),
        }
        self.assertEqual(set(verdicts), CLAUSES)
        for clause, verified in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(bool(verified), clause)

    def test_falla_cerrado(self) -> None:
        """AC-02: rechazos reales y falta de evidencia no conceden permisos."""
        invalid = router("invalid")
        for key in (
            "extra", "secret", "occurrence", "empty_timeline", "reason",
            "missing_primary", "duplicate_fingerprint", "chronology",
            "class_drift", "channel_drift", "unknown_provenance",
        ):
            with self.subTest(router_case=key):
                self.assertIs(invalid[key], True)
        blocked = router("blocked")
        for key in ("stale", "unknown", "disabled", "resolved"):
            self.assertIsNone(blocked[key], key)
        self.assertTrue(blocked["scope_mismatch"])
        self.assertTrue(blocked["bad_provenance"])
        self.assertTrue(projects("ambiguous"))
        self.assertTrue(all(projects("invalid").values()))
        for key in ("size_rejected", "url_rejected", "secret_rejected"):
            self.assertIs(backup("backup")[key], True)
        for key in ("production_rejected", "checksum_rejected", "before_backup_rejected"):
            self.assertIs(backup("restore")[key], True)
        for html_case in ("no_restore", "invalid_restore", "orphan_restore"):
            with self.subTest(ui_case=html_case):
                rendered = ui(html_case)
                self.assertIn("Restorable</dt><dd>no", rendered)
                self.assertNotIn("Restorable</dt><dd>sí", rendered)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixturas aisladas, sin red ni secretos ni activación."""
        for script in (
            "observability_incident_scenarios.php",
            "observability_policy_router_scenarios.php",
            "controlbot_backup_scenarios.php",
            "observability_ui_scenarios.php",
        ):
            with self.subTest(script=script):
                self.assertEqual(scenario(script, "pure")["hits"], [])
        redacted = scenario("observability_ui_scenarios.php", "redaction")
        self.assertTrue(all(redacted.values()))
        page = ui("complete").lower()
        for confidential in (
            "vault:controlbot/backup-001",
            "evidence:restore/controlbot-001",
            "http://", "https://", "<form", "<input", "<button",
        ):
            self.assertNotIn(confidential, page)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: pruebas offline no certifican live/producción ni matriz."""
        rows = rows_by_clause()
        self.assertEqual(set(rows), CLAUSES)
        for clause, cells in rows.items():
            with self.subTest(clause=clause):
                self.assertEqual(cells[0], clause)
                self.assertEqual(cells[3], "NO_VERIFICABLE")
                self.assertIn("UNKNOWN", cells[4])
                self.assertIn("live=UNKNOWN", cells[4])
                self.assertIn("operando=UNKNOWN", cells[4])
        absent = projects("missing-stale")
        for project_id, kind in (
            ("project-controlbot", "ci"),
            ("project-controlbot", "deploy"),
            ("project-condor", "health"),
        ):
            self.assertEqual(source(absent, project_id, kind)["freshness"], "unknown")
        self.assertEqual(source(absent, "project-controlbot", "health")["freshness"], "stale")
        self.assertIn("Monitor: unknown", ui("missing"))
        self.assertIn("Restorable</dt><dd>no", ui("missing"))
        self.assertIsNone(router("blocked")["unknown"])


if __name__ == "__main__":
    unittest.main()

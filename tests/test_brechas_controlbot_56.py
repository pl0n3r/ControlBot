#!/usr/bin/env python3
"""ControlBot #1063: contraste offline para CB-0850..0866.

Los escenarios PHP ejercitan código real con fixtures sintéticos; el resultado
no acredita transporte, autoridad owner, persistencia ni producción live.
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
MASTER = ROOT / "docs/matriz-cobertura.md"
PART = ROOT / "docs/matriz-cobertura-parte-03.md"
DELIVERY = ROOT / "src/OwnerBriefingDelivery.php"
INBOX = ROOT / "src/OwnerInbox.php"
PAUSE = ROOT / "src/PauseControl.php"
DOCS = (
    ROOT / "docs/owner-briefing-delivery-v1.md",
    ROOT / "docs/owner-inbox-contract.md",
    ROOT / "docs/pause-control-v1.md",
)
IDS = tuple("CB-" + str(n).zfill(4) for n in (
    850, 851, 852, 853, 854, 856, 857, 858, 859, 860, 861,
    863, 864, 865, 866,
))
UNKNOWN = "UNKNOWN"


def matrix_rows(texts: tuple[str, str] | None = None) -> dict[str, tuple[str, ...]]:
    if texts is None:
        texts = (MASTER.read_text(encoding="utf-8"), PART.read_text(encoding="utf-8"))
    rows: dict[str, tuple[str, ...]] = {}
    for content in texts:
        for line in content.splitlines():
            match = re.match(r"^\| (CB-\d{4}) \|", line)
            if not match or match.group(1) not in IDS:
                continue
            cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
            identifier = match.group(1)
            if identifier in rows or len(cells) != 6 or not all(cells):
                raise ValueError("UNKNOWN: duplicate/incomplete row " + identifier)
            if cells[3] != "NO_VERIFICABLE" or any(
                f"{plane}=unknown" not in cells[4].lower()
                for plane in ("offline", "live", "operando")
            ):
                raise ValueError("UNKNOWN: unproven coverage " + identifier)
            rows[identifier] = cells
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: incomplete clause set")
    return rows


@lru_cache(maxsize=None)
def php_scenario(script: str, scenario: str = "") -> dict[str, object]:
    if shutil.which("php") is None:
        raise AssertionError("UNKNOWN: PHP runtime unavailable")
    command = ["php", str(ROOT / "tests" / script)]
    if scenario:
        command.append(scenario)
    run = subprocess.run(command, cwd=ROOT, capture_output=True, text=True,
                         timeout=25, check=False)
    warnings = [line for line in run.stderr.splitlines() if line.strip()]
    if script == "owner_briefing_delivery_scenarios.php":
        warnings = [line for line in warnings if
                    "use statement with non-compound name 'InvalidArgumentException' has no effect"
                    not in line]
    if run.returncode != 0 or warnings:
        raise AssertionError("UNKNOWN: PHP scenario failed: " + script + ":" + scenario)
    try:
        result = json.loads(run.stdout)
    except (TypeError, ValueError) as exc:
        raise AssertionError("UNKNOWN: invalid PHP scenario output") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: expected PHP object")
    return result


def external_attestation(_: object = None) -> str:
    """La lectura de snapshots locales no otorga autoridad de producción."""
    return UNKNOWN


class BrechasControlbot56Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: cada cláusula prueba el comportamiento PHP o frontera exacta."""
        rows = matrix_rows()
        d = php_scenario("owner_briefing_delivery_scenarios.php")
        a = php_scenario("owner_inbox_scenarios.php", "authority")
        f = php_scenario("owner_inbox_scenarios.php", "freshness")
        p = php_scenario("pause_control_scenarios.php", "precedence")
        i = php_scenario("pause_control_scenarios.php", "invalid")
        e = php_scenario("pause_control_scenarios.php", "preemptibility")
        s = php_scenario("pause_control_scenarios.php", "scopes")
        first, retry, changed = d["first"], d["retry"], d["changed"]
        assert isinstance(first, dict) and isinstance(retry, dict)
        checks = {
            "CB-0850": (
                first["version"] == 1 and first["schedule_ref"].startswith("controlbot:schedule/")
                and isinstance(first["briefing"]["sections"], dict)
                and first["policy_ref"].startswith("controlbot:policy/")
            ),
            "CB-0851": (
                first["delivery_ref"] == retry["delivery_ref"]
                and first["dedupe_key"] == retry["dedupe_key"]
                and first["briefing_fingerprint"] == retry["briefing_fingerprint"]
                and changed["delivery_ref"] != first["delivery_ref"]
                and retry["reasons"] == ["duplicate"]
            ),
            "CB-0852": (
                first["delivery_status"] == "pending"
                and first["delivery_intent"]["delivery_ref"] == first["delivery_ref"]
                and retry["delivery_status"] == "suppressed"
                and retry["delivery_intent"] is None
            ),
            "CB-0853": (
                all(d[name]["delivery_intent"] is None
                    for name in ("stale", "blocked", "unavailable"))
                and bool(d["invalid_snapshot_rejected"])
                and set(d["stale"]["reasons"]) == {"scheduler_stale"}
            ),
            "CB-0854": (
                "public static function plan(" in DELIVERY.read_text(encoding="utf-8")
                and "Scheduler/Observability" in DOCS[0].read_text(encoding="utf-8")
                and "delivery_status" in DELIVERY.read_text(encoding="utf-8")
            ),
            "CB-0856": (
                a["decision"]["required_authority_level"] == "L4_OWNER"
                and a["decision"]["options_ref"] is not None
                and isinstance(a["decision"]["deadline_at"], int)
                and a["critical"]["decision_ref"] is None
            ),
            "CB-0857": (
                a["decision"]["decision_ref"] is not None
                and a["critical"]["required_authority_level"] == "L4_OWNER"
                and a["missing"] is True and a["fyi_authority"] is True
            ),
            "CB-0858": (
                f["stale"]["freshness"] == "stale"
                and f["stale"]["source_ref"] is not None
                and isinstance(f["stale"]["observed_at"], int)
                and f["current_without_source"] is True
            ),
            "CB-0859": (
                f["unknown"]["freshness"] == "unknown"
                and f["unknown"]["source_ref"] is None
                and f["unknown"]["evidence_refs"] == []
                and f["unknown"]["observed_at"] is None
                and f["unknown_with_source"] is True
            ),
            "CB-0860": (
                f["stale"]["freshness"] == "stale"
                and f["unknown"]["freshness"] == "unknown"
                and external_attestation(f) == UNKNOWN
            ),
            "CB-0861": (
                "public static function entry(" in INBOX.read_text(encoding="utf-8")
                and "DecisionRights" in DOCS[1].read_text(encoding="utf-8")
                and "no hay persistencia" in DOCS[1].read_text(encoding="utf-8").lower()
            ),
            "CB-0863": (
                all(s[scope]["effective_scope"] == scope
                    for scope in ("session", "account", "project", "global"))
                and p["forward"]["effective_scope"] == "global"
                and p["reverse"]["effective_scope"] == "global"
                and p["without_global"]["effective_scope"] == "project"
            ),
            "CB-0864": (
                i["invalid"] is True and i["foreign"] is True
                and i["unknown"]["blocked"] is True
                and i["unknown"]["mutation_allowed"] is False
            ),
            "CB-0865": (
                e["immediate"]["preemptibility"] == "immediate"
                and e["immediate"]["state"] == "active"
                and e["immediate"]["safe_point_at"] is None
            ),
            "CB-0866": (
                e["safe_point_missing"] is True
                and e["non_preemptible_missing"] is True
                and e["non_preemptible"]["safe_point_at"] <= e["non_preemptible"]["activated_at"]
            ),
        }
        self.assertEqual(set(checks), set(IDS))
        for identifier in IDS:
            with self.subTest(clause=identifier):
                self.assertTrue(checks[identifier], "brecha sin aserción " + identifier)
                self.assertEqual(rows[identifier][3], "NO_VERIFICABLE")

    def test_falla_cerrado(self) -> None:
        """AC-02: filas ausentes/duplicadas y cobertura inventada son UNKNOWN."""
        master, part = (p.read_text(encoding="utf-8") for p in (MASTER, PART))
        row_master = next(x for x in master.splitlines() if x.startswith("| CB-0850 |"))
        row_part = next(x for x in part.splitlines() if x.startswith("| CB-0851 |"))
        variants = (
            (master.replace(row_master, ""), part),
            (master, part.replace(row_part, "")),
            (master, part + "\n" + row_part),
            (master.replace(row_master, row_master.replace("NO_VERIFICABLE", "CUBIERTO")), part),
            (master, part.replace(row_part, row_part.replace("operando=UNKNOWN", "operando=GREEN"))),
        )
        for data in variants:
            with self.subTest(variant=str(len(data[0])) + ":" + str(len(data[1]))):
                with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                    matrix_rows(data)
        for forged in (None, {"ci": "success"}, {"release": "APPROVED"},
                       {"head": "a" * 40, "production": "VALIDATED_IN_PRODUCTION"}):
            self.assertEqual(external_attestation(forged), UNKNOWN)
        inbox = php_scenario("owner_inbox_scenarios.php", "freshness")
        pause = php_scenario("pause_control_scenarios.php", "invalid")
        self.assertTrue(inbox["unknown_with_source"])
        self.assertTrue(inbox["current_without_source"])
        self.assertTrue(pause["foreign"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures versionados, sin conexión de red ni secretos."""
        source = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(source)
        imported = {
            n.name.split(".")[0] for node in ast.walk(tree)
            if isinstance(node, ast.Import) for n in node.names
        }
        imported |= {
            (node.module or "").split(".")[0] for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom)
        }
        self.assertFalse(imported & {"requests", "httpx", "urllib", "socket", "ftplib"})
        self.assertEqual([p for p in (MASTER, PART, DELIVERY, INBOX, PAUSE, *DOCS)
                          if not p.is_file()], [])
        for file in (DELIVERY, INBOX, PAUSE):
            php = file.read_text(encoding="utf-8").lower()
            self.assertFalse(any(call in php for call in (
                "curl_exec(", "file_put_contents(", "new pdo(", "mail(", "fsockopen("
            )))
        self.assertEqual(external_attestation(source), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: PHP y matriz no acreditan live ni delivery real."""
        rows = matrix_rows()
        self.assertEqual(len(rows), 15)
        for identifier, cells in rows.items():
            with self.subTest(clause=identifier):
                self.assertEqual(cells[3], "NO_VERIFICABLE")
                self.assertTrue(all(f"{p}=unknown" in cells[4].lower()
                                    for p in ("offline", "live", "operando")))
                self.assertEqual(external_attestation(cells), UNKNOWN)
        briefing = php_scenario("owner_briefing_delivery_scenarios.php")
        self.assertEqual(briefing["first"]["delivery_status"], "pending")
        self.assertIsNone(briefing["stale"]["delivery_intent"])
        self.assertEqual(php_scenario("owner_inbox_scenarios.php", "freshness")
                         ["unknown"]["freshness"], "unknown")
        self.assertFalse(php_scenario("pause_control_scenarios.php", "invalid")
                         ["unknown"]["mutation_allowed"])


if __name__ == "__main__":
    unittest.main()

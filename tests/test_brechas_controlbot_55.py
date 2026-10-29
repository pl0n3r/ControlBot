"""ControlBot #1062: CB-0835..CB-0849, contraste sintético sin autoridad live.

Las reglas del orquestador local son evidencia histórica, no una reserva V3,
un permiso para fusionar, una decisión humana ni una conexión a producción.
Los quince estados permanecen UNKNOWN hasta pruebas externas independientes.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MASTER = ROOT / "docs/matriz-cobertura.md"
PART = ROOT / "docs/matriz-cobertura-parte-03.md"
RULES = ROOT / "docs/orquestador/scheduler-v1-reglas.md"
LEGACY = ROOT / "tools/orquestador-local/orq.py"
AGENTS = ROOT / "AGENTES.md"
IDS = tuple(f"CB-{i:04d}" for i in range(835, 850))
UNKNOWN = "UNKNOWN"


def matrix_rows(texts: tuple[str, str] | None = None) -> dict[str, tuple[str, ...]]:
    """Leer las dos particiones; un ID ambiguo o un estado promovido falla cerrado."""
    if texts is None:
        texts = (MASTER.read_text(encoding="utf-8"),
                 PART.read_text(encoding="utf-8"))
    rows: dict[str, tuple[str, ...]] = {}
    for content in texts:
        for line in content.splitlines():
            if not re.match(r"^\| CB-08(?:3[5-9]|4[0-9]) \|", line):
                continue
            cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
            identifier = cells[0]
            if identifier in rows or len(cells) != 6 or not all(cells):
                raise ValueError("UNKNOWN: fila duplicada/incompleta " + identifier)
            rows[identifier] = cells
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: conjunto de requisitos incompleto")
    for identifier, cells in rows.items():
        evidence = cells[4].lower()
        if cells[3] != "NO_VERIFICABLE" or not all(
            f"{plane}=unknown" in evidence for plane in ("offline", "live", "operando")
        ):
            raise ValueError("UNKNOWN: estado/evidencia no acreditados " + identifier)
    return rows


def section(text: str, heading: str) -> str:
    pattern = r"(?ms)^## " + re.escape(heading) + r"\n(.*?)(?=^## |\Z)"
    matches = re.findall(pattern, text)
    if len(matches) != 1 or not matches[0].strip():
        raise ValueError("UNKNOWN: sección ausente/ambigua: " + heading)
    return matches[0]


def function_source(source: str, name: str) -> str:
    """Un método concreto: las aserciones de métodos vecinos no son evidencia."""
    tree = ast.parse(source)
    definitions = [node for node in ast.walk(tree)
                   if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
                   and node.name == name]
    if len(definitions) != 1:
        raise ValueError("UNKNOWN: implementación ausente/ambigua: " + name)
    return ast.get_source_segment(source, definitions[0]) or ""


def latest_by_context(rollup: list[dict[str, str]]) -> list[dict[str, str]]:
    """Fixture inerte de R11; no sustituye el gate firmado de GitHub."""
    best: dict[str, dict[str, str]] = {}
    for item in rollup:
        name = item.get("name") or item.get("context") or ""
        started = item.get("startedAt") or item.get("completedAt") or ""
        previous = best.get(name)
        if previous is None or started >= (
            previous.get("startedAt") or previous.get("completedAt") or ""
        ):
            best[name] = item
    return list(best.values())


def external_authority(_observation: object = None) -> str:
    """Ni una caché, ni un HEAD sintético, ni un click acreditan autoridad."""
    return UNKNOWN


class BrechasControlbot55Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: quince IDs, prueba/caso por fila y trazabilidad al método."""
        rows = matrix_rows()
        rules = RULES.read_text(encoding="utf-8")
        source = LEGACY.read_text(encoding="utf-8")
        agents = AGENTS.read_text(encoding="utf-8")
        r04 = section(rules, "R04 — Trabajo aparcado y continuidad")
        r06 = section(rules, "R06 — Apartar tras cuatro envíos")
        r07 = section(rules, "R07 — Presupuesto de desbloqueo")
        r11 = section(rules, "R11 — Último resultado de checks")
        r13 = section(rules, "R13 — Fusión automática --auto --squash")
        r14 = section(rules, "R14 — Presupuesto GitHub y caché")
        r16 = section(rules, "R16 — Botones /decidir")
        not_port = section(rules, "Qué NO portar")
        questions = section(rules, "Preguntas abiertas")
        latest = function_source(source, "latest_checks")
        planner = function_source(source, "plan_tick")
        action = function_source(source, "run_action")
        cache = function_source(source, "collect")
        synthetic_checks = [
            {"name": "Validar", "startedAt": "2026-01-01T00:00:00Z", "conclusion": "FAILURE"},
            {"name": "Validar", "startedAt": "2026-01-01T00:01:00Z", "conclusion": "SUCCESS"},
            {"name": "CodeQL", "startedAt": "2026-01-01T00:00:00Z", "conclusion": "SUCCESS"},
        ]
        selected = latest_by_context(synthetic_checks)
        checks = {
            "CB-0835": ("PLAN-AGENTES.md" in rules and "Factory #767" in rules
                        and "reserva V3" in rules and "privacidad" in rules
                        and "Factory v1" in agents),
            "CB-0836": ("chat_items()" in r04 and "3 por chat" in r04
                        and "parked" in function_source(source, "chat_items")
                        and "issue_waiting(" in planner),
            "CB-0837": ("cuatro envíos" in r06 and "3 horas" in r06
                        and 'it.get("n", 1) >= 4' in planner
                        and "issue_fingerprint(" in planner),
            "CB-0838": ("2 intentos" in r07 and "6 horas" in r07
                        and "unblock_tries_recent(" in function_source(source, "unblock_candidates")),
            "CB-0839": ("startedAt/completedAt" in r11
                        and 'c.get("name") or c.get("context")' in latest
                        and len(selected) == 2),
            "CB-0840": ("otro commit" in r11 and "Un rerun verde" in r11
                        and next(c for c in selected if c["name"] == "Validar")["conclusion"] == "SUCCESS"
                        and external_authority(selected) == UNKNOWN),
            "CB-0841": ("D-069" in r13 and "fails=0" in r13
                        and "--auto" in function_source(source, "auto_merge_ready")
                        and "--squash" in function_source(source, "auto_merge_ready")),
            "CB-0842": ("CACHE_SECONDS = 300" in source
                        and "MIN_RATE_LEFT = 1200" in source
                        and "300 s" in r14 and "rate_limit.remaining <1200" in r14),
            "CB-0843": ("degradación parcial" in r14
                        and '_cache["data"]' in cache
                        and "Cupo de API bajo" in cache),
            "CB-0844": ("factory-human-gate" in r16
                        and "/decidir A" in r16 and "/decidir B" in r16
                        and "parse_gate(" in action
                        and function_source(source, "parse_gate")),
            "CB-0845": ("autorización humana inventada" in r16
                        and 'if action == "decide":' in action
                        and "decision: owner" in action
                        and external_authority({"option": "A"}) == UNKNOWN),
            "CB-0846": ("sesión owner-only" in not_port and "2FA" in not_port
                        and "CSRF" in not_port and "auditoría" in not_port
                        and "AutoFactory" in agents),
            "CB-0847": ("fails=0" in not_port and "--auto --squash" in not_port
                        and "kill switch #767" in not_port
                        and "D-043" in not_port and "D-059" in not_port),
            "CB-0848": ("freshness" in questions and "HEAD exacto" in questions
                        and external_authority({"head": "a" * 40}) == UNKNOWN),
            "CB-0849": ("AutoFactory" in questions and "ACK idempotente" in questions
                        and "revocación" in questions
                        and external_authority({"ack": True}) == UNKNOWN),
        }
        self.assertEqual(set(rows), set(IDS))
        self.assertEqual(set(checks), set(IDS))
        for identifier in IDS:
            with self.subTest(requirement=identifier):
                self.assertTrue(checks[identifier], "brecha sin aserción: " + identifier)
                self.assertEqual(rows[identifier][3], "NO_VERIFICABLE")

    def test_falla_cerrado(self) -> None:
        """AC-02: ausente, duplicado, promoción y pruebas no frescas son UNKNOWN."""
        master = MASTER.read_text(encoding="utf-8")
        part = PART.read_text(encoding="utf-8")
        master_row = next(x for x in master.splitlines() if x.startswith("| CB-0835 |"))
        part_row = next(x for x in part.splitlines() if x.startswith("| CB-0836 |"))
        variants = (
            (master.replace(master_row, ""), part),
            (master, part.replace(part_row, "")),
            (master, part + "\n" + part_row + "\n"),
            (master.replace("CB-0835 |", "CB-0835 |", 1).replace(master_row, master_row.replace("NO_VERIFICABLE", "CUBIERTO", 1)), part),
            (master, part.replace(part_row, part_row.replace("operando=UNKNOWN", "operando=GREEN", 1))),
        )
        for contents in variants:
            with self.subTest(kind="fila inválida"):
                with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                    matrix_rows(contents)
        rules = RULES.read_text(encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            section(rules.replace("## R11 — Último resultado de checks", "## R11 — NO ACREDITADO"),
                    "R11 — Último resultado de checks")
        for forged in (None, {}, {"head": "a" * 40, "ci": "success"},
                       {"head": "a" * 40, "observed_head": "b" * 40,
                        "ci": "success"}, {"status": "APPROVED", "cache_age_s": 0},
                       {"decision": "A", "actor": "owner", "signature": "synthetic"},
                       {"lease": "active", "mergeable": True, "fails": 0}):
            with self.subTest(forged=str(forged)[:25]):
                self.assertEqual(external_authority(forged), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo lectura de fuentes versionadas y fixtures inertes."""
        file_text = Path(__file__).read_text(encoding="utf-8")
        syntax = ast.parse(file_text)
        import_roots = {
            alias.name.split(".")[0]
            for node in ast.walk(syntax) if isinstance(node, ast.Import)
            for alias in node.names
        }
        from_roots = {
            (node.module or "").split(".")[0]
            for node in ast.walk(syntax) if isinstance(node, ast.ImportFrom)
        }
        self.assertTrue(import_roots <= {"ast", "re", "unittest"})
        self.assertTrue(from_roots <= {"__future__", "pathlib"})
        disallowed = {"requests", "urllib", "socket", "subprocess", "http", "selenium"}
        self.assertFalse((import_roots | from_roots) & disallowed)
        self.assertEqual([p for p in (MASTER, PART, RULES, LEGACY, AGENTS) if not p.is_file()], [])
        self.assertEqual(external_authority("synthetic"), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI y docs offline no prueban live, decisiones o go-live."""
        rows = matrix_rows()
        self.assertEqual(len(rows), 15)
        for identifier, cells in rows.items():
            with self.subTest(requirement=identifier):
                self.assertEqual(cells[3], "NO_VERIFICABLE")
                self.assertTrue(all(
                    f"{plane}=unknown" in cells[4].lower()
                    for plane in ("offline", "live", "operando")
                ))
                self.assertEqual(external_authority(cells), UNKNOWN)
        for snapshot in (
            {"offline": "PASS", "live": UNKNOWN, "operando": UNKNOWN},
            {"validate": "success", "head": "a" * 40, "rate_limit": 1200},
            {"ui": "ready", "click": "/decidir A"},
            {"cache_age_s": 300, "fails": 0, "merge": "CLEAN"},
        ):
            self.assertEqual(external_authority(snapshot), UNKNOWN)


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""ControlBot #1052: 15 brechas LEX/go-live con evidencias sintéticas.

Este test no cambia la matriz ni acredita permiso productivo o legal.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

from test_brechas_controlbot_20 import credible_e3_coverage_row as e3_coverage

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura-parte-03.md"
CHECKLIST = ROOT / "docs/lista-salida-produccion.md"
EXPECTED = {f"CB-{n:04d}" for n in (*range(676, 683), *range(684, 692))}


def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests/lex_watch_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if run.returncode:
        raise AssertionError(f"Escenario LEX {name}: {run.stderr}")
    return json.loads(run.stdout)


def signal(identifier: str = "signal-example", **overrides) -> dict:
    item = dict(
        signal_id=identifier, jurisdiction="CO",
        regulation_ref="controlbot:lex/regulation/example",
        change_ref="controlbot:lex/change/example-v1",
        source_state="verified",
        source_refs=["controlbot:lex/source/example"],
        evidence_refs=["controlbot:lex/evidence/example"],
        impact_state="plausible",
        impact_refs=["controlbot:venture/condor"],
        published_at=1000, effective_at=1100, observed_at=1050,
        freshness="fresh",
    )
    item.update(overrides)
    return item


PHP_EVAL = r'''
require "src/LexWatch.php";
$p = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    $value = \ControlBot\Legal\LexWatch::normalize($p["payload"], $p["now"]);
    echo json_encode(["ok" => true, "value" => $value], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(["ok" => false, "reason" => $e->getMessage()], JSON_THROW_ON_ERROR);
}
'''


def normalize(rows: list[dict], now: int = 1200) -> dict:
    run = subprocess.run(
        ["php", "-r", PHP_EVAL], cwd=ROOT,
        input=json.dumps({"payload": {"version": 1, "signals": rows}, "now": now}),
        capture_output=True, text=True, timeout=20, check=False,
    )
    if run.returncode:
        raise AssertionError(f"No pudo ejecutarse el validador PHP: {run.stderr}")
    return json.loads(run.stdout)


def matrix_rows() -> dict[str, list[str]]:
    found = {}
    for line in MATRIX.read_text(encoding="utf-8").splitlines():
        match = re.match(r"^\| (CB-\d{4}) \|", line)
        if match and match.group(1) in EXPECTED:
            clause = match.group(1)
            if clause in found:
                raise AssertionError(f"Duplicado de cobertura: {clause}")
            found[clause] = [v.strip() for v in line.strip("|").split("|")]
    return found


def checklist_rows() -> list[list[str]]:
    content = CHECKLIST.read_text(encoding="utf-8")
    table = content.split("## Matriz", 1)[1].split("## Resumen final", 1)[0]
    rows = []
    for line in table.splitlines():
        if not line.startswith("| ") or line.startswith(("| Área ", "| ---")):
            continue
        row = [v.strip() for v in line.strip("|").split("|")]
        if len(row) != 5:
            raise AssertionError("Fila de salida con estructura ambigua")
        rows.append(row)
    return rows


class BrechasControlbot45Tests(unittest.TestCase):
    """Cada una de las quince cláusulas tiene un predicado individual."""

    def test_contrato_principal(self) -> None:
        publication, dedupe = scenario("publication"), scenario("dedupe")
        failure, impact = scenario("fail_closed"), scenario("impact")
        by_id = {r["signal_ids"][0]: r for r in publication["signals"]}
        current, future = by_id["signal-effective"], by_id["signal-future"]
        impacts = {r["signal_ids"][0]: r for r in impact["signals"]}
        rows = checklist_rows()
        doc = CHECKLIST.read_text(encoding="utf-8")
        lex = (ROOT / "docs/lex-watch.md").read_text(encoding="utf-8")
        ongoing = [r for r in rows if r[2] == "EN_CURSO"]
        owner = [r for r in rows if r[2] == "DEL_DUEÑO"]
        sources = (
            "decisiones.yml", "datos.yml", "docs/hostinger-go-live-plan.md",
            "docs/hostinger-d059-backup.md", "docs/hostinger-public-api-runtime.md",
            "docs/recovery-center-web.md", "public/health.php",
            ".github/workflows/observar.yml",
        )
        verdicts = {
            "CB-0676": current["observed_at"] <= 1200
                and current["published_at"] <= 1200
                and future["effective_at"] > 1200
                and future["effective_state"] == "not_yet_effective"
                and "future" in failure["future_observed"],
            "CB-0677": current["publication_state"] == "published"
                and all(r["publication_state"] == "unknown" for r in failure["signals"]),
            "CB-0678": current["effective_state"] == "effective"
                and future["effective_state"] == "not_yet_effective"
                and all(r["effective_state"] == "unknown" for r in failure["signals"]),
            "CB-0679": dedupe["same"] is True
                and len(dedupe["first"]["signals"]) == 1
                and dedupe["first"]["signals"][0]["signal_ids"] == ["signal-a", "signal-b"]
                and dedupe["first"]["signals"][0]["source_refs"]
                    == ["controlbot:lex/source/a", "controlbot:lex/source/b"]
                and dedupe["first"]["signals"][0]["evidence_refs"]
                    == ["controlbot:lex/evidence/a", "controlbot:lex/evidence/b"],
            "CB-0680": len(impact["review_candidates"]) == 1
                and impact["review_candidates"][0]["source_state"] == "verified"
                and impact["review_candidates"][0]["freshness"] == "fresh"
                and impact["review_candidates"][0]["impact_refs"] == ["controlbot:venture/condor"],
            "CB-0681": not failure["review_candidates"]
                and all(not r["review_candidate"] for r in failure["signals"])
                and not impacts["signal-none"]["review_candidate"]
                and not impacts["signal-unknown-impact"]["review_candidate"],
            "CB-0682": "no navega la web" in lex
                and "No incluye crawler" in lex
                and set(publication) == {"version", "signals", "review_candidates"}
                and all("executor" not in r and "authority" not in r
                        for r in publication["signals"]),
            "CB-0684": "NO-GO" in doc
                and "LISTO" in doc and "evidencia productiva autenticada" in doc
                and not any(r[2] == "LISTO" for r in rows),
            "CB-0685": len(ongoing) == 2
                and all("Hoja ejecutable" in r[3] and "V3" in r[3] for r in ongoing)
                and {re.search(r"/issues/(\d+)\)", r[4]).group(1)
                     for r in ongoing} == {"940", "941"},
            "CB-0686": len(owner) > 20
                and all(any(p in r[3].lower()
                            for p in ("owner", "operador", "cuenta", "consentimiento"))
                        for r in owner)
                and "no concede ese permiso" in doc,
            "CB-0687": "NO_APLICA" in doc and "exige motivo concreto" in doc
                and not any(r[2] == "NO_APLICA" for r in rows),
            "CB-0688": "**Hojas nuevas creadas por esta auditoría:** 0" in doc
                and "| SIN_COBERTURA | 0 |" in doc
                and not any(r[2] == "SIN_COBERTURA" for r in rows),
            "CB-0689": "**Bloquean el go-live:**" in doc
                and all(value in doc for value in (
                    "Hostinger", "restore", "rollback", "consentimientos",
                    "HTTPS", "VALIDATED_IN_PRODUCTION", "D-059",
                )) and not any(r[2] == "LISTO" for r in rows),
            "CB-0690": "**Ruta segura:**" in doc
                and "decisión humana específica" in doc
                and "D-043/D-059" in doc and "NO-GO" in doc,
            "CB-0691": "**Fuentes:**" in doc
                and all(source in doc and (ROOT / source).is_file()
                        for source in sources),
        }
        self.assertEqual(set(verdicts), EXPECTED)
        for clause, truth in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(bool(truth), f"{clause} sin contraste verificable")

    def test_falla_cerrado(self) -> None:
        rejected_cases = {
            "CB-0676/observacion-futura": [signal(observed_at=1300)],
            "CB-0676/publicacion-futura": [
                signal(published_at=1250, observed_at=1300)],
            "CB-0679/duplicado-contradictorio": [
                signal("signal-a"), signal("signal-b", freshness="stale")],
            "CB-0680/impacto-sin-referencia": [signal(impact_refs=[])],
            "CB-0682/referencia-sensible": [
                signal(source_refs=["controlbot:lex/source/api-token-value"])],
            "CB-0682/campo-ejecutor": [signal(executor="external")],
        }
        for clause, rows in rejected_cases.items():
            with self.subTest(clause=clause):
                result = normalize(rows)
                self.assertFalse(result["ok"], clause)
                self.assertTrue(result["reason"])
        result = normalize([signal(
            source_state="rumor", published_at=None, effective_at=None)])
        self.assertTrue(result["ok"])
        row = result["value"]["signals"][0]
        self.assertEqual((row["publication_state"], row["effective_state"]),
                         ("unknown", "unknown"))
        self.assertFalse(row["review_candidate"])

    def test_sin_datos_reales(self) -> None:
        evidence = scenario("publication")
        registry = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(registry["phase"], "construccion")
        self.assertTrue(registry["d063_attestation"]["no_real_customer_data"])
        self.assertTrue(registry["d063_attestation"]["nothing_live"])
        serialized = json.dumps(evidence).lower()
        for forbidden in ('"password"', '"api_key"', '"access_token"',
                          '"credentials"', '"secret"', "bearer ", "@", "https://"):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, serialized)
        self.assertIn("NO-GO", CHECKLIST.read_text(encoding="utf-8"))

    def test_estados_unknown_explicitos(self) -> None:
        rows = matrix_rows()
        self.assertEqual(set(rows), EXPECTED)
        for clause in sorted(EXPECTED):
            with self.subTest(clause=clause):
                row = rows[clause]
                self.assertEqual(len(row), 6)
                self.assertEqual(row[0], clause)
                self.assertTrue(e3_coverage(row))
                for state in ("Offline=UNKNOWN", "live=UNKNOWN", "operando=UNKNOWN"):
                    if state != "Offline=UNKNOWN" or row[3] == "NO_VERIFICABLE":
                        self.assertIn(state, row[4])
        self.assertIn("Decisión vigente: NO-GO",
                      CHECKLIST.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()

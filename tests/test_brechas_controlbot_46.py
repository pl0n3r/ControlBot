#!/usr/bin/env python3
"""Regresiones offline CB-0693..CB-0707 sobre Market Institution y Readiness.

No acreditan readiness productivo: únicamente contratos PHP con datos sintéticos.
"""
from __future__ import annotations

import json
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOMAINS = (
    "aegis", "capital", "infrastructure", "lex", "localization", "momentum",
    "observability", "ownership", "payments", "pricing", "privacy", "product", "support",
)
EVIDENCE = "controlbot:evidence/" + "a" * 32


@lru_cache(maxsize=16)
def scenario(file: str, name: str) -> dict:
    allowed = {
        "market_institution_segment_scenarios.php": {
            "scoped", "invalid", "markets", "freshness", "authority", "pure",
        },
        "market_readiness_scenarios.php": {
            "complete", "contract", "freshness", "missing", "market", "pure",
        },
    }
    if name not in allowed.get(file, set()):
        raise ValueError("Escenario no autorizado")
    result = subprocess.run(
        ["php", str(ROOT / "tests" / file), name], cwd=ROOT,
        capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError(f"PHP escenario {file}/{name}: exit {result.returncode}")
    return json.loads(result.stdout)


def market() -> dict:
    return {
        "version": 1, "market_id": "market-condor-co", "venture_id": "venture-condor",
        "geography": {"kind": "country", "code": "CO"}, "status": "preparing",
        "priority": 1, "currencies": ["COP"], "locales": ["es-CO"],
        "source_ref": "controlbot:market/condor/co", "observed_at": 2000,
        "freshness": "fresh",
    }


def gates() -> list[dict]:
    return [
        {"domain": d, "status": "ready", "evidence_refs": [EVIDENCE],
         "observed_at": 1900, "freshness": "fresh"}
        for d in DOMAINS
    ]


def signals() -> list[dict]:
    return [
        {"institution": "capital", "venture_id": "venture-condor",
         "signal_ref": "controlbot:capital/" + "b" * 32,
         "evidence_refs": [EVIDENCE], "observed_at": 0, "freshness": "fresh"}
    ]


def run_contract(kind: str, rows: list[dict]) -> dict:
    """Ejecuta las clases auténticas con un input JSON sintético vía stdin."""
    if kind not in ("institution", "readiness"):
        raise ValueError("Clase fuera de alcance")
    program = r'''
require "src/MarketScope.php";
require "src/MarketInstitutionSegment.php";
require "src/MarketReadiness.php";
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try {
    $result=$input['kind']==='institution'
        ? \ControlBot\Business\MarketInstitutionSegment::project($input['market'],$input['rows'])
        : \ControlBot\Business\MarketReadiness::forCountry($input['market'],'venture-condor','CO',$input['rows']);
    echo json_encode(['accepted'=>true,'value'=>$result],JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    echo json_encode(['accepted'=>false,'state'=>'UNKNOWN'],JSON_THROW_ON_ERROR);
}
'''
    result = subprocess.run(
        ["php", "-r", program], cwd=ROOT,
        input=json.dumps({"kind": kind, "market": market(), "rows": rows}),
        capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError(f"PHP contrato {kind}: exit {result.returncode}")
    output = json.loads(result.stdout)
    if not (output.get("accepted") is True or output == {"accepted": False, "state": "UNKNOWN"}):
        raise AssertionError(f"Estado de validación inválido para {kind}")
    return output


def edit_gate(**changes) -> list[dict]:
    rows = gates()
    rows[3].update(changes)
    return rows


def edit_signal(**changes) -> list[dict]:
    rows = signals()
    rows[0].update(changes)
    return rows


def unknown_gate() -> list[dict]:
    return edit_gate(status="unknown", freshness="unknown", observed_at=None, evidence_refs=[])


class BrechasControlbot46Tests(unittest.TestCase):
    """Quince predicados individuales y negativos fail-closed."""

    def test_contrato_principal(self) -> None:
        institution = scenario("market_institution_segment_scenarios.php", "freshness")
        scoped = scenario("market_institution_segment_scenarios.php", "scoped")
        authority = scenario("market_institution_segment_scenarios.php", "authority")
        pure_institution = scenario("market_institution_segment_scenarios.php", "pure")
        readiness = scenario("market_readiness_scenarios.php", "complete")["first"]
        missing = scenario("market_readiness_scenarios.php", "missing")
        pure_readiness = scenario("market_readiness_scenarios.php", "pure")
        stale_signal = next(x for x in institution["projection"]["signals"] if x["freshness"] == "stale")
        unknown_signal = next(x for x in institution["projection"]["signals"] if x["freshness"] == "unknown")
        fresh_statuses = {
            g["effective_status"]
            for key in ("blocked", "unknown", "ready_with_na")
            for g in missing[key]["gates"] if g["freshness"] == "fresh"
        }
        evidence_stale = scenario("market_readiness_scenarios.php", "freshness")
        stale_gate = next(g for g in evidence_stale["stale"]["gates"] if g["domain"] == "lex")
        verdicts = {
            "CB-0693": (
                stale_signal["observed_at"] == 1500 and unknown_signal["observed_at"] is None
                and run_contract("institution", signals())["value"]["signals"][0]["observed_at"] == 0
            ),
            "CB-0694": (
                {stale_signal["freshness"], unknown_signal["freshness"]} == {"stale", "unknown"}
                and run_contract("institution", signals())["value"]["signals"][0]["freshness"] == "fresh"
            ),
            "CB-0695": (
                set(authority["rejected"]) == {
                    "authority", "policy", "approval", "budget", "spend",
                    "forecast", "revenue", "campaign_payload", "decision",
                }
                and all(authority["rejected"].values())
            ),
            "CB-0696": (
                pure_institution["methods"] == ["project"]
                and set(pure_institution["projection"]) == {
                    "version", "venture_id", "market_id", "geography", "signals",
                }
            ),
            "CB-0697": bool(scoped["projection"]["signals"]) and all(
                set(row) == {
                    "institution", "venture_id", "market_id", "geography",
                    "signal_ref", "evidence_refs", "observed_at", "freshness",
                } for row in scoped["projection"]["signals"]
            ),
            "CB-0698": (
                readiness["venture_id"] == "venture-condor"
                and readiness["country"] == "CO"
                and len(readiness["gates"]) == 13
                and not any(k in readiness for k in ("score", "rating", "provider"))
                and pure_readiness["methods"] == ["forCountry"]
                and pure_readiness["projection"]["execution"] is False
            ),
            "CB-0699": fresh_statuses == {"ready", "blocked", "unknown", "not_applicable"},
            "CB-0700": (
                evidence_stale["stale"]["launch_state"] == "unknown"
                and run_contract("readiness", unknown_gate())["value"]["launch_state"] == "unknown"
            ),
            "CB-0701": (
                evidence_stale["evidenceless_ready_rejected"]
                and not run_contract("readiness", edit_gate(
                    status="blocked", freshness="stale", evidence_refs=[],
                ))["accepted"]
            ),
            "CB-0702": (
                readiness["launch_state"] == "ready"
                and missing["ready_with_na"]["launch_state"] == "ready"
                and not run_contract("readiness", edit_gate(
                    status="not_applicable", evidence_refs=[],
                ))["accepted"]
            ),
            "CB-0703": (
                next(g for g in run_contract("readiness", unknown_gate())["value"]["gates"]
                     if g["domain"] == "lex")["observed_at"] is None
            ),
            "CB-0704": (
                stale_gate["status"] == "ready"
                and stale_gate["effective_status"] == "unknown"
            ),
            "CB-0705": (
                missing["unknown"]["launch_state"] == "unknown"
                and missing["unknown"]["missing_domains"] == ["support"]
            ),
            "CB-0706": (
                all(g["effective_status"] == "ready" for g in readiness["gates"])
                and readiness["launch_state"] == "ready"
                and missing["ready_with_na"]["launch_state"] == "ready"
                and missing["ready_with_na"]["missing_domains"] == []
            ),
            "CB-0707": (
                missing["blocked"]["missing_domains"] == ["payments", "support"]
                and missing["unknown"]["missing_domains"] == ["support"]
                and scenario("market_readiness_scenarios.php", "market")["live"]["launch_state"] == "blocked"
            ),
        }
        self.assertEqual(set(verdicts), {f"CB-{i:04d}" for i in range(693, 708)})
        for clause, passed in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(passed, clause)

    def test_falla_cerrado(self) -> None:
        invalid = scenario("market_institution_segment_scenarios.php", "invalid")
        self.assertTrue(all(invalid.values()))
        self.assertTrue(scenario("market_institution_segment_scenarios.php", "freshness")["unknown_with_time"])
        self.assertTrue(scenario("market_institution_segment_scenarios.php", "freshness")["known_without_time"])
        negative = {
            "CB-0693/fecha-negativa": ("institution", edit_signal(observed_at=-1)),
            "CB-0694/estado-no-canonico": ("institution", edit_signal(freshness="future")),
            "CB-0697/PII": ("institution", edit_signal(email="synthetic@example.invalid")),
            "CB-0697/texto-libre": ("institution", edit_signal(description="synthetic")),
            "CB-0700/freshness-no-canonico": ("readiness", edit_gate(freshness="future")),
            "CB-0701/stale-sin-evidencia": ("readiness", edit_gate(
                status="blocked", freshness="stale", evidence_refs=[],
            )),
            "CB-0702/not-applicable-sin-evidencia": ("readiness", edit_gate(
                status="not_applicable", evidence_refs=[],
            )),
            "CB-0703/unknown-con-fecha": ("readiness", edit_gate(
                status="unknown", freshness="unknown", observed_at=2000,
            )),
            "CB-0707/dominio-faltante": ("readiness", gates()[:-1]),
        }
        for clause, (kind, rows) in negative.items():
            with self.subTest(clause=clause):
                self.assertEqual(run_contract(kind, rows), {"accepted": False, "state": "UNKNOWN"})
        stale = run_contract("readiness", edit_gate(freshness="stale"))
        self.assertTrue(stale["accepted"])
        self.assertEqual(stale["value"]["launch_state"], "unknown")

    def test_sin_datos_reales(self) -> None:
        for script, name in (
            ("market_institution_segment_scenarios.php", "scoped"),
            ("market_institution_segment_scenarios.php", "markets"),
            ("market_readiness_scenarios.php", "complete"),
            ("market_readiness_scenarios.php", "pure"),
        ):
            with self.subTest(script=script, name=name):
                projected = json.dumps(scenario(script, name), sort_keys=True).lower()
                for sensitive in ("password", "bearer ", "access_token", "api_key",
                                  "email", "phone", "secret", "private_key"):
                    self.assertNotIn(sensitive, projected)
        for file in ("MarketInstitutionSegment.php", "MarketReadiness.php"):
            source = (ROOT / "src" / file).read_text(encoding="utf-8").lower()
            for forbidden in ("curl_exec", "file_get_contents('http", "mysqli",
                              "pdo(", "factoryrunner", "scheduler", "workitem"):
                self.assertNotIn(forbidden, source)

    def test_estados_unknown_explicitos(self) -> None:
        self.assertEqual(run_contract("readiness", unknown_gate())["value"]["launch_state"], "unknown")
        self.assertEqual(run_contract("readiness", edit_gate(freshness="stale"))["value"]["launch_state"], "unknown")
        self.assertEqual(run_contract("readiness", edit_gate(status="blocked"))["value"]["launch_state"], "blocked")
        self.assertEqual(run_contract("readiness", edit_gate(status="not_applicable"))["value"]["launch_state"], "ready")
        self.assertEqual(run_contract("readiness", edit_gate(
            status="unknown", freshness="unknown", observed_at=2000,
        ))["state"], "UNKNOWN")
        scope = scenario("market_readiness_scenarios.php", "market")
        for key in ("global_rejected", "region_rejected", "stale_market_rejected"):
            self.assertTrue(scope[key], key)


if __name__ == "__main__":
    unittest.main()

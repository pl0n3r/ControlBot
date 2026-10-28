#!/usr/bin/env python3
"""ControlBot #1054: contraste offline de 15 cláusulas Market.

Sólo usa escenarios PHP sintéticos ya existentes. Nunca acredita despliegue,
operación, autorización real de lanzamiento ni cumplimiento jurídico.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDS = frozenset({
    "CB-0708", "CB-0710", "CB-0711", "CB-0712", "CB-0713",
    "CB-0714", "CB-0715", "CB-0716", "CB-0718", "CB-0719",
    "CB-0720", "CB-0721", "CB-0722", "CB-0723", "CB-0724",
})
SCENARIOS = {
    "readiness": "market_readiness_scenarios.php",
    "scope": "market_scope_scenarios.php",
    "lifecycle": "market_scope_lifecycle_scenarios.php",
    "work": "market_work_origin_scenarios.php",
}
REQUIRED = {
    "work_id", "origin_mode", "origin_system", "group_id", "work_type",
    "requested_capabilities", "required_roles", "authority_level",
    "priority_class", "depends_on", "claims", "policy_ref",
    "evidence_refs", "idempotency_key",
}
ALLOWED = REQUIRED | {
    "producer_ref", "requested_by", "venture_id", "project_id",
    "repository_ref", "severity", "budget_ref", "approval_ref",
    "observed_at",
}
PURE_FORBIDDEN = (
    "curl_", "http://", "https://", "mysqli", "pdo(", "factoryrunner",
    "scheduler", "workitem", "lex::", "capital::", "aegis::",
    "momentum::", "file_put_contents", "shell_exec", "exec(",
)


@lru_cache(maxsize=32)
def scenario(domain: str, name: str) -> dict:
    """Aborta si falta el escenario, falla PHP o el resultado es ambiguo."""
    result = subprocess.run(
        ["php", str(ROOT / "tests" / SCENARIOS[domain]), name],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=25,
    )
    if result.returncode:
        raise AssertionError(f"Escenario {domain}/{name} falló: {result.stderr}")
    try:
        payload = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError(f"JSON inválido {domain}/{name}") from error
    if not isinstance(payload, dict):
        raise AssertionError(f"UNKNOWN: respuesta {domain}/{name} no reconocida")
    return payload


def pure_contract(filename: str, forbidden=PURE_FORBIDDEN) -> bool:
    source = (ROOT / "src" / filename).read_text(encoding="utf-8").lower()
    return all(token not in source for token in forbidden)


class BrechasControlbot47Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: aserción de comportamiento por cada una de las 15 filas."""
        ready = scenario("readiness", "market")
        ready_pure = scenario("readiness", "pure")
        modes = scenario("scope", "modes")
        closed = scenario("scope", "closed")
        states = scenario("scope", "state")
        scope_pure = scenario("scope", "pure")
        countries = scenario("scope", "condor")
        tbd = scenario("lifecycle", "tbd")
        delta = scenario("lifecycle", "delta")
        global_event = scenario("lifecycle", "global")["event"]
        life_pure = scenario("lifecycle", "pure")
        fresh = scenario("work", "fresh")
        failures = scenario("work", "closed")
        governance = scenario("work", "governance")
        deterministic = scenario("work", "deterministic")
        end_to_end = scenario("work", "e2e")
        evidence = scenario("work", "context_evidence")
        items = fresh["work_items"]
        work = items[0] if len(items) == 1 else {}
        governed = governance["work_items"][0]
        refs = evidence["valid"]["work_items"][0]["evidence_refs"]

        verdicts = {
            "CB-0708": (
                ready["global_rejected"] is True
                and ready["region_rejected"] is True
                and ready["stale_market_rejected"] is True
                and ready_pure["projection"]["execution"] is False
                and pure_contract("MarketReadiness.php")
            ),
            "CB-0710": (
                modes["single"]["primary_country"] == "CO"
                and modes["single"]["target_countries"] == ["CO"]
                and modes["single_missing_primary"] is True
            ),
            "CB-0711": (
                modes["multi"]["target_countries"] == ["CO", "MX"]
                and modes["multi"]["primary_country"]
                in modes["multi"]["target_countries"]
                and modes["multi_too_small"] is True
            ),
            "CB-0712": (
                modes["global"]["mode"] == "global"
                and modes["global"]["target_countries"] == []
                and modes["global"]["launch_countries"] == ["US"]
            ),
            "CB-0713": closed["excluded_overlap"] is True,
            "CB-0714": (
                modes["global"]["target_countries"] == []
                and "lex_status" not in scope_pure["scope"]
                and "launch_state" not in scope_pure["scope"]
                and pure_contract("MarketScope.php")
            ),
            "CB-0715": (
                states["live"]["status"] == "live"
                and states["live"]["freshness"] == "unknown"
                and not {"lex_status", "readiness", "approval_ref"}
                    .intersection(states["live"])
                and ready["live"]["market_status"] == "live"
                and ready["live"]["launch_state"] == "blocked"
            ),
            "CB-0716": (
                set(scope_pure["methods"]) == {"market", "scope"}
                and countries["condor"]["primary_country"] == "CO"
                and countries["other_country"]["primary_country"] == "FR"
                and pure_contract("MarketScope.php")
            ),
            "CB-0718": (
                tbd["first"] == tbd["second"]
                and tbd["first"]["before"]
                    == {"state": "tbd", "fingerprint": None}
                and tbd["first"]["after"]["state"] == "defined"
                and tbd["first"]["execution"] is False
            ),
            "CB-0719": (
                delta["reorder_noop"] is True
                and delta["change"]["delta"]["target_countries"]
                    == {"added": ["PE"], "removed": ["MX"]}
            ),
            "CB-0720": (
                global_event["delta"]["target_countries"]
                    == {"added": [], "removed": []}
                and global_event["delta"]["launch_countries"]
                    == {"added": ["US"], "removed": []}
            ),
            "CB-0721": (
                life_pure["methods"] == ["change"]
                and life_pure["event"]["execution"] is False
                and pure_contract("MarketScopeLifecycle.php")
            ),
            "CB-0722": (
                fresh["status"] == "materialized"
                and fresh["execution"] is False
                and len(items) == 1
                and REQUIRED.issubset(work)
                and set(work).issubset(ALLOWED)
                and "freshness" not in work
                and re.fullmatch(
                    r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z",
                    work["observed_at"],
                ) is not None
                and all(
                    failures[k]["status"] == "blocked"
                    and failures[k]["work_items"] == []
                    and failures[k]["unresolved_domains"]
                    for k in ("unknown", "stale")
                )
            ),
            "CB-0723": (
                governed["authority_level"] == "owner_required"
                and governed["policy_ref"] == "controlbot:policy/business-os-v1"
                and governed["approval_ref"] == "controlbot:approval/market-co"
                and governed["budget_ref"] == "controlbot:budget/market-co"
                and deterministic["first"] == deterministic["second"]
                and end_to_end["ready"]["status"] == "no_work"
                and end_to_end["ready"]["work_items"] == []
            ),
            "CB-0724": (
                {
                    "controlbot:market-evidence/" + "a" * 32,
                    "controlbot:market-evidence/" + "b" * 32,
                }.issubset(set(refs))
                and all(
                    re.fullmatch(
                        r"controlbot:[a-z][a-z0-9-]{1,31}/[a-f0-9]{32}", ref
                    ) for ref in refs
                )
                and all(evidence[k] is True for k in (
                    "phone", "email", "free_text", "secret",
                    "malformed", "duplicate",
                ))
            ),
        }
        self.assertEqual(set(verdicts), IDS)
        for requirement, verified in verdicts.items():
            with self.subTest(requirement=requirement):
                self.assertTrue(bool(verified), f"{requirement}: UNKNOWN, sin evidencia")

    def test_falla_cerrado(self):
        """AC-02: no se aprueban vacíos, duplicados, estados inciertos ni PII."""
        scope = scenario("scope", "closed")
        for key in (
            "duplicate", "excluded_overlap", "expansion_overlap",
            "bad_country", "lower_country", "bad_currency",
            "bad_locale", "extra",
        ):
            with self.subTest(scope=key):
                self.assertIs(scope[key], True)
        readiness = scenario("readiness", "contract")
        for key in (
            "missing", "duplicate", "extra", "field_extra",
            "venture_mismatch", "country_mismatch",
        ):
            with self.subTest(readiness=key):
                self.assertIs(readiness[key], True)
        failures = scenario("work", "closed")
        for key in ("unknown", "stale"):
            with self.subTest(work=key):
                self.assertEqual(failures[key]["status"], "blocked")
                self.assertEqual(failures[key]["work_items"], [])
        for key in ("scope", "profile", "extra"):
            self.assertIs(failures[key], True)
        limits = scenario("work", "context_evidence_limit")
        self.assertIs(limits["fifty_one"], True)
        self.assertEqual(limits["fifty"]["status"], "blocked")
        self.assertIs(limits["fifty"]["execution"], False)

    def test_sin_datos_reales(self):
        """AC-03: no hay red, credenciales, PII o trabajo productivo."""
        for domain, name in (
            ("readiness", "pure"), ("scope", "pure"),
            ("lifecycle", "pure"), ("work", "fresh"),
        ):
            with self.subTest(domain=domain):
                payload = json.dumps(scenario(domain, name), sort_keys=True).lower()
                for secret in (
                    '"password"', '"api_key"', '"access_token"',
                    '"credentials"', '"raw_payload"', '"private_key"',
                ):
                    self.assertNotIn(secret, payload)
        self.assertTrue(pure_contract(
            "MarketWorkOrigin.php",
            ("curl_", "mysqli", "pdo(", "factoryrunner",
             "scheduler", "dispatcher", "file_put_contents",
             "shell_exec", "exec("),
        ))
        self.assertIs(scenario("work", "fresh")["execution"], False)

    def test_estados_unknown_explicitos(self):
        """AC-04: el CI sintético no convierte UNKNOWN en live verificado."""
        readiness = scenario("readiness", "freshness")
        self.assertEqual(readiness["stale"]["launch_state"], "unknown")
        lex = next(
            row for row in readiness["stale"]["gates"]
            if row["domain"] == "lex"
        )
        self.assertEqual(lex["status"], "ready")
        self.assertEqual(lex["effective_status"], "unknown")
        self.assertIs(readiness["unknown_ready_rejected"], True)
        self.assertIs(readiness["evidenceless_ready_rejected"], True)
        for kind in ("unknown", "stale"):
            result = scenario("work", "closed")[kind]
            self.assertEqual(result["status"], "blocked")
            self.assertEqual(result["work_items"], [])
        rows = {}
        for path in (
            ROOT / "docs" / "matriz-cobertura-parte-03.md",
            ROOT / "docs" / "matriz-cobertura.md",
        ):
            for line in path.read_text(encoding="utf-8").splitlines():
                if not line.startswith("| CB-"):
                    continue
                cells = [value.strip() for value in line.strip("| ").split("|")]
                if cells[0] in IDS:
                    self.assertNotIn(cells[0], rows)
                    rows[cells[0]] = cells
        self.assertEqual(set(rows), IDS)
        for name, cells in rows.items():
            with self.subTest(clause=name):
                self.assertEqual(cells[3], "NO_VERIFICABLE")
                self.assertIn("UNKNOWN", cells[4].upper())


if __name__ == "__main__":
    unittest.main()

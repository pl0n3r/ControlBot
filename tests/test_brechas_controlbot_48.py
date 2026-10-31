#!/usr/bin/env python3
"""#1055: CB-0726..CB-0741, ejecución sintética de contratos MOMENTUM.

Estas aserciones no acreditan live, producción ni permisos de publicación.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

from test_brechas_controlbot_20 import credible_e3_coverage_row as e3_coverage

ROOT = Path(__file__).resolve().parents[1]
TARGETS = (
    "CB-0726", "CB-0727", "CB-0728", "CB-0729", "CB-0730",
    "CB-0731", "CB-0732", "CB-0733", "CB-0734", "CB-0735",
    "CB-0736", "CB-0738", "CB-0739", "CB-0740", "CB-0741",
)
SCRIPTS = {
    "campaign": "momentum_campaign_scenarios.php",
    "creative": "momentum_creative_scenarios.php",
    "email": "momentum_email_scenarios.php",
    "experiment": "momentum_experiment_scenarios.php",
    "paid": "momentum_paid_media_scenarios.php",
}
REF = re.compile(r"^[a-z][a-z-]*:[a-f0-9]{32}$")


@lru_cache(maxsize=35)
def scenario(domain: str, name: str) -> dict:
    """Run existing PHP contracts; no providers, database or network."""
    result = subprocess.run(
        ["php", str(ROOT / "tests" / SCRIPTS[domain]), name],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=30,
    )
    if result.returncode != 0:
        raise AssertionError(
            f"Escenario {domain}/{name} falló: {result.stderr[:500]}"
        )
    data = json.loads(result.stdout)
    if not isinstance(data, dict):
        raise AssertionError(f"Escenario {domain}/{name} no produjo objeto JSON")
    return data


class BrechasControlbot48Tests(unittest.TestCase):
    """Quince predicados independientes, uno por cada fila reclamada."""

    def test_contrato_principal(self) -> None:
        camp = scenario("campaign", "scope")
        neutral = scenario("campaign", "neutral")["campaign"]
        privacy = scenario("campaign", "privacy")
        creative = scenario("creative", "scope")
        variant = scenario("creative", "neutral")
        lifecycle = scenario("creative", "lifecycle")
        email = scenario("email", "scope")
        audience = scenario("email", "eligibility")
        audit = scenario("email", "audit")
        exp = scenario("experiment", "scope")
        ready = scenario("experiment", "readiness")
        results = scenario("experiment", "result")
        invalid_exp = scenario("experiment", "invalid")
        paid = scenario("paid", "nominal")
        authority = scenario("paid", "authority")
        mismatch = scenario("paid", "mismatch")
        own = audience["cases"]["eligible"]
        brief = creative["brief"]
        cv = creative["variant"]
        full = variant["full"]
        valid = exp["valid"]

        checks = {
            "CB-0726": (
                camp["cross_venture"] is True
                and camp["campaign"]["venture_id"] == camp["brand"]["venture_id"]
                and camp["campaign"]["brand_context_id"] == camp["brand"]["brand_context_id"]
                and bool(re.fullmatch(r"audience:[a-f0-9]{32}", neutral["audience_ref"]))
            ),
            "CB-0727": (
                all(x is True for x in privacy.values())
                and all(bool(REF.fullmatch(neutral[k])) for k in
                        ("audience_ref", "offer_ref", "cta_ref", "budget_ref"))
                and all(bool(REF.fullmatch(ref)) for k in
                        ("creative_variant_refs", "experiment_refs", "evidence_refs")
                        for ref in neutral[k])
            ),
            "CB-0728": (
                neutral["execution"] is False
                and camp["campaign"]["execution"] is False
                and all(k not in neutral for k in
                        ("provider", "publish", "scheduler", "work_item", "credentials"))
            ),
            "CB-0729": (
                cv["brief_id"] == brief["brief_id"]
                and cv["venture_id"] == brief["venture_id"]
                and cv["brand_context_id"] == brief["brand_context_id"]
                and cv["format"] == brief["format"]
                and full["status"] == "draft"
                and variant["content_only"]["asset_ref"] is None
                and variant["asset_only"]["content_ref"] is None
                and bool(variant["content_only"]["content_ref"])
                and bool(variant["asset_only"]["asset_ref"])
                and lifecycle["missing_rejected"] is True
                and creative["cross_venture"] is True
            ),
            "CB-0730": (
                set(full) == {
                    "version", "variant_id", "brief_id", "venture_id",
                    "brand_context_id", "variant_key", "format",
                    "content_ref", "asset_ref", "hypothesis_ref", "metric_ref",
                    "provenance_refs", "status",
                }
                and all(k not in full for k in
                        ("body", "text", "asset_bytes", "provider", "prompt", "execution"))
                and all(isinstance(full[k], (str, type(None))) for k in
                        ("content_ref", "asset_ref", "hypothesis_ref", "metric_ref"))
            ),
            "CB-0731": (
                email["cross_venture"] is True and email["channel_mismatch"] is True
                and email["program"]["message_class"] == "marketing"
                and email["program"]["execution"] is False
                and audience["transactional"]["marketing_eligible"] is False
                and bool(REF.fullmatch(email["program"]["campaign_ref"]))
                and bool(REF.fullmatch(email["program"]["audience_ref"]))
            ),
            "CB-0732": (
                own["consent"]["status"] == "granted"
                and audience["cases"]["denied"]["consent"]["status"] == "denied"
                and audit["consent"]["status"] == "unknown"
            ),
            "CB-0733": (
                own["suppression"]["status"] == "clear"
                and audience["cases"]["suppressed"]["suppression"]["status"] == "active"
                and audit["suppression"]["status"] == "unknown"
            ),
            "CB-0734": (
                own["unsubscribe"]["status"] == "clear"
                and audience["cases"]["unsubscribed"]["unsubscribe"]["status"] == "active"
                and own["unsubscribe"]["state_ref"] != own["suppression"]["state_ref"]
            ),
            "CB-0735": (
                len({own[k]["state_ref"] for k in
                     ("consent", "suppression", "unsubscribe")}) == 3
                and all(set(own[k]) == {
                    "state_ref", "status", "source_ref", "observed_at", "freshness"
                } for k in ("consent", "suppression", "unsubscribe"))
                and all(own[k]["freshness"] == "current"
                        and isinstance(own[k]["observed_at"], int)
                        and bool(REF.fullmatch(own[k]["source_ref"]))
                        for k in ("consent", "suppression", "unsubscribe"))
                and audit["suppression"]["freshness"] == "unknown"
            ),
            "CB-0736": (
                own["marketing_eligible"] is True
                and all(audience["cases"][k]["marketing_eligible"] is False
                        for k in ("denied", "suppressed", "unsubscribed", "stale"))
                and audit["marketing_eligible"] is False
                and audience["transactional"]["marketing_eligible"] is False
                and audience["transactional"]["execution"] is False
            ),
            "CB-0738": (
                exp["cross_venture"] is True
                and valid["baseline_ref"] not in valid["variant_refs"]
                and len(set(valid["variant_refs"])) == len(valid["variant_refs"])
                and valid["window"]["start_at"] < valid["window"]["end_at"]
                and all(invalid_exp[k] is True for k in
                        ("duplicate", "baseline_duplicate", "window", "foreign"))
                and all(ready[k] is True for k in
                        ("missing_baseline", "missing_metric", "missing_window"))
            ),
            "CB-0739": (
                {results[k]["result"]["state"] for k in
                 ("observed", "inferred", "unknown")} ==
                {"observed", "inferred", "unknown"}
                and all(results[k]["result"]["freshness"] == "current"
                        and results[k]["result"]["observed_at"] is not None
                        and results[k]["result"]["source_ref"] is not None
                        and bool(results[k]["result"]["evidence_refs"])
                        for k in ("observed", "inferred"))
            ),
            "CB-0740": (
                all(results["unknown"]["result"][k] is None for k in
                    ("winner_ref", "effect_bps", "source_ref", "observed_at"))
                and results["unknown"]["result"]["evidence_refs"] == []
                and results["unknown"]["result"]["freshness"] == "unknown"
                and invalid_exp["sensitive"] is True
            ),
            "CB-0741": (
                paid["ok"]["status"] in ("planned", "owner_decision_required")
                and paid["ok"]["execution"] is False
                and paid["ok"]["authority"]["capability"] == "momentum.paid_media.plan"
                and paid["ok"]["authority"]["required_authority_level"] ==
                    "L2_VENTURE_ADMIN"
                and paid["array"]["status"] == "denied"
                and paid["object"]["status"] == "denied"
                and authority["caller"]["status"] == "denied"
                and all(authority[k]["status"] == "owner_decision_required"
                        for k in ("launch_l1", "pause_l1", "reallocate_l1"))
                and all(mismatch[k]["status"] == "denied" for k in
                        ("scope", "capability", "policy", "invalid"))
                and mismatch["owner"]["status"] == "owner_decision_required"
            ),
        }
        self.assertEqual(set(checks), set(TARGETS))
        for identifier in TARGETS:
            with self.subTest(brecha=identifier):
                self.assertTrue(checks[identifier], f"{identifier} no acreditada")

    def test_falla_cerrado(self) -> None:
        """Rechazos observables, no inferidos de documentación."""
        cases = {
            "campaign/privacy": scenario("campaign", "privacy"),
            "campaign/invalid": {
                k: scenario("campaign", "closed")[k] for k in (
                    "duplicate_rejected", "extra_rejected", "schedule_rejected",
                    "active_without_start_rejected", "completed_without_start_rejected"
                )
            },
            "creative": {
                "approval": scenario("creative", "lifecycle")["missing_rejected"],
                "duplicate": scenario("creative", "set")["duplicate_rejected"],
            },
            "email/scope": {k: scenario("email", "scope")[k]
                            for k in ("cross_venture", "channel_mismatch")},
            "email/invalid": scenario("email", "invalid"),
            "experiment/invalid": {
                k: scenario("experiment", "invalid")[k] for k in (
                    "duplicate", "baseline_duplicate", "window",
                    "extra", "sensitive", "foreign")
            },
        }
        for group, checks in cases.items():
            for reason, rejected in checks.items():
                with self.subTest(group=group, reason=reason):
                    self.assertIs(rejected, True)
        for key in ("future", "expired", "stale", "unknown"):
            row = scenario("paid", "freshness")[key]
            with self.subTest(group="CB-0741", reason=key):
                self.assertEqual(row["status"], "denied")
                self.assertIs(row["execution"], False)

    def test_sin_datos_reales(self) -> None:
        """Solo datos sintéticos y referencias; jamás tokens ni destinatarios."""
        for domain, name in (
            ("campaign", "neutral"), ("creative", "neutral"),
            ("email", "scope"), ("experiment", "scope"), ("paid", "nominal")
        ):
            data = json.dumps(scenario(domain, name), ensure_ascii=False)
            with self.subTest(domain=domain):
                self.assertNotRegex(data, r"(?i)\bBearer\s+[A-Za-z0-9._~+/=-]{12,}")
                self.assertNotRegex(data, r"(?i)(?:ghp_|github_pat_)[A-Za-z0-9_]{8,}")
                self.assertNotRegex(data, r"(?i)\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b")
                self.assertNotIn("https://", data)
                self.assertNotIn("http://", data)
        for row in (
            scenario("campaign", "neutral")["campaign"],
            scenario("email", "scope")["program"],
            scenario("experiment", "scope")["valid"],
            scenario("paid", "nominal")["ok"]
        ):
            self.assertIs(row["execution"], False)

    def test_estados_unknown_explicitos(self) -> None:
        """La matriz no se altera hasta una reconciliación independiente."""
        rows = {}
        for path in ("docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-03.md"):
            for line in (ROOT / path).read_text(encoding="utf-8").splitlines():
                match = re.match(r"^\|\s*(CB-07\d\d)\s*\|", line)
                if match and match.group(1) in TARGETS:
                    rows.setdefault(match.group(1), []).append(line)
        self.assertEqual(set(rows), set(TARGETS))
        for identifier in TARGETS:
            with self.subTest(brecha=identifier):
                self.assertEqual(len(rows[identifier]), 1)
                line = rows[identifier][0]
                self.assertTrue(e3_coverage([p.strip() for p in line.strip("|").split("|")]))
                if "| NO_VERIFICABLE |" in line:
                    self.assertIn("offline=unknown", line.lower())
                self.assertIn("live=unknown", line.lower())
                self.assertIn("operando=unknown", line.lower())
        email = scenario("email", "audit")
        self.assertFalse(email["marketing_eligible"])
        self.assertEqual(email["suppression"]["freshness"], "unknown")
        result = scenario("experiment", "result")["unknown"]["result"]
        self.assertEqual(result["state"], "unknown")
        self.assertIsNone(result["winner_ref"])
        self.assertIsNone(result["effect_bps"])


if __name__ == "__main__":
    unittest.main()

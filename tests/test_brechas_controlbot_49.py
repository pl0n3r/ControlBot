"""CB-0742..CB-0756: contrastes MOMENTUM offline con datos sintéticos.

No certifica proveedores, gasto real, operación live ni cambios de la matriz.
"""
from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = {
    "paid": "momentum_paid_media_scenarios.php",
    "performance": "momentum_performance_scenarios.php",
    "revenue": "momentum_revenue_scenarios.php",
}
IDS = tuple(f"CB-{i:04d}" for i in range(742, 757))


@lru_cache(maxsize=24)
def scenario(family: str, case: str) -> dict:
    """Ejecuta solo casos PHP locales ya existentes, sin shell ni red."""
    php = shutil.which("php")
    if php is None:
        raise RuntimeError("PHP obligatorio para los contrastes de comportamiento")
    run = subprocess.run(
        [php, str(ROOT / "tests" / SCENARIOS[family]), case],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=45,
    )
    return json.loads(run.stdout)


class BrechasControlbot49Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: 15 brechas con aserciones semánticas de comportamiento."""
        pf = scenario("paid", "freshness")
        pe = scenario("paid", "evidence")
        pp = scenario("paid", "pure")
        ps = scenario("performance", "scope")
        pc = scenario("performance", "chain")
        pk = scenario("performance", "classification")
        pu = scenario("performance", "unit")
        pg = scenario("performance", "guardrails")
        pt = scenario("performance", "pure")
        ra = scenario("revenue", "attribution")
        rf = scenario("revenue", "forecast")
        full = pc["full"]
        checks = {
            # Evidencia futura o caducada no hereda current.
            "CB-0742": (
                pf["current"]["status"] == "planned"
                and pf["future"]["reasons"] == ["evidence_from_future"]
                and pf["expired"]["reasons"] == ["evidence_expired"]),
            # Coincidencia de presupuesto y propuesta capital.
            "CB-0743": (
                pe["budget_mismatch"]["status"] == "denied"
                and pe["capital_mismatch"]["status"] == "denied"),
            # Performance es proyección pura, sin autoridad ni ejecución.
            "CB-0744": (
                pt["methods"] == ["project"] and full["execution"] is False
                and full["derived"]["observed_revenue_minor"] == 9000000),
            # Spend planificado nunca se eleva a observado.
            "CB-0745": (
                pc["planned"] == 2000000
                and full["derived"]["spend_minor"] == 2500000
                and full["paid_media_spend_ref"] == full["spend"]["governed_spend_ref"]),
            # Venture, Campaign, período y moneda cerrados al scope.
            "CB-0746": (
                all(ps[k] for k in ("venture", "campaign", "period", "paid_denied"))
                and pg["currency"] is True),
            # Spend observed requiere evidencia independiente y spend_ref.
            "CB-0747": (
                full["spend"]["classification"] == "observed"
                and full["spend"]["freshness"] == "current"
                and bool(full["spend"]["evidence_refs"])
                and full["spend"]["governed_spend_ref"] == full["paid_media_spend_ref"]),
            # Funnel observado: contadores más evidencia.
            "CB-0748": (
                full["funnel"]["classification"] == "observed"
                and full["funnel"]["leads"] == 100
                and full["funnel"]["conversions"] == 20
                and bool(full["funnel"]["evidence_refs"])),
            # Cost observado separado; sin cost no se deriva margin.
            "CB-0749": (
                full["cost"]["classification"] == "observed"
                and full["cost"]["amount_minor"] == 1000000
                and bool(full["cost"]["evidence_refs"])
                and pu["no_cost"]["derived"]["margin_minor"] is None),
            # Rechazar duplicados, timestamps fuera de período y refs falsas.
            "CB-0750": all(
                pg[k] is True for k in ("duplicate", "missing", "outside", "spend_ref")),
            # Stale/unknown no se convierten en current.
            "CB-0751": (
                pg["stale"]["freshness"] == "stale"
                and pg["stale"]["derived"]["spend_minor"] is None
                and pg["pipeline_stale"]["derived"]["observed_revenue_minor"] is None),
            # Inferred revenue nunca entra a margin/ROAS observados.
            "CB-0752": (
                pk["inferred"]["derived"]["observed_revenue_minor"] is None
                and pk["inferred"]["derived"]["inferred_revenue_minor"] == 9000000
                and pk["inferred"]["derived"]["margin_minor"] is None
                and pu["inferred"]["unit_economics"]["roas_milli"] is None),
            # Payback y LTV permanecen unknown.
            "CB-0753": (
                pu["full"]["unit_economics"]["payback_classification"] == "unknown"
                and pu["full"]["unit_economics"]["ltv_classification"] == "unknown"
                and pu["full"]["unit_economics"]["payback_days"] is None
                and pu["full"]["unit_economics"]["ltv_minor"] is None),
            # No ejecutar campañas ni proveedores desde la proyección.
            "CB-0754": (
                pt["methods"] == ["project"] and pt["execution"] is False
                and pp["plan"]["execution"] is False),
            # Paid Media intención; Revenue atribución; Performance métricas.
            "CB-0755": (
                pp["plan"]["status"] == "planned"
                and ra["observed"]["classification"] == "observed"
                and ra["unknown"]["demonstrated_amount_minor"] is None
                and full["revenue"]["classification"] == "observed"),
            # Forecast limitado, no ingreso demostrado.
            "CB-0756": (
                rf["forecast"]["classification"] == "forecast"
                and rf["forecast"]["amount_minor"] == 12500000
                and rf["forecast"]["currency"] == "COP"
                and rf["forecast"]["confidence"] == 70
                and rf["forecast"]["freshness"] == "current"
                and rf["forecast"]["source_ref"].startswith("source:")
                and rf["forecast"]["demonstrated_revenue"] is False
                and rf["bad_confidence_rejected"] is True
                and rf["bad_freshness_rejected"] is True),
        }
        self.assertEqual(set(checks), set(IDS))
        for identifier in IDS:
            with self.subTest(brecha=identifier):
                self.assertTrue(checks[identifier], f"{identifier}: sin evidencia")

    def test_falla_cerrado(self):
        """AC-02: missing, ambiguous y stale nunca acreditan éxito."""
        paid = scenario("paid", "freshness")
        for status in ("stale", "unknown", "future", "expired"):
            with self.subTest(estado=status):
                self.assertEqual(paid[status]["status"], "denied")
        guard = scenario("performance", "guardrails")
        for key in ("missing", "duplicate", "outside", "spend_ref", "paid_stale"):
            with self.subTest(entrada=key):
                self.assertTrue(guard[key])
        unknown = scenario("performance", "chain")["unknown_spend"]
        self.assertEqual(unknown["spend"]["classification"], "unknown")
        self.assertIsNone(unknown["derived"]["spend_minor"])
        self.assertIsNone(unknown["derived"]["margin_minor"])
        attr = scenario("revenue", "attribution")
        self.assertIsNone(attr["unknown"]["amount_minor"])
        self.assertIsNone(attr["unknown"]["demonstrated_amount_minor"])
        self.assertTrue(attr["unknown_claim_rejected"])
        self.assertTrue(attr["observed_without_evidence_rejected"])

    def test_sin_datos_reales(self):
        """AC-03: sin PII, red, proveedores, gasto o persistencia."""
        for module in ("MomentumPerformance.php", "MomentumRevenue.php"):
            source = (ROOT / "src" / module).read_text(encoding="utf-8").lower()
            for forbidden in ("curl_", "file_get_contents(", "http://", "https://",
                              "pdo(", "mysqli", "factoryrunner", "scheduler", "workitem"):
                with self.subTest(module=module, forbidden=forbidden):
                    self.assertNotIn(forbidden, source)
        perf = scenario("performance", "pure")
        paid = scenario("paid", "pure")
        self.assertFalse(perf["execution"])
        self.assertFalse(paid["plan"]["execution"])
        self.assertRegex(
            scenario("performance", "chain")["full"]["campaign_ref"],
            r"^campaign:[a-f0-9]{32}$",
        )

    def test_estados_unknown_explicitos(self):
        """AC-04: unknown real sigue siendo no verificable."""
        chain = scenario("performance", "chain")
        unit = scenario("performance", "unit")
        classified = scenario("performance", "classification")
        self.assertEqual(chain["unknown_spend"]["spend"]["classification"], "unknown")
        self.assertIsNone(chain["unknown_spend"]["derived"]["margin_minor"])
        self.assertEqual(unit["no_cost"]["cost"]["classification"], "unknown")
        self.assertIsNone(unit["no_cost"]["derived"]["margin_minor"])
        self.assertEqual(unit["full"]["unit_economics"]["payback_classification"], "unknown")
        self.assertEqual(unit["full"]["unit_economics"]["ltv_classification"], "unknown")
        self.assertEqual(classified["unknown"]["revenue"]["classification"], "unknown")
        self.assertIsNone(classified["unknown"]["derived"]["observed_revenue_minor"])
        self.assertIsNone(classified["unknown"]["derived"]["inferred_revenue_minor"])


if __name__ == "__main__":
    unittest.main()

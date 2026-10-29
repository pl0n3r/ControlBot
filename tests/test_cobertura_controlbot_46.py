#!/usr/bin/env python3
"""#1229: executable offline subcontracts for 19 MOMENTUM/Observability rows.

Only local checked-in PHP scenarios call the production classes. Nothing here
reads a coverage matrix, contacts a provider or handles tenant/customer data.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "revenue": "momentum_revenue_scenarios.php",
    "work": "momentum_work_origin_scenarios.php",
    "event": "observability_event_scenarios.php",
    "incident": "observability_incident_scenarios.php",
}
CASES = {
    "revenue": frozenset({"funnel", "forecast", "attribution"}),
    "work": frozenset({"valid", "gates", "identity", "e2e", "drift", "unsafe"}),
    "event": frozenset({"freshness", "incident78", "security"}),
    "incident": frozenset({"deploy", "separate", "recovery", "severity", "dedupe", "pure"}),
}
# CB-0763 is an institutional responsibility allocation, not a callable
# guarantee that remote Factory/FactoryRunner/AEGIS/CAPITAL honor the boundary.
NON_EXECUTABLE = {
    "CB-0763": "C: division of responsibilities across external products is policy",
}
EXECUTABLE = frozenset({
    "CB-0756", "CB-0757", "CB-0758", "CB-0759",
    "CB-0760", "CB-0761", "CB-0762", "CB-0764", "CB-0765", "CB-0766",
    "CB-0768", "CB-0769", "CB-0771", "CB-0772", "CB-0773",
    "CB-0774", "CB-0775", "CB-0776",
})


@lru_cache(maxsize=32)
def php(family: str, case: str) -> dict:
    """Run a whitelisted real PHP scenario in a disposable local environment."""
    if family not in SCRIPTS or case not in CASES[family]:
        raise AssertionError("UNKNOWN: unrecognized PHP scenario")
    binary = shutil.which("php")
    script = ROOT / "tests" / SCRIPTS[family]
    if binary is None or not script.is_file():
        raise AssertionError("UNKNOWN: PHP interpreter or checked-in scenario missing")
    with tempfile.TemporaryDirectory(prefix="cb-1229-") as home:
        allowed_env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": home,
            "LANG": "C",
        }
        # The coverage harness wraps php and needs this local executable path.
        real_php = os.environ.get("CONTROLBOT_REAL_PHP")
        if real_php:
            allowed_env["CONTROLBOT_REAL_PHP"] = real_php
        result = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0", str(script), case],
            cwd=ROOT,
            env=allowed_env,
            capture_output=True, text=True, timeout=60, check=False,
        )
    if result.returncode or result.stderr.strip():
        raise AssertionError(f"UNKNOWN: PHP {family}/{case} failed: {result.stderr[-800:]}")
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: malformed PHP output: " + result.stdout[:400]) from exc
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: PHP scenario did not return an object")
    return data


class CoberturaControlbot46Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: explicit predicates exercise production PHP for 18 offline rows."""
        self.assertEqual(len(EXECUTABLE | NON_EXECUTABLE.keys()), 19)
        self.assertFalse(EXECUTABLE & NON_EXECUTABLE.keys())
        forecast = php("revenue", "forecast")["forecast"]
        attribution = php("revenue", "attribution")
        funnel = php("revenue", "funnel")
        valid = php("work", "valid")
        item = valid["work_item"]
        e2e = php("work", "e2e")
        identity = php("work", "identity")
        gates = php("work", "gates")
        fresh = php("event", "freshness")
        capacity = php("event", "incident78")
        deploy = php("incident", "deploy")
        separate = php("incident", "separate")
        recovery = php("incident", "recovery")
        severity = php("incident", "severity")
        incident = php("incident", "dedupe")["incidents"][0]
        expected_incident = {"version", "incident_id", "project_id", "environment_id", "status",
                             "severity", "primary_fingerprint", "event_fingerprints",
                             "correlation_reason", "first_seen_at", "last_seen_at", "opened_at",
                             "last_event_at", "resolved_at", "occurrence_count", "timeline_events"}
        predicates = {
            "CB-0756": forecast["classification"] == "forecast"
                and forecast["demonstrated_revenue"] is False
                and forecast["amount_minor"] == 12500000
                and forecast["currency"] == "COP"
                and forecast["confidence"] == 70
                and forecast["freshness"] == "current"
                and forecast["source_ref"].startswith("source:"),
            "CB-0757": attribution["observed"]["demonstrated_amount_minor"] == 12500000
                and attribution["inferred"]["demonstrated_amount_minor"] is None
                and attribution["unknown"]["demonstrated_amount_minor"] is None,
            "CB-0758": funnel["lead"]["stage"] == "lead"
                and funnel["won"]["stage"] == "won"
                and funnel["won"]["qualification"] == "qualified",
            "CB-0759": forecast["demonstrated_revenue"] is False
                and attribution["inferred"]["classification"] == "inferred"
                and attribution["inferred"]["demonstrated_amount_minor"] is None,
            "CB-0760": e2e["out"]["status"] == "materialized"
                and e2e["campaign"]["campaign_id"] == e2e["paid"]["campaign_id"]
                and e2e["creative"]["variant_id"] in e2e["campaign"]["creative_variant_refs"]
                and e2e["email"]["marketing_eligible"] is True
                and e2e["experiment"]["status"] == "completed"
                and e2e["experiment"]["result"]["freshness"] == "current"
                and e2e["performance"]["freshness"] == "current",
            "CB-0761": e2e["out"]["work_item"] == item
                and php("work", "drift")["upstream_rejected"] is True,
            "CB-0762": all(row["status"] == "blocked" and row["work_item"] is None
                and row["execution"] is False and row["gate_ref"]
                for row in gates.values()),
            "CB-0764": item["authority_level"] == "l2_venture_admin"
                and item["budget_ref"] == e2e["paid"]["spend"]["budget_ref"]
                and item["policy_ref"] == "controlbot:policy/business-os-v1"
                and item["producer_ref"] == "momentum:work-origin",
            "CB-0765": item["origin_mode"] == "automatic"
                and item["origin_system"] == "momentum"
                and "freshness" not in item and "execution" not in item
                and valid["execution"] is False,
            "CB-0766": all(row["reasons"] == ["invalid_input"] and row["work_item"] is None
                for row in php("work", "unsafe").values()),
            "CB-0768": fresh["fresh"] == "fresh" and fresh["stale"] == "stale"
                and fresh["unknown"] == "unknown" and fresh["delayed"] == "stale",
            "CB-0769": capacity["payload_allowlisted"]["application_status"] == "unknown"
                and capacity["payload_allowlisted"]["startup_failure"] is True
                and capacity["payload_allowlisted"]["steps"] is None,
            "CB-0771": deploy["linked"]["incidents"][0]["correlation_reason"] == "post_deploy_explicit"
                and len(deploy["linked"]["incidents"][0]["event_fingerprints"]) == 2
                and deploy["too_late"]["incidents"][0]["correlation_reason"] == "new_incident",
            "CB-0772": len(separate["incidents"]) == 2
                and severity["forward"] == severity["reverse"]
                and severity["forward"]["incidents"][0]["severity"] == "critical",
            "CB-0773": recovery["resolved"]["incidents"][0]["status"] == "resolved"
                and recovery["stale_status"] == "open"
                and recovery["unknown_status"] == "open",
            "CB-0774": set(incident) == expected_incident
                and incident["occurrence_count"] == 2
                and len(incident["timeline_events"]) == 1,
            "CB-0775": all(not key.startswith("_") for key in incident)
                and "_evidence_keys" not in incident and "_keys" not in incident,
            "CB-0776": php("incident", "pure")["hits"] == []
                and valid["execution"] is False,
        }
        self.assertEqual(set(predicates), EXECUTABLE)
        for identifier, passed in predicates.items():
            with self.subTest(row=identifier):
                self.assertTrue(passed, "Unproven code behavior: " + identifier)

    def test_falla_cerrado(self) -> None:
        """AC-02: permissions, invalid input, freshness and ambiguous evidence deny."""
        forecast = php("revenue", "forecast")
        for key in ("bad_confidence_rejected", "bad_freshness_rejected"):
            self.assertTrue(forecast[key], key)
        attribution = php("revenue", "attribution")
        for key in ("unknown_claim_rejected", "observed_without_evidence_rejected",
                    "pre_won_revenue_rejected", "campaign_mismatch_rejected"):
            self.assertTrue(attribution[key], key)
        funnel = php("revenue", "funnel")
        for key in ("bad_stage_rejected", "bad_qualification_rejected", "extra_rejected"):
            self.assertTrue(funnel[key], key)
        gates = php("work", "gates")
        self.assertIn("authority_owner_gate", gates["owner"]["reasons"])
        self.assertIn("capital_denied", gates["budget"]["reasons"])
        self.assertIn("authority_unknown", gates["unknown"]["reasons"])
        self.assertEqual(gates["paid_stale"]["freshness"], "stale")
        self.assertEqual(gates["origin_unknown"]["freshness"], "unknown")
        self.assertIn("email_not_eligible", gates["email_denied"]["reasons"])
        self.assertTrue(php("event", "freshness")["future_rejected"])
        event_security = php("event", "security")
        for k in ("secret", "email", "bearer", "nested", "extra", "wrong_type", "secret_key"):
            self.assertTrue(event_security[k], k)
        self.assertEqual(php("incident", "recovery")["unknown_status"], "open")

    def test_sin_datos_reales(self) -> None:
        """AC-03: no external calls, secrets, credentials or customer fixtures."""
        self.assertEqual(php("work", "valid")["execution"], False)
        self.assertEqual(php("incident", "pure")["hits"], [])
        own = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                names = {n.name.split(".")[0] for n in node.names}
                self.assertFalse(names & {"socket", "requests", "urllib", "httpx", "pymysql"})
        self.assertNotIn("matriz" + "-cobertura", own)
        self.assertEqual(len(SCRIPTS), 4)


if __name__ == "__main__":
    unittest.main()
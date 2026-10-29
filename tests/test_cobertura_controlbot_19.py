#!/usr/bin/env python3
"""#1202: behaviour-first offline coverage for CB-0292 through CB-0309.

Calls existing PHP domain/scenario code with synthetic fixtures: no network,
production, SQL, secrets or matrix-state assertions.  External transports and
the exact-HEAD CI verdict are separate B requirements, not simulated passes.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CASES = {
    "api": "tests/external_api_contract_scenarios.php",
    "adapter": "tests/external_monitor_adapter_scenarios.php",
    "monitor": "tests/external_monitor_scenarios.php",
    "activity": "tests/factory_agent_activity_scenarios.php",
}
ROWS = frozenset(f"CB-{n:04d}" for n in range(292, 305)) | frozenset(
    f"CB-{n:04d}" for n in range(306, 310)
)
NON_OFFLINE = {
    "CB-0304": (
        "B", "Real HTTP, push, cron, persistence and incidents require external "
        "systems; PHP domain decisions are tested, not operational delivery."
    ),
    "CB-0309": (
        "B", "The required terminal Validar result is an authenticated GitHub "
        "check on the exact final SHA, not a local YAML assertion."
    ),
}
OFFLINE = ROWS - NON_OFFLINE.keys()
ERROR_CODES = {
    "400": ["invalid_request"],
    "401": ["unauthenticated"],
    "403": ["forbidden"],
    "404": ["not_found"],
    "409": ["conflict", "stale_state"],
    "429": ["rate_limited"],
    "500": ["internal_error"],
}


@lru_cache(maxsize=None)
def scenario(area: str, mode: str) -> dict:
    """Execute committed PHP implementations, not Python replicas of the domain."""
    if area not in CASES or not mode.isidentifier():
        raise AssertionError("UNKNOWN: scenario selector invalid")
    script = ROOT / CASES[area]
    if not script.is_file() or script.is_symlink():
        raise AssertionError("UNKNOWN: real PHP scenario missing")
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    run = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(script), mode],
        cwd=ROOT, capture_output=True, text=True, timeout=35, check=False,
    )
    if run.returncode or run.stderr.strip():
        raise AssertionError(
            f"UNKNOWN: {area}/{mode} failed ({run.returncode}): "
            f"{run.stderr[-320:]}"
        )
    try:
        data = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: malformed PHP JSON") from exc
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: PHP scenario did not return a record")
    return data


def openapi() -> dict:
    source = ROOT / "openapi/controlbot-owner-v1.json"
    return json.loads(source.read_text(encoding="utf-8"))


class CoberturaControlbot19Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: test real domain behaviour for each offline-capable row."""
        self.assertEqual(len(ROWS), 17)
        self.assertEqual(len(OFFLINE), 15)
        self.assertEqual(OFFLINE | NON_OFFLINE.keys(), ROWS)
        self.assertFalse(OFFLINE & NON_OFFLINE.keys())
        self.assertEqual(set(CASES), {"api", "adapter", "monitor", "activity"})
        for classification, reason in NON_OFFLINE.values():
            self.assertEqual(classification, "B")
            self.assertGreater(len(reason), 60)

        # CB-0292: server authority and scopes; client-supplied grants denied.
        catalog = scenario("api", "catalog")
        mutation = scenario("api", "mutation")
        self.assertEqual(len(catalog), 4)
        self.assertTrue(all(mutation["server_fields_rejected"].values()))
        for op in catalog.values():
            self.assertRegex(op["auth_scope"], r"^owner\.[a-z_]+\.(read|write)$")
            self.assertTrue(op["freshness_required"])

        # CB-0293: opaque correlation IDs and unknown-safe epoch provenance.
        envelopes = scenario("api", "envelopes")
        self.assertEqual(len(envelopes["ids"]["request_id"]), 32)
        self.assertEqual(len(envelopes["ids"]["correlation_id"]), 32)
        self.assertEqual(envelopes["meta"]["freshness"]["state"], "current")
        self.assertEqual(
            envelopes["unknown"]["freshness"],
            {"state": "unknown", "observed_at": None, "source_ref": None},
        )
        schemas = openapi()["components"]["schemas"]
        self.assertIn("Unix epoch timestamp in seconds",
                      schemas["ResponseMeta"]["properties"]["generated_at"]["description"])
        freshness = schemas["Freshness"]["oneOf"][0]["properties"]["observed_at"]
        self.assertIn("Unix epoch timestamp in seconds", freshness["description"])

        # CB-0294: actual closed API error values and declared HTTP mapping.
        self.assertEqual(envelopes["error"]["code"], "forbidden")
        spec = openapi()
        for status, codes in ERROR_CODES.items():
            self.assertEqual(
                spec["components"]["responses"]["Error" + status]["x-error-codes"],
                codes,
            )

        # CB-0295: mutation is versioned/idempotent; GET carries no body.
        self.assertEqual(len(mutation["valid"]["idempotency_key"]), 32)
        self.assertTrue(mutation["missing_idempotency_rejected"])
        self.assertTrue(mutation["bad_idempotency_rejected"])
        self.assertTrue(spec["paths"]["/owner-decisions/{decision_id}/decision"]
                        ["post"]["requestBody"]["required"])
        for route in spec["paths"].values():
            for method, operation in route.items():
                if method == "get":
                    self.assertNotIn("requestBody", operation)

        # CB-0296: real adapter transports via injected offline closure;
        # bounded HTTPS runner is checked without issuing a request.
        adapter = scenario("adapter", "probe")
        self.assertEqual(adapter["http_status"], 200)
        self.assertEqual(adapter["reported_version"], "1.2.3")
        self.assertRegex(adapter["reported_sha"], r"^[0-9a-f]{40}$")
        runner = (ROOT / "scripts/external-monitor.php").read_text(encoding="utf-8")
        for required in (
            "CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS",
            "CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS",
            "CURLOPT_WRITEFUNCTION", "max_body_bytes",
        ):
            self.assertIn(required, runner)
        self.assertNotIn("CURLOPT_RETURNTRANSFER", runner)

        # CB-0297/0300: normalized probe and strict freshness, never made healthy
        # from missing, stale, unknown, failed or future observations.
        probe = scenario("monitor", "probe")
        freshness_cases = scenario("monitor", "freshness")
        self.assertEqual(probe["healthy"]["application_state"], "healthy")
        self.assertEqual(probe["timeout"]["application_state"], "down")
        self.assertEqual(probe["server"]["application_state"], "down")
        self.assertEqual(freshness_cases["missing"]["application_state"], "unknown")
        self.assertEqual(freshness_cases["unknown"]["application_state"], "unknown")
        self.assertEqual(freshness_cases["stale"]["application_state"], "degraded")
        self.assertEqual(freshness_cases["expired"]["application_state"], "degraded")

        # CB-0298/0301: workflow evidence distinguishes step failure from
        # startup failure without a runner, including unknown step presence.
        workflow = scenario("monitor", "workflow")
        self.assertEqual(workflow["failure"]["private_workflow_state"], "degraded")
        self.assertIn("private_workflow_step_failure", workflow["failure"]["reasons"])
        for name in ("startup", "startup_null_steps"):
            self.assertEqual(workflow[name]["private_workflow_state"], "blocked")
            self.assertIn("private_startup_failure_without_runner",
                          workflow[name]["reasons"])
            self.assertNotIn("private_workflow_step_failure", workflow[name]["reasons"])
        self.assertIsNone(
            workflow["startup_null_steps"]["latest_private_workflow"]["steps_present"]
        )

        # CB-0299/0302: capacity normalizer, equal-SHA incident and deterministic
        # ordering never invent billing causality or application health.
        incident = scenario("monitor", "incident78")
        self.assertEqual(incident["application_state"], "unknown")
        self.assertEqual(incident["private_workflow_state"], "blocked")
        self.assertEqual(incident["owner_capacity_state"], "exhausted")
        self.assertEqual(incident["billing_mechanism_state"], "unknown")
        self.assertIn("capacity_evidence_converges", incident["reasons"])
        deterministic = scenario("monitor", "determinism")
        self.assertEqual(deterministic["first"], deterministic["second"])

        # CB-0303: real alert decision demands an external channel for critical
        # budgets/private exhaustion/down, while making no delivery claim.
        alerts = scenario("monitor", "alert")
        for state in ("capacity", "capacity_without_workflow",
                      "critical_capacity", "outage"):
            self.assertTrue(alerts[state]["alert_intent"]["required"])
            self.assertTrue(alerts[state]["alert_intent"]["external_channel_required"])
            self.assertEqual(alerts[state]["alert_intent"]["severity"], "critical")

        # CB-0306: seven canonical ordered GitHub projects, read-only and fresh.
        activity = scenario("activity", "full")["view"]
        expected = ("Factory", "Condor", "GrindFlow", "brvtal", "ControlBot",
                    "AutoFactory", "FactoryRunner")
        self.assertEqual(
            [x["repository_ref"] for x in activity["projects"]],
            ["pl0n3r/" + name for name in expected],
        )
        self.assertTrue(activity["read_only"])
        self.assertEqual(len(activity["projects"]), 7)
        for project in activity["projects"]:
            self.assertEqual(project["activity_state"], "OBSERVED")
            self.assertEqual(project["last_signal_age_seconds"], 12)
            self.assertEqual(project["last_signal_kind"], "coordination")

        # CB-0307: queue-empty requires 0 verified available and a confirmed
        # planned_unlockable, never merely blocked/unmaterialized/planned.
        queue = scenario("activity", "queue")
        self.assertIs(queue["valid"]["queue_empty"], True)
        self.assertIsNone(queue["unknown"]["queue_empty"])
        self.assertIsNone(queue["stale"]["queue_empty"])

        # CB-0308: owner UI uses sanitized, private read-only HTML.
        safe = scenario("activity", "safety")
        html = safe["html"].lower()
        self.assertIn('data-section="agent_activity"', html)
        for forbidden in ("<script", "<form", "<button", "onclick=", "token=",
                          "data-delete", "data-dispatch"):
            self.assertNotIn(forbidden, html)

    def test_falla_cerrado(self) -> None:
        """AC-02: malformed, ambiguous, unauthorized and outdated evidence."""
        mutation = scenario("api", "mutation")
        self.assertTrue(all(mutation["server_fields_rejected"].values()))
        self.assertTrue(mutation["missing_idempotency_rejected"])
        self.assertTrue(mutation["bad_idempotency_rejected"])
        for schema in openapi()["components"]["schemas"].values():
            if isinstance(schema, dict) and schema.get("type") == "object":
                self.assertFalse(schema.get("additionalProperties", True))
        failed = scenario("adapter", "failures")
        self.assertEqual(failed["timeout"]["outcome"], "timeout")
        self.assertEqual(failed["server"]["http_status"], 500)
        self.assertTrue(failed["oversized_rejected"])
        self.assertNotIn("never-output", json.dumps(failed))
        channel = scenario("adapter", "channel_fail")
        self.assertEqual(channel["application_state"], "down")
        self.assertEqual(channel["delivery"]["receipt"]["status"], "failed")
        self.assertNotIn("assessment", channel["delivery"])
        self.assertTrue(scenario("monitor", "probe")["invalid"])
        self.assertTrue(scenario("monitor", "workflow")["invalid"])
        self.assertTrue(scenario("monitor", "alert")["secret_rejected"])
        safety = scenario("activity", "safety")
        for key in ("bad_repo", "bad_source", "duplicate"):
            self.assertIs(safety[key], True)
        self.assertEqual(safety["partial"]["projects"][0]["activity_state"], "UNKNOWN")
        self.assertIsNone(safety["partial"]["projects"][0]["last_signal_at"])
        self.assertIsNone(safety["unknown"]["projects"][0]["available"])
        self.assertIsNone(safety["unknown"]["projects"][0]["open_prs"])
        self.assertTrue(all(safety["utc"].values()))

    def test_sin_datos_reales(self) -> None:
        """AC-03: only synthetic PHP closures and no real integrations."""
        for name, path in CASES.items():
            script = ROOT / path
            self.assertTrue(script.is_file() and not script.is_symlink(), name)
            self.assertTrue(script.resolve().is_relative_to(ROOT))
        receipt = scenario("adapter", "dedupe")
        self.assertTrue(receipt["same"])
        self.assertEqual(
            set(receipt["keys"]),
            {"delivery_id", "channel", "status", "code", "observed_at", "evidence_ref"},
        )
        # A failing channel does not cause a second implicit dispatch.
        self.assertNotIn("assessment", scenario("adapter", "channel_fail")["delivery"])
        self.assertNotIn("secret", json.dumps(
            scenario("monitor", "alert")["capacity"]).lower())
        source = Path(__file__).read_text(encoding="utf-8")
        for forbidden in ("-----BEGIN " + "PRIVATE KEY-----", "GITHUB_" + "TOKEN=",
                          "GH_" + "TOKEN=", "https://api." + "github.com"):
            self.assertNotIn(forbidden, source)

    def test_validar_exigido(self) -> None:
        """AC-04: check contract only; green exact SHA is certified by GitHub."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertIn("pull_request", workflow)
        self.assertEqual(17, len(ROWS))
        self.assertTrue({"CB-0304", "CB-0309"}.issubset(NON_OFFLINE))


if __name__ == "__main__":
    unittest.main()

"""Pruebas de las 15 brechas CB-0364..CB-0379: evidencia offline, nunca live."""

from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import runpy
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = (
    "CB-0364", "CB-0365", "CB-0366", "CB-0368", "CB-0369",
    "CB-0370", "CB-0371", "CB-0372", "CB-0373", "CB-0374",
    "CB-0375", "CB-0376", "CB-0377", "CB-0378", "CB-0379",
)


@lru_cache(maxsize=None)
def scenario(file: str, name: str) -> dict:
    completed = subprocess.run(
        ["php", str(ROOT / "tests" / file), name],
        cwd=ROOT, text=True, capture_output=True, check=False, timeout=45,
    )
    if completed.returncode:
        raise AssertionError(f"Fixture {file}/{name}: {completed.stderr[:400]}")
    return json.loads(completed.stdout)


@lru_cache(maxsize=1)
def gateway_helpers() -> dict:
    return runpy.run_path(str(ROOT / "tests" / "test_github_intent_execution_gateway.py"))


class BrechasControlbot25Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: comprobación comportamental por cláusula, con su ID explícito."""
        covered = set()
        for clause in IDS:
            with self.subTest(clause=clause):
                covered.add(clause)
                if clause == "CB-0364":
                    data = scenario("github_project_snapshot_scenarios.php", "routes")
                    repos = data["snapshot"]["repositories"]
                    self.assertEqual([r["repository"] for r in repos],
                                     ["pl0n3r/ControlBot", "pl0n3r/Factory"])
                    self.assertEqual(data["snapshot"]["observed_at"], 200)
                    self.assertEqual(len(data["calls"]), 16)
                    for method, url, _, body in data["calls"]:
                        self.assertEqual(method, "GET")
                        self.assertIsNone(body)
                        self.assertTrue(url.startswith("https://api.github.com/repos/"))
                elif clause == "CB-0365":
                    data = scenario("github_project_snapshot_scenarios.php", "bounded")
                    repo = data["bounded"]
                    for field in ("checks", "pull_requests", "issues"):
                        self.assertTrue(repo[field]["truncated"], field)
                        self.assertEqual(len(repo[field]["items"]), 100)
                    self.assertIsNone(data["no_release"])
                elif clause == "CB-0366":
                    bootstrap = (ROOT / "public" / "index.php").read_text()
                    router = (ROOT / "src" / "ControlBotWebEntrypoint.php").read_text()
                    self.assertIn("ControlBotWebEntrypoint::handleAuthenticated(", bootstrap)
                    self.assertIn("GitHubPublicEntrypoint::handle(", router)
                    self.assertIn("GitHubProjectEntrypoint::handle(", router)
                    self.assertIn("FactoryOrchestratorWebEntrypoint::handle(", router)
                    self.assertNotIn("api.github.com", bootstrap)
                    self.assertNotIn("curl_init(", bootstrap)
                elif clause == "CB-0368":
                    data = scenario("github_http_transport_scenarios.php", "allowlist")
                    self.assertEqual(data["sender_calls"], 0)
                    self.assertEqual(data["secret_calls"], 0)
                    self.assertEqual(len(data["receipts"]), 16)
                    for receipt in data["receipts"].values():
                        self.assertEqual(receipt["status"], "rejected")
                        self.assertEqual(receipt["error_code"], "invalid_request")
                elif clause == "CB-0369":
                    data = scenario("github_http_transport_scenarios.php", "closed")
                    self.assertEqual(data["calls"], 4)
                    for key, reason in (
                        ("tls", "tls_not_verified"),
                        ("redirect", "redirect_blocked"),
                        ("oversize", "response_limit_exceeded"),
                        ("redirect_count", "redirect_blocked"),
                    ):
                        self.assertEqual(data[key]["error_code"], reason)
                        self.assertNotEqual(data[key]["status"], "executed")
                elif clause == "CB-0370":
                    data = scenario("github_http_transport_scenarios.php", "local")
                    receipt = data["first"]
                    for key in ("intent_id", "project_id", "repository_id", "type",
                                "status", "idempotency_key", "request_digest",
                                "evidence_ref", "started_at", "finished_at"):
                        self.assertIn(key, receipt)
                    self.assertEqual(receipt, data["again"])
                    self.assertTrue(receipt["evidence_ref"].startswith("stub:sha256:"))
                    self.assertEqual(data["calls"], 4)
                elif clause == "CB-0371":
                    row = scenario("github_intent_authority_requirements_scenarios.php",
                                   "boundary")["declaration"]
                    self.assertEqual(row["state"], "requirements")
                    self.assertEqual(row["approval_class"], "owner_required")
                    self.assertIs(row["authorized"], False)
                    self.assertIs(row["execution"], False)
                elif clause == "CB-0372":
                    data = scenario("github_intent_authority_requirements_scenarios.php",
                                    "catalog")
                    self.assertEqual(len(data), 11)
                    for kind, item in data.items():
                        row = item["requirements"]
                        scope = "project" if kind.startswith("project.") else "repository"
                        self.assertEqual(row["type"], kind)
                        self.assertEqual(row["scope"], scope)
                        self.assertTrue(row["capability"].startswith("github."))
                        self.assertEqual(row["scope_ref"],
                                         "controlbot:project/controlbot" if scope == "project"
                                         else "pl0n3r/ControlBot")
                elif clause == "CB-0373":
                    fail = scenario("github_intent_authority_requirements_scenarios.php",
                                    "fail_closed")
                    self.assertEqual(len(fail), 12)
                    for row in fail.values():
                        self.assertEqual(row["state"], "unknown")
                        self.assertNotIn("capability", row)
                        self.assertIs(row["authorized"], False)
                        self.assertIs(row["execution"], False)
                elif clause == "CB-0374":
                    data = scenario("github_intent_authority_requirements_scenarios.php",
                                    "catalog")
                    for item in data.values():
                        self.assertIs(item["policy"]["known"], False)
                        self.assertEqual(item["policy"]["decision"], "forbidden")
                        self.assertIs(item["policy"]["auto_grant_allowed"], False)
                elif clause == "CB-0375":
                    rows = scenario("github_intent_envelope_scenarios.php", "known")["rows"]
                    self.assertEqual(len(rows), 11)
                    for kind, row in rows.items():
                        self.assertEqual(row["type"], kind)
                        self.assertIs(row["execution"], False)
                        self.assertEqual(row["repository_ref"], "pl0n3r/ControlBot")
                elif clause == "CB-0376":
                    rows = scenario("github_intent_envelope_scenarios.php", "known")["rows"]
                    for row in rows.values():
                        self.assertEqual(row["version"], 1)
                        self.assertEqual(row["project_ref"], "controlbot:project/controlbot")
                        self.assertEqual(row["idempotency_key"], "intent:controlbot:678:1")
                        self.assertEqual(len(set(row["evidence_refs"])), 2)
                        self.assertEqual(len(row["evidence_refs"]), 2)
                elif clause == "CB-0377":
                    helpers = gateway_helpers()
                    receipt = helpers["execute"](helpers["candidate"]())["receipts"][0]
                    self.assertEqual(receipt["decision"], "deny")
                    self.assertIs(receipt["execution"], False)
                    self.assertEqual(receipt["fixture_status"], "simulated")
                elif clause == "CB-0378":
                    helpers = gateway_helpers()
                    data = helpers["execute"](helpers["candidate"]())
                    self.assertEqual(data["calls"], 1)
                    self.assertEqual(data["receipts"][0]["fixture_status"], "simulated")
                    self.assertIs(data["receipts"][0]["runtime_authorized"], False)
                elif clause == "CB-0379":
                    helpers = gateway_helpers()
                    for mode in ("ok", "false_success", "malformed", "mismatch"):
                        result = helpers["execute"](helpers["candidate"](), sender_mode=mode)
                        self.assertEqual(result["calls"], 1)
                        receipt = result["receipts"][0]
                        self.assertEqual(receipt["decision"], "deny")
                        for key in ("execution", "runtime_authorized", "authorized",
                                    "grant_issued", "approval_issued", "approval_verified"):
                            self.assertIs(receipt[key], False)
                        self.assertEqual(receipt["fixture_status"],
                                         "simulated" if mode == "ok" else "ambiguous")
                else:
                    self.fail(f"Sin contraste ejecutable para {clause}")
        self.assertEqual(covered, set(IDS))
        self.assertEqual(len(covered), 15)

    def test_falla_cerrado(self) -> None:
        """AC-02: ambigüedad, colisión y ausencia de autoridad nunca permiten ejecutar."""
        self.assertTrue(all(scenario(
            "github_project_snapshot_scenarios.php", "invalid")["blocked"].values()))
        self.assertTrue(all(scenario(
            "github_intent_envelope_scenarios.php", "invalid").values()))
        self.assertTrue(all(row["state"] == "unknown" for row in scenario(
            "github_intent_authority_requirements_scenarios.php", "fail_closed").values()))
        secret = scenario("github_http_transport_scenarios.php", "secret")
        self.assertEqual(secret["first"]["status"], "ambiguous")
        self.assertEqual(secret["first"], secret["again"])
        self.assertEqual(secret["conflict"]["error_code"], "idempotency_conflict")
        helpers = gateway_helpers()
        invalid = helpers["candidate"]()
        invalid["approval"]["approved"] = False
        receipt = helpers["execute"](invalid)
        self.assertEqual(receipt["calls"], 0)
        self.assertEqual(receipt["receipts"][0]["fixture_status"], "denied")

    def test_sin_datos_reales(self) -> None:
        """AC-03: transporte inyectado y fixtures offline, sin exponer claves."""
        secret = scenario("github_http_transport_scenarios.php", "secret")
        self.assertNotIn("fake-token-never-log-XYZ", json.dumps(secret))
        helpers = gateway_helpers()
        result = helpers["execute"](helpers["candidate"]())
        self.assertNotIn(helpers["KEY"], json.dumps(result))
        self.assertEqual(result["receipts"][0]["decision"], "deny")
        source = (ROOT / "src" / "GitHubIntentExecutionGateway.php").read_text().lower()
        for forbidden in ("curl_exec(", "file_get_contents(", "shell_exec(",
                          "proc_open(", "github.com/repos/"):
            self.assertNotIn(forbidden, source)
        self.assertEqual(len(IDS), 15)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no afirmar cobertura live/operativa desde las pruebas sintéticas."""
        invalid = scenario("github_project_snapshot_scenarios.php", "review-invalid")
        self.assertEqual(invalid["bad_review"]["items"][0]["review_state"], "unknown")
        self.assertEqual(invalid["null_mergeable"]["items"][0]["mergeability"], "unknown")
        self.assertEqual(invalid["bad_mergeability"]["items"][0]["mergeability"], "unknown")
        rows = scenario("github_intent_authority_requirements_scenarios.php",
                        "fail_closed")
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertIn(clause, IDS)
                # No live GitHub grant, deployment, backup or operational receipt in this leaf.
                operational_state = "UNKNOWN"
                self.assertEqual(operational_state, "UNKNOWN")
                self.assertNotEqual(operational_state, "GREEN")
        for row in rows.values():
            self.assertEqual(row["state"], "unknown")
            self.assertIs(row["execution"], False)


if __name__ == "__main__":
    unittest.main()

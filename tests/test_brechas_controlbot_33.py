"""ControlBot #1040: quince cláusulas CB-0485..CB-0502 en escenarios offline.

Esto NO acredita GitHub workflow_dispatch live, recovery real, permisos,
credenciales, deploy, aplicación efectiva de pausa ni evidencia productiva.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import runpy
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
TESTS = ROOT / "tests"
IDS = (
    "CB-0485", "CB-0487", "CB-0488", "CB-0490", "CB-0491",
    "CB-0492", "CB-0493", "CB-0494", "CB-0495", "CB-0496",
    "CB-0497", "CB-0498", "CB-0500", "CB-0501", "CB-0502",
)


@lru_cache(maxsize=16)
def scenario(domain: str, case: str = "") -> dict:
    scripts = {
        "search": "global_search_scenarios.php",
        "recovery": "grindflow_recovery_rollout_scenarios.php",
        "infra": "guardrail_infrastructure_classification_scenarios.php",
        "guardrail": "guardrail_integration_scenarios.php",
    }
    args = ["php", str(TESTS / scripts[domain])]
    if case:
        args.append(case)
    run = subprocess.run(
        args, cwd=ROOT, text=True, capture_output=True, timeout=60, check=False,
    )
    if run.returncode or not run.stdout.strip():
        raise AssertionError(
            f"escenario offline {domain}/{case} falló: {run.stderr[:400]}"
        )
    data = json.loads(run.stdout)
    if not isinstance(data, dict):
        raise AssertionError("escenario PHP sin resultado tipado")
    return data


@lru_cache(maxsize=1)
def workflow_helpers() -> dict:
    """Reusar fixtures locales de #763, sin tocar runtime de GitHub."""
    return runpy.run_path(str(TESTS / "test_github_workflow_intent_executor.py"))


def workflow(payload=None) -> dict:
    helpers = workflow_helpers()
    return helpers["execute"](
        payload if payload is not None else helpers["candidate"]()
    )


class BrechasControlbot33Tests(unittest.TestCase):
    """Una aserción explícita y una frontera UNKNOWN por cada ID."""

    def test_contrato_principal(self) -> None:
        wf = workflow()
        numbered = scenario("search", "number")
        ranked = scenario("search", "stable")
        rows = scenario("search", "freshness")["items"]
        freshness = {row["repo"]: row["freshness"] for row in rows}
        recovery = scenario("recovery")
        profile = recovery["profile"]
        boundary = recovery["release_backup_boundary"]
        infra = scenario("infra")
        guard = scenario("guardrail")
        unknown_signals = {
            "backup_evidence", "offsite_copy", "immutable_copy",
            "restore_drill", "observed_rpo", "observed_rto",
        }

        checks = {
            "CB-0485": (
                wf["decision"] == "deny"
                and wf["execution"] is False
                and wf["runtime_authorized"] is False
                and wf["fake_effect"]["status"] == "simulated"
                and len(wf["fake_state"]["dispatches"]) == 1
            ),
            "CB-0487": (
                numbered["total"] == 1
                and len(numbered["items"]) == 1
                and numbered["items"][0]["number_or_id"] == 78
                and numbered["items"][0]["canonical_url"].endswith("/issues/78")
                and ranked["a"] == ranked["b"]
                and len({r["canonical_url"] for r in ranked["a"]["items"]})
                == len(ranked["a"]["items"])
            ),
            "CB-0488": (
                freshness["pl0n3r/ControlBot"] == "fresh"
                and freshness["pl0n3r/factory"] == "stale"
                and freshness["pl0n3r/FactoryRunner"] == "unavailable"
            ),
            "CB-0490": (
                profile["project_ref"] == "controlbot:project/project-grindflow"
                and recovery["execution"] is False
                and recovery["dr_status"] == "UNKNOWN"
                and recovery["restorable"] is False
            ),
            "CB-0491": (
                profile["targets"] == {"rpo_minutes": 15, "rto_minutes": 60}
                and profile["retention"] == {
                    "hourly": 24, "daily": 7, "weekly": 8, "monthly": 12,
                }
                and profile["restore_drill_cadence_hours"] == 168
                and profile["sources"] == {
                    "database": "required", "media": "required",
                    "repository": "required", "configuration": "required",
                }
                and profile["strategy"] == {
                    "copies_required": 3, "media_types_required": 2,
                    "offsite_required": True, "immutable_required": True,
                    "undetected_restore_failures_target": 0,
                }
                and profile["encryption_required"] is True
            ),
            "CB-0492": (
                profile["source_ref"]
                == "https://github.com/pl0n3r/ControlBot/issues/465"
                and recovery["signals"]["backup_evidence"] == "unknown"
                and recovery["restorable"] is False
            ),
            "CB-0493": (
                boundary["scope"] == "release_artifact_only"
                and boundary["canonical_database_recovery"] is False
                and boundary["canonical_media_recovery"] is False
            ),
            "CB-0494": (
                boundary["canonical_restore_drill"] is False
                and recovery["signals"]["restore_drill"] == "unknown"
            ),
            "CB-0495": (
                set(recovery["signals"]) == unknown_signals
                and set(recovery["signals"].values()) == {"unknown"}
                and recovery["dr_status"] == "UNKNOWN"
                and recovery["restorable"] is False
            ),
            "CB-0496": (
                recovery["profile_status"] == "configured"
                and recovery["dr_status"] == "UNKNOWN"
                and recovery["restorable"] is False
            ),
            "CB-0497": (
                recovery["execution"] is False
                and boundary["scope"] == "release_artifact_only"
                and profile["project_ref"].endswith("project-grindflow")
            ),
            "CB-0498": (
                recovery["execution"] is False
                and recovery["dr_status"] == "UNKNOWN"
                and all(x == "unknown" for x in recovery["signals"].values())
            ),
            "CB-0500": (
                infra["blocked"]["classification"] == "blocked_by_infrastructure"
                and infra["one_pre"]["classification"] == "unknown"
                and infra["duplicate_same_repo"]["classification"] == "unknown"
                and infra["public_failed"]["classification"] == "unknown"
                and infra["stale"]["classification"] == "unknown"
            ),
            "CB-0501": (
                infra["blocked"]["retry_intent"]["action"] == "suppress_retry"
                and infra["blocked"]["handoff"]["status"] == "waiting_dependency"
                and infra["blocked"]["queue_release_allowed"] is False
                and infra["deduped"]["retry_intent"]["deduplicated"] is True
                and infra["recovery"]["canary_work_item"]["state"] == "queued"
                and infra["recovery"]["queue_release_allowed"] is False
            ),
            "CB-0502": (
                guard["waiting"]["execution_status"] == "waiting_safe_point"
                and guard["waiting"]["pause_intent"]["action"] == "wait_safe_point"
                and guard["safe"]["pause_intent"]["action"] == "request_activation"
                and guard["safe"]["pause_intent"]["state"]["state"] == "unknown"
                and guard["safe"]["pause_intent"]["state"]["activated_at"] is None
                and guard["non_preemptible"]["pause_intent"] is None
            ),
        }
        self.assertEqual(set(checks), set(IDS), "cláusula silenciada")
        for clause, condition in checks.items():
            with self.subTest(clause=clause):
                self.assertTrue(condition, "no acreditado/UNKNOWN: " + clause)

    def test_falla_cerrado(self) -> None:
        helpers = workflow_helpers()
        bad = helpers["candidate"]()
        bad["intent"]["params"]["git_ref"] = "outside-allowlist"
        denied = workflow(bad)
        self.assertEqual(denied["decision"], "deny")
        self.assertIs(denied["execution"], False)
        self.assertEqual(denied["fake_effect"]["status"], "denied")
        self.assertEqual(denied["audit"], [])
        self.assertEqual(denied["fake_state"], bad["state"])
        infra = scenario("infra")
        for key in (
            "one_pre", "stale", "older_external", "duplicate_same_repo",
            "same_repository_different_issues", "conflict", "public_not_started",
            "public_failed", "public_unknown", "unresolved_external_agent",
        ):
            with self.subTest(infra=key):
                self.assertEqual(infra[key]["classification"], "unknown")
        for key in (
            "same_timestamp_recovery", "unrelated_recovery",
            "mismatched_active_recovery", "unchanged_fingerprint_recovery",
            "older_recovery", "old_recovery",
        ):
            with self.subTest(recovery=key):
                self.assertIsNone(infra[key]["canary_work_item"])
        guard = scenario("guardrail")
        self.assertIsNone(guard["waiting"]["checkpoint"])
        self.assertIsNone(guard["waiting"]["requeue_intent"])
        self.assertIsNone(guard["non_preemptible"]["pause_intent"])
        self.assertFalse(guard["partial"]["success"])
        self.assertTrue(guard["partial"]["recoverable"])
        self.assertTrue(guard["invalid_order"])
        self.assertFalse(scenario("recovery")["restorable"])

    def test_sin_datos_reales(self) -> None:
        """Solo fixtures sin llamadas a infraestructura o secretos externos."""
        wf = workflow()
        self.assertIs(wf["execution"], False)
        self.assertIs(wf["runtime_authorized"], False)
        self.assertNotIn("fixture_proof", wf)
        self.assertNotIn("key", wf)
        self.assertNotIn("token", wf)
        self.assertNotIn("password", json.dumps(wf).lower())
        search = scenario("search", "sanitize")
        rendered = (
            search["search"]["items"][0]["title"] + " "
            + search["search"]["items"][0]["snippet"]
        ).lower()
        for secret in (
            "alice@example.com", "hunter two words", "jsonabc",
            "bearer xyz", "300 123 4567",
        ):
            with self.subTest(secret=secret):
                self.assertNotIn(secret, rendered)
        self.assertTrue(search["network_rejected"])
        self.assertTrue(search["dot_rejected"])
        for name in (
            "grindflow_recovery_rollout_scenarios.php",
            "guardrail_infrastructure_classification_scenarios.php",
            "guardrail_integration_scenarios.php",
        ):
            source = (TESTS / name).read_text(encoding="utf-8").lower()
            self.assertNotRegex(
                source,
                r"\bcurl_exec\s*\(|\bshell_exec\s*\(|\bproc_open\s*\(",
            )
        self.assertIs(scenario("recovery")["execution"], False)
        self.assertIs(scenario("infra")["blocked"]["queue_release_allowed"], False)

    def test_estados_unknown_explicitos(self) -> None:
        """No promociona NO_VERIFICABLE a éxito ni evidencia de operación live."""
        paths = (
            ROOT / "docs/matriz-cobertura.md",
            ROOT / "docs/matriz-cobertura-parte-02.md",
        )
        rows = {clause: [] for clause in IDS}
        for path in paths:
            for line in path.read_text(encoding="utf-8").splitlines():
                match = re.match(r"\| (CB-\d{4}) \|", line)
                if match and match.group(1) in rows:
                    rows[match.group(1)].append(line)
        for clause, matches in rows.items():
            with self.subTest(clause=clause):
                self.assertEqual(len(matches), 1, "fila ausente o repetida")
                self.assertIn("| NO_VERIFICABLE |", matches[0])
                self.assertIn("offline=unknown", matches[0].lower())
                self.assertIn("live=unknown", matches[0].lower())
        recovery = scenario("recovery")
        self.assertEqual(recovery["dr_status"], "UNKNOWN")
        self.assertFalse(recovery["restorable"])
        safe = scenario("guardrail")["safe"]["pause_intent"]["state"]
        self.assertEqual(safe["state"], "unknown")
        self.assertIsNone(safe["activated_at"])


if __name__ == "__main__":
    unittest.main()

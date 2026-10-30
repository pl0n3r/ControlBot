#!/usr/bin/env python3
"""ControlBot #1250: 16 executable offline proofs for venture and focus contracts.

Run real PHP production entrypoints with checked-in synthetic scenario drivers.
No coverage-matrix reads, remote endpoints, real identities, credentials or live state.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
ROWS = frozenset(f"CB-{i:04d}" for i in range(1108, 1124))
SCRIPTS = {
    "source": ("venture_access_source_scenarios.php", frozenset({
        "request", "resolve", "mismatch", "composition", "readonly", "secret"
    })),
    "finance": ("venture_finance_scenarios.php", frozenset({
        "freshness", "provenance", "invalid"
    })),
    "decision": ("decision_runtime_scenarios.php", frozenset({
        "venture-materialize", "venture-replay", "venture-untrusted-recovery",
        "venture-response-lost", "venture-client-authority",
        "venture-no-writer", "venture-approve"
    })),
    "verified": ("verified_access_context_scenarios.php", frozenset({
        "structural", "server", "copy", "readonly", "secrets", "decisions"
    })),
    "focus": ("weekly_focus_ui_scenarios.php", frozenset({""})),
    "identity": ("venture_identity_scenarios.php", frozenset({"invalid-grants"})),
    "projection": ("infrastructure_action_projection_scenarios.php", frozenset({""})),
}


@lru_cache(maxsize=32)
def scenario(group: str, case: str = "") -> dict:
    """Fail closed on missing PHP, unexpected fixtures, errors or malformed JSON."""
    if group not in SCRIPTS:
        raise AssertionError("UNKNOWN: unsupported PHP scenario group")
    filename, cases = SCRIPTS[group]
    if case not in cases:
        raise AssertionError("UNKNOWN: unsupported PHP scenario")
    executable = shutil.which("php")
    if not executable:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    script = ROOT / "tests" / filename
    if not script.is_file():
        raise AssertionError("UNKNOWN: checked-in PHP driver absent")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    for key in (
        "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
    ):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="controlbot-1250-") as temp:
        env["HOME"] = temp
        run = subprocess.run(
            [
                executable, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
                "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,"
                "pfsockopen,stream_socket_client,shell_exec,system,exec,"
                "passthru,proc_open,popen",
                str(script), *([case] if case else []),
            ],
            cwd=ROOT, env=env, capture_output=True, text=True,
            timeout=60, check=False,
        )
    stderr = run.stderr.strip()
    # This checked-in driver emits one harmless PHP import warning. Do not
    # silence PHP warnings globally: exclude only this exact pre-existing
    # diagnostic and keep every other warning/error as a fail-closed result.
    known_prefix = (
        "PHP Warning:  The use statement with non-compound name "
        "'InvalidArgumentException' has no effect in "
    )
    known_suffix = "/tests/infrastructure_action_projection_scenarios.php on line 22"
    if (
        group == "projection"
        and stderr.startswith(known_prefix)
        and stderr.endswith(known_suffix)
        and chr(10) not in stderr
    ):
        stderr = ""
    if run.returncode or stderr:
        raise AssertionError(
            f"UNKNOWN: PHP {group}/{case} failed (exit={run.returncode}): "
            f"{stderr[-500:]}"
        )
    try:
        value = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP returned invalid JSON") from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: PHP returned non-object")
    return value


class CoberturaControlbot67Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: one distinct behavioral predicate for each of 16 matrix IDs."""
        source_request = scenario("source", "request")
        source_resolved = scenario("source", "resolve")
        source_mismatch = scenario("source", "mismatch")
        source_composition = scenario("source", "composition")
        source_readonly = scenario("source", "readonly")
        finance = scenario("finance", "freshness")
        provenance = scenario("finance", "provenance")
        material = scenario("decision", "venture-materialize")
        replay = scenario("decision", "venture-replay")
        untrusted = scenario("decision", "venture-untrusted-recovery")
        lost = scenario("decision", "venture-response-lost")
        client = scenario("decision", "venture-client-authority")
        no_writer = scenario("decision", "venture-no-writer")
        approval = scenario("decision", "venture-approve")
        structure = scenario("verified", "structural")
        server = scenario("verified", "server")
        copied = scenario("verified", "copy")
        readonly = scenario("verified", "readonly")
        secret = scenario("verified", "secrets")
        decisions = scenario("verified", "decisions")
        focus = scenario("focus")
        identity = scenario("identity", "invalid-grants")
        projection = scenario("projection")
        gate = material["materialized"][0]
        view = focus["view"]

        checks = {
            "CB-1108": (
                source_request["extra_blocked"] is True
                and source_request["calls"] == 0
                and client["extra_blocked"] is True
                and client["created_issues"] == 0
            ),
            "CB-1109": (
                set(source_mismatch) == {
                    "capability", "policy", "scope", "missing",
                    "ambiguous", "unconfigured"
                }
                and all(source_mismatch.values())
                and source_readonly["source_unchanged"] is True
                and source_readonly["has_runtime_state"] is False
            ),
            "CB-1110": (
                source_composition["request_keys"] == [
                    "identity_id", "scope", "capability"
                ]
                and source_composition["calls"] == 1
                and source_composition["grant_id"] == "grant-infra"
                and source_mismatch["unconfigured"] is True
            ),
            "CB-1111": (
                source_resolved["resolved"]["grant"]["grant_id"] == "grant-infra"
                and source_resolved["calls"] == 1
                and server["source_call"]["capability"] == "hostinger.read"
                and structure["malicious_source_cannot_mint"] is True
                and decisions["allow"]["decision"] == "allow"
                and projection["planned"]["authority_decision"] == "allow"
                and projection["planned"]["status"] == "planned"
            ),
            "CB-1112": (
                "no puede ser verified" in finance["stale_verified"]
                and "no puede ser verified" in finance["unknown_verified"]
                and finance["stale_unknown"]["confidence"] == "unknown"
                and finance["stale_unknown"]["freshness"] == "stale"
                and "source_ref inválido" in provenance["bad_source"]
                and "observed_at inválido" in provenance["bad_time"]
            ),
            "CB-1113": (
                client["repo_blocked"] is True
                and client["extra_blocked"] is True
                and client["created_issues"] == 0
                and no_writer["blocked"] is True
                and no_writer["created_issues"] == 0
                and no_writer["seen"] == []
            ),
            "CB-1114": (
                material["result"]["status"] == "owner_decision_required"
                and material["created_issues"] == 1
                and material["result"]["owner_decision"]["issue"] == gate["number"]
                and "factory-human-gate" in gate["labels"]
                and "factory-human-gate" in gate["body"]
                and "venture-access-materialization" in gate["body"]
                and "product-direction" in gate["body"]
                and any("labels=factory-human-gate" in request[1]
                        for request in material["seen"])
                and untrusted["result"]["owner_decision"]["issue"] != 199
            ),
            "CB-1115": (
                replay["created_issues"] == 1
                and replay["first"]["owner_decision"]["created"] is True
                and replay["second"]["owner_decision"]["created"] is False
                and replay["first"]["owner_decision"]["issue"]
                == replay["second"]["owner_decision"]["issue"]
                and lost["first_failed"] is True
                and lost["created_issues"] == 1
                and lost["second"]["owner_decision"]["created"] is False
                and lost["second"]["owner_decision"]["issue"]
                == lost["issues"][0]["number"]
            ),
            "CB-1116": (
                "Venture access escalation" in material["page"]["body"]
                and approval["approval"]["category"] == "product-direction"
                and approval["approval"]["option"] == "A"
                and approval["issue_state"] == "closed"
                and approval["created_issues"] == 1
                and len([request for request in approval["seen"]
                         if request[0] == "POST"
                         and request[1].endswith("/issues")]) == 1
            ),
            "CB-1117": (
                structure["constructor_private"] is True
                and structure["raw_factory_absent"] is True
                and structure["array_is_context"] is False
                and structure["object_is_context"] is False
                and copied["shape_is_context"] is False
                and copied["clone_blocked"] is True
                and copied["serialize_blocked"] is True
            ),
            "CB-1118": (
                structure["source_factory_absent"] is True
                and structure["malicious_source_cannot_mint"] is True
                and server["source_call"] == {
                    "identityId": "identity-admin",
                    "scope": "venture:alpha",
                    "capability": "hostinger.read", "now": 5000,
                }
                and server["summary"]["scope"] == "venture:alpha"
            ),
            "CB-1119": (
                all(source_mismatch.values())
                and secret["sensitive_blocked"] is True
                and secret["normal_allowed"] is True
                and decisions["deny"]["decision"] == "deny"
                and decisions["deny"]["reasons"] == ["capability_not_granted"]
                and all(identity["blocked"].values())
            ),
            "CB-1120": (
                readonly["unchanged"] is True
                and readonly["source_calls"] == 1
                and readonly["has_audit"] is False
                and readonly["has_top_level_state"] is False
                and source_readonly["has_runtime_state"] is False
            ),
            "CB-1121": (
                decisions["allow"]["decision"] == "allow"
                and decisions["owner"]["decision"] == "owner_decision_required"
                and decisions["deny"]["decision"] == "deny"
                and copied["clone_blocked"] is True
                and readonly["has_audit"] is False
                and projection["planned"]["status"] == "planned"
                and projection["planned"]["runner_request_ready"] is True
                and projection["planned"]["execution"] is False
                and projection["owner"]["status"] == "owner_decision_required"
                and projection["owner"]["runner_request_ready"] is False
                and projection["denied"]["status"] == "denied"
                and projection["denied"]["runner_request_ready"] is False
                and all(projection[key] for key in (
                    "fabricated_array_rejected", "fabricated_object_rejected",
                    "cross_capability_rejected", "fake_plan_rejected",
                    "caller_status_rejected"
                ))
            ),
            "CB-1122": (
                view["accessibility"] == {
                    "desktop_drag": True, "mobile_buttons": True,
                    "keyboard_buttons": True, "drag_only": False
                }
                and len(view["items"]) == 3
                and all(
                    item["actions"][direction]["keyboard_focusable"] is True
                    and item["actions"][direction]["focus_visible"] is True
                    and item["actions"][direction]["requires_drag"] is False
                    for item in view["items"]
                    for direction in ("move_up", "move_down")
                )
                and focus["desktop"]["save_request"]["ordered_refs"] !=
                focus["mobile"]["save_request"]["ordered_refs"]
            ),
            "CB-1123": (
                set(focus["desktop"]["save_request"]) == {
                    "ordered_refs", "expected_version"
                }
                and set(focus["mobile"]["save_request"]) == {
                    "ordered_refs", "expected_version"
                }
                and focus["desktop"]["save_request"]["expected_version"] == 3
                and focus["conflict"]["status"] == "conflict"
                and focus["conflict"]["current_version"] == 3
                and focus["conflict"]["save_request"] is None
                and focus["conflict"]["requires_refresh"] is True
                and focus["conflict"]["reconcile_action"] == "reload_latest_focus"
                and focus["conflict"]["overwrite_on_conflict"] is False
            ),
        }
        self.assertEqual(set(checks), ROWS)
        for row, outcome in sorted(checks.items()):
            with self.subTest(row=row):
                self.assertTrue(outcome, row)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing grants, forbidden authority, stale and ambiguous fail."""
        self.assertTrue(all(scenario("source", "mismatch").values()))
        self.assertTrue(scenario("source", "request")["extra_blocked"])
        self.assertTrue(scenario("source", "secret")["blocked"])
        self.assertTrue(scenario("decision", "venture-no-writer")["blocked"])
        client = scenario("decision", "venture-client-authority")
        self.assertTrue(client["repo_blocked"])
        self.assertTrue(client["extra_blocked"])
        self.assertEqual(client["created_issues"], 0)
        self.assertEqual(client["seen"], [])
        untrusted = scenario("decision", "venture-untrusted-recovery")
        self.assertEqual(untrusted["created_issues"], 1)
        self.assertNotEqual(untrusted["result"]["owner_decision"]["issue"], 199)
        invalid = scenario("finance", "invalid")
        for name in ("float_money", "negative_money", "bad_currency",
                     "bad_period", "extra"):
            with self.subTest(invalid=name):
                self.assertNotEqual(invalid[name], "accepted")
        self.assertEqual(scenario("focus")["conflict"]["status"], "conflict")
        self.assertIsNone(scenario("focus")["conflict"]["save_request"])
        self.assertEqual(scenario("verified", "decisions")["deny"]["decision"], "deny")
        self.assertTrue(all(scenario("identity", "invalid-grants")["blocked"].values()))
        projection = scenario("projection")
        self.assertTrue(projection["fabricated_array_rejected"])
        self.assertTrue(projection["fabricated_object_rejected"])
        self.assertTrue(projection["cross_capability_rejected"])
        self.assertTrue(projection["secret_rejected"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: source fixtures are synthetic; output contains no authority."""
        source = scenario("source", "composition")
        summary = scenario("verified", "server")["summary"]
        gate = scenario("decision", "venture-materialize")["materialized"][0]
        focus = scenario("focus")
        projection = scenario("projection")
        self.assertEqual(source["request_keys"],
                         ["identity_id", "scope", "capability"])
        self.assertEqual(summary["identity_id"], "identity-admin")
        self.assertEqual(summary["grant_id"], "grant-infra")
        self.assertEqual(gate["state"], "open")
        self.assertEqual(
            set(focus["desktop"]["save_request"]),
            {"ordered_refs", "expected_version"},
        )
        for value in (source, summary, gate, focus, projection):
            encoded = json.dumps(value).lower()
            for forbidden in (
                "fixture-client-value", "fixture-server-value",
                "github_pat_", "bearer ", "password=", "secret=",
                "raw_audio", "customer_email", "credit_card",
            ):
                with self.subTest(term=forbidden):
                    self.assertNotIn(forbidden, encoded)
        self.assertEqual(len(ROWS), 16)


if __name__ == "__main__":
    unittest.main()

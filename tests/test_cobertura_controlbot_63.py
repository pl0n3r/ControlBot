#!/usr/bin/env python3
"""#1246 — live PHP scheduler contracts exercised offline for CB-1035..1053.

Use the original PHP scenario fixtures and shipping classes. Never assert
coverage-matrix statuses or use remote accounts, secrets, network or real data.
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
SCRIPTS = {
    "commit": "scheduler_assignment_commit_scenarios.php",
    "plan": "scheduler_assignment_plan_scenarios.php",
    "policy": "scheduler_policy_guard_scenarios.php",
    "requeue": "scheduler_requeue_plan_scenarios.php",
    "selection": "scheduler_selection_scenarios.php",
}
CASES = {
    "commit": {"cas", "ownership", "binding", "atomic", "deterministic", "pure"},
    "plan": {"selection", "target", "binding", "ownership", "deterministic", "pure"},
    "policy": {"preemption", "focus", "blocked_focus", "drift", "deterministic"},
    "requeue": {"transition", "guard", "ownership", "requeue", "owner_guard", "deterministic"},
    "selection": {"request", "order", "drift", "authority", "telemetry", "pure"},
}


@lru_cache(maxsize=32)
def php(family: str, case: str) -> dict:
    """Invoke actual PHP implementation through checked-in synthetic fixtures."""
    if family not in SCRIPTS or case not in CASES[family]:
        raise AssertionError("UNKNOWN: unrecognized PHP scenario")
    executable = shutil.which("php")
    if not executable:
        raise AssertionError("UNKNOWN: PHP CLI required")
    script = ROOT / "tests" / SCRIPTS[family]
    if not script.is_file():
        raise AssertionError("UNKNOWN: original PHP scenario missing")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    for key in (
        "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
    ):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="controlbot-1246-") as home:
        env["HOME"] = home
        result = subprocess.run(
            [executable, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
             "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,shell_exec,system,exec,passthru,proc_open,popen",
             str(script), case],
            cwd=ROOT, env=env, text=True, capture_output=True,
            check=False, timeout=60,
        )
    # The existing policy scenario emits this harmless PHP 8.5 compile warning
    # for a redundant global-class import. Do not silence *other* warnings.
    known_warning = (
        "PHP Warning:  The use statement with non-compound name "
        "'InvalidArgumentException' has no effect in "
    )
    unexpected = [
        line for line in result.stderr.splitlines()
        if line.strip() and not (
            family == "policy"
            and line.startswith(known_warning)
            and line.endswith("/tests/scheduler_policy_guard_scenarios.php on line 4")
        )
    ]
    if result.returncode or unexpected:
        raise AssertionError(
            f"UNKNOWN: PHP {family}/{case} failed ({result.returncode}): "
            + "\n".join(unexpected)[-700:]
        )
    try:
        value = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise AssertionError("UNKNOWN: PHP returned invalid JSON") from error
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: PHP did not return an object")
    return value


class CoberturaControlbot63Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: independently exercise the 19 behavior rows with PHP calls."""
        cc, ca, cb = (php("commit", x) for x in ("cas", "atomic", "binding"))
        ps, pt, pb, pd = (php("plan", x) for x in
                          ("selection", "target", "binding", "deterministic"))
        pre = php("policy", "preemption")
        focus, blocked = php("policy", "focus"), php("policy", "blocked_focus")
        trans, rq, owner = (php("requeue", x) for x in
                            ("transition", "requeue", "owner_guard"))
        sr, sa, st = (php("selection", x) for x in
                      ("request", "authority", "telemetry"))
        writes = cb["writes"]
        checks = {
            "CB-1035": (
                set(cb) == {"version", "policy_ref", "expected", "writes",
                            "plan_fingerprint", "commit_fingerprint"}
                and set(writes) == {"reservation", "work_item", "session", "assignment"}
                and len(cb["expected"]["cas_sha256"]) == 64
            ),
            "CB-1036": (
                cc["fabricated"] is True
                and ps["rejected"]["fabricated_array"] is True
                and cb["policy_ref"] == "factory-dispatcher-v2"
            ),
            "CB-1037": all(cc[k] is True for k in
                ("work_drift", "session_drift", "agent_drift", "clock_stale")),
            "CB-1038": (
                ca["write_keys"] == ["reservation", "work_item", "session", "assignment"]
                and ca["has_partial"] is False
                and ca["top_keys"] == ["version", "policy_ref", "expected", "writes",
                                       "plan_fingerprint", "commit_fingerprint"]
            ),
            "CB-1039": (
                all(php("commit", "ownership").values())
                and cc["fabricated"] and cc["agent_drift"] and cc["session_drift"]
            ),
            "CB-1040": (
                ps["valid"]["work_item"]["work_item_id"] == "work-a"
                and all(ps["rejected"][k] is True for k in
                    ("key", "source_ref", "priority", "generation",
                     "required_capabilities", "account_id", "fabricated_array"))
            ),
            "CB-1041": (
                pb["reservation"]["reservation_id"] == "reservation-0001"
                and pb["assignment"]["assignment_id"] == "assignment-0001"
                and pd["same"] and pd["fingerprint_same"]
                and php("plan", "pure")["hits"] == []
            ),
            "CB-1042": (
                len(pb["preconditions"]["cas_sha256"]) == 64
                and pd["work_cas_changed"] and pd["session_cas_changed"]
                and cc["work_drift"] and cc["session_drift"]
            ),
            "CB-1043": (
                pt["valid"]["session"]["status"] == "assigned"
                and all(pt[k] is True for k in
                    ("stale", "busy", "account", "agent_identity", "capability"))
            ),
            "CB-1044": (
                pre["safe"]["allowed"] is True
                and pre["safe"]["selected_work_item_id"] == "work-incident"
                and pre["safe"]["running_work_item_id"] == "work-running"
                and pre["blocked"]["allowed"] is False
                and "non_preemptible_outside_safe_point" in pre["blocked"]["reasons"]
                and pre["stale"]["allowed"] is False
                and pre["higher"]["allowed"] is False
                and "running_priority_not_lower" in pre["higher"]["reasons"]
            ),
            "CB-1045": (
                focus["critical_rejected"] and focus["incident_rejected"]
                and focus["protected_selected"]["selected_key"] == "work-critical"
                and focus["protected_selected"]["focus"]["influenced"]
                and blocked["ready_ids"] == ["work-high"]
                and blocked["excluded_ids"] == ["work-critical"]
            ),
            "CB-1046": (
                trans["valid"]["version"] == 1
                and php("requeue", "deterministic")["hits"] == []
                and "applied" not in rq and "execution" not in rq
            ),
            "CB-1047": (
                trans["valid"]["version"] == 1
                and all(trans[k] is True for k in ("no_change", "leave", "recovery"))
            ),
            "CB-1048": (
                rq["release_reservation"]["active"] is False
                and rq["release_reservation"]["generation"] == 4
                and rq["work_item"]["state"] == "queued"
                and rq["work_item"]["reservation_id"] is None
                and rq["work_item"]["assigned_session_id"] is None
                and (rq["work_item"]["generation"], rq["work_item"]["attempt"]) == (5, 3)
                and (rq["fence"]["previous_generation"], rq["fence"]["next_generation"]) == (4, 5)
                and (rq["fence"]["previous_attempt"], rq["fence"]["next_attempt"]) == (2, 3)
                and rq["handoff"]["to_session_id"] is None
                and len(rq["fingerprint"]) == 64
            ),
            "CB-1049": (
                owner["before"]["allowed"] is True
                and owner["old_after"]["allowed"] is False
                and "stale_generation" in owner["old_after"]["reasons"]
                and "work_unowned" in owner["old_after"]["reasons"]
                and owner["new_generation_unowned"]["allowed"] is False
            ),
            "CB-1050": (
                sa["validated"]["selected"]["generation"] == sa["candidate"]["generation"]
                and sa["validated"]["selected"]["required_capabilities"] ==
                    sa["candidate"]["required_capabilities"]
                and "authority" not in sa["validated"]["selected"]
                and sa["generation_rejected"] and sa["capabilities_rejected"]
                and sa["authority_rejected"]
            ),
            "CB-1051": (
                sr["valid"]["policy_ref"] == "factory-dispatcher-v2"
                and sr["wrong_policy"] is True
                and php("selection", "drift")["wrong_policy"] is True
            ),
            "CB-1052": (
                set(st["validated"]["telemetry"]) == {"ready_not_selected", "excluded"}
                and st["validated"]["telemetry"]["ready_not_selected"] == ["work-b"]
                and set(st["validated"]["telemetry"]["excluded"]) == {"work-c"}
                and st["fabricated"] is True
            ),
            "CB-1053": (
                php("selection", "pure")["pure"] is True
                and php("selection", "pure")["hits"] == []
                and php("selection", "order")["same"] is True
            ),
        }
        self.assertEqual(set(checks), {f"CB-{n:04d}" for n in range(1035, 1054)})
        for row, passed in sorted(checks.items()):
            with self.subTest(row=row):
                self.assertTrue(passed, f"{row} failed in shipping PHP behavior")

    def test_falla_cerrado(self) -> None:
        """AC-02: negative fixtures reject missing, stale and unauthorized inputs."""
        cases = {
            "commit_fabricated": php("commit", "cas")["fabricated"],
            "commit_expired": php("commit", "cas")["clock_stale"],
            "plan_reserved": php("plan", "ownership")["reserved"],
            "plan_assigned": php("plan", "ownership")["assigned"],
            "plan_occupied": php("plan", "ownership")["occupied"],
            "plan_stale": php("plan", "ownership")["stale_generation"],
            **{"requeue_" + k: v for k, v in php("requeue", "ownership").items()},
            **{"selection_" + k: php("selection", "drift")[k] for k in (
                "wrong_fingerprint", "unknown_key", "blocked_key", "readiness_drift"
            )},
            **{"policy_" + k: v for k, v in php("policy", "drift").items()},
            "selection_duplicate": php("selection", "request")["duplicate"],
            "selection_extra": php("selection", "request")["extra"],
            "requeue_unsafe": php("requeue", "guard")["unsafe_preemption"],
        }
        for name, rejected in sorted(cases.items()):
            with self.subTest(case=name):
                self.assertIs(rejected, True)

    def test_sin_datos_reales(self) -> None:
        """AC-03: scenarios run without network or secrets and reject unsafe data."""
        for family, case in (
            ("commit", "pure"), ("plan", "pure"),
            ("policy", "deterministic"), ("requeue", "deterministic"),
            ("selection", "pure"),
        ):
            data = php(family, case)
            with self.subTest(family=family):
                self.assertEqual(data["hits"], [])
                if "pure" in data:
                    self.assertIs(data["pure"], True)
        self.assertIs(php("selection", "request")["secret_account"], True)
        self.assertIs(php("selection", "telemetry")["secret"], True)
        self.assertIs(php("selection", "telemetry")["pii"], True)
        output = json.dumps(php("selection", "telemetry")["validated"])
        self.assertNotIn("@", output)
        self.assertNotIn("token", output.lower())


if __name__ == "__main__":
    unittest.main()

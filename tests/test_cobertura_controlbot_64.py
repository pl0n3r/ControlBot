#!/usr/bin/env python3
"""#1247: 17 contracts exercised by actual SecurityEvent PHP implementations.

The input scenarios are deterministic checkout fixtures. CB-1066/CB-1072 are
documented architectural boundaries (C), not fabricated executable coverage.
No coverage matrix status, live source, credential, provider or network access.
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
SCENARIOS = {
    "alert": "security_alert_scenarios.php",
    "retention": "security_event_retention_scenarios.php",
    "inbox": "security_event_ui_scenarios.php",
    "event": "security_event_scenarios.php",
}
EXECUTABLE = frozenset(f"CB-{n:04d}" for n in (
    1054, 1055, 1056, 1057, 1058, 1059, 1060, 1061, 1062,
    1063, 1064, 1065, 1067, 1068, 1069, 1070, 1071,
))
PROBE = r"""
require 'src/SecurityEvent.php';
require 'src/SecurityAlertChannel.php';
use ControlBot\Security\SecurityEvent;
use ControlBot\Security\SecurityAlertChannel;
function denied(callable $f): bool {
 try { $f(); return false; } catch (InvalidArgumentException) { return true; }
}
$raw=[
 'version'=>1, 'provider'=>'fixture', 'event_id'=>'synthetic-001',
 'observed_at'=>2000, 'occurred_at'=>1900, 'event_type'=>'new_login',
 'severity'=>'critical', 'account_scope'=>'controlbot:account/fixture',
 'confidence'=>'unknown', 'device'=>null, 'actor'=>null
];
$event=SecurityEvent::normalize($raw);
$channels=['primary_state'=>'healthy','independent_state'=>'unknown'];
$policy=['version'=>1,'escalate_noncritical'=>false];
$states=[];
foreach(['healthy','degraded','down','stale','unavailable','unknown'] as $state) {
 $copy=$channels; $copy['primary_state']=$state;
 $states[$state]=SecurityAlertChannel::project($event,$copy,$policy)['decision'];
}
$badState=$channels;$badState['primary_state']='online';
$badIndependent=$channels;$badIndependent['independent_state']='ready';
$missing=$channels;unset($missing['independent_state']);
$badPolicy=$policy;$badPolicy['escalate_noncritical']='yes';
$forged=$event;$forged['fingerprint']='forged';
$extra=$raw;$extra['freeform']='synthetic';
$absent=$raw;unset($absent['provider']);
echo json_encode([
 'states'=>$states,
 'bad_primary'=>denied(fn()=>SecurityAlertChannel::project($event,$badState,$policy)),
 'bad_independent'=>denied(fn()=>SecurityAlertChannel::project($event,$badIndependent,$policy)),
 'missing_independent'=>denied(fn()=>SecurityAlertChannel::project($event,$missing,$policy)),
 'bad_policy'=>denied(fn()=>SecurityAlertChannel::project($event,$channels,$badPolicy)),
 'forged_event'=>denied(fn()=>SecurityAlertChannel::project($forged,$channels,$policy)),
 'extra_event'=>denied(fn()=>SecurityEvent::normalize($extra)),
 'missing_event'=>denied(fn()=>SecurityEvent::normalize($absent))
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
"""


def local_php(args: list[str]) -> dict:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI required to exercise real code")
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    # Preserve local coverage instrumentation only; never pass app secrets.
    for key in (
        "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
    ):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="controlbot-1247-") as home:
        env["HOME"] = home
        p = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0", *args],
            cwd=ROOT, env=env, capture_output=True, text=True,
            check=False, timeout=60,
        )
    if p.returncode:
        raise AssertionError(
            "UNKNOWN: local PHP failed (exit=" + str(p.returncode)
            + "): " + p.stderr[-700:]
        )
    try:
        data = json.loads(p.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: local PHP output is not JSON") from exc
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: local PHP output must be object")
    return data


@lru_cache(maxsize=4)
def scenario(which: str) -> dict:
    if which not in SCENARIOS:
        raise AssertionError("Unsupported PHP scenario")
    p = ROOT / "tests" / SCENARIOS[which]
    if not p.is_file():
        raise AssertionError("UNKNOWN: checked-in PHP scenario absent")
    return local_php([str(p)])


@lru_cache(maxsize=1)
def probe() -> dict:
    return local_php(["-r", PROBE])


class CoberturaControlbot64Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each row checks a concrete real PHP behavior."""
        a, r, u, e, x = (
            scenario("alert"), scenario("retention"), scenario("inbox"),
            scenario("event"), probe(),
        )
        p, independent = a["warning_primary"], a["critical_independent"]
        alert_payload = independent["payload"]
        expired, held = r["expired"], r["held"]
        minimum = expired["retain_minimum"]
        cards = u["inbox"]["cards"]
        original, controlbot = e["new_device"], e["controlbot"]
        event_fields = {
            "version", "provider", "event_id", "observed_at", "occurred_at",
            "event_type", "severity", "account_scope", "confidence", "device",
            "actor", "event_identity", "fingerprint",
        }
        assertions = {
            "CB-1054": (
                set(x["states"]) ==
                {"healthy", "degraded", "down", "stale", "unavailable", "unknown"}
                and x["states"]["healthy"] == "primary_channel"
                and x["states"]["unknown"] == "owner_review"
                and x["bad_primary"] and x["bad_independent"]
            ),
            "CB-1055": (
                p["decision"] == "primary_channel" and p["channel"] == "primary"
                and p["severity"] == "warning"
                and a["primary_healthy"]["decision"] == "primary_channel"
            ),
            "CB-1056": (
                independent["decision"] == "independent_channel"
                and independent["channel"] == "independent"
                and a["idempotent_a"]["decision"] == "independent_channel"
                and a["unknown_confidence"]["decision"] == "independent_channel"
                and a["unknown_independent"]["decision"] == "owner_review"
                and a["warning_no_alert"]["decision"] == "no_alert"
                and a["warning_escalated"]["decision"] == "independent_channel"
            ),
            "CB-1057": (
                a["unknown_primary"]["decision"] == "owner_review"
                and a["unknown_independent"]["decision"] == "owner_review"
                and a["warning_no_alert"]["payload"] is None
                and a["unknown_confidence"]["confidence"] == "unknown"
            ),
            "CB-1058": (
                set(alert_payload) == {
                    "event_identity", "event_type", "severity", "confidence",
                    "timestamp", "context",
                }
                and set(alert_payload["context"]) == {"account_scope"}
                and alert_payload["timestamp"] == 1900
                and alert_payload["context"]["account_scope"].startswith("controlbot:")
            ),
            "CB-1059": (
                expired == r["replay"] and expired["version"] == 1
                and expired["expired"] is True
                and expired["purge_plan_id"].startswith("security-retention:")
            ),
            "CB-1060": (
                set(minimum) == {
                    "event_identity", "fingerprint", "event_type", "severity",
                    "confidence", "observed_at", "occurred_at", "account_scope",
                    "acknowledge",
                }
                and minimum["account_scope"].startswith("controlbot:")
                and "provider" not in minimum
                and "device" not in minimum and "actor" not in minimum
            ),
            "CB-1061": (
                held["legal_hold"] is True
                and held["retain_minimum"] == minimum
                and all(held["classifications"][key] == "purge" for key in (
                    "raw_payload", "secrets", "ip", "precise_location", "credentials"
                ))
                and held["retained_artifact_references"] == expired["retained_artifact_references"]
            ),
            "CB-1062": (
                r["wrong_ack_rejected"] is True
                and r["pre_event_ack_rejected"] is True
                and minimum["acknowledge"]["state"] == "acknowledged"
                and minimum["acknowledge"]["observed_at"] == 120
                and set(minimum["acknowledge"]) ==
                {"state", "event_identity", "observed_at"}
            ),
            "CB-1063": (
                expired["policy_version"] == r["same_version_changed"]["policy_version"]
                and expired["policy_fingerprint"] != r["same_version_changed"]["policy_fingerprint"]
                and expired["purge_plan_id"] != r["same_version_changed"]["purge_plan_id"]
                and r["changed"]["policy_version"] == 2
                and expired["purge_plan_id"] != r["changed"]["purge_plan_id"]
            ),
            "CB-1064": (
                [c["severity"] for c in cards] ==
                ["critical", "warning", "warning", "info"]
                and cards[1]["observed_at"] < cards[2]["observed_at"]
                and cards[-1]["state"] == "superseded"
            ),
            "CB-1065": (
                u["ack"] == u["ack_replay"]
                and u["ack"]["provider"] == "controlbot"
                and u["ack"]["event_type"] == "security_alert_acknowledged"
                and u["ack"]["event_identity"].startswith("security-event:provider:")
                and u["ack"]["severity"] == u["critical"]["severity"]
                and u["ack"]["confidence"] == u["critical"]["confidence"]
            ),
            "CB-1067": (
                set(original) == event_fields
                and e["reject_extra_field"] is True
                and x["missing_event"] is True
            ),
            "CB-1068": (
                original["severity"] == "warning"
                and original["confidence"] == "provider_reported"
                and e["incomplete_unknown"]["confidence"] == "unknown"
                and e["reject_confirmed_incomplete"] is True
            ),
            "CB-1069": (
                original["event_identity"] == e["repeat"]["event_identity"]
                and original["event_identity"] == e["reordered"]["event_identity"]
                and original["fingerprint"] == e["repeat"]["fingerprint"]
                and e["fallback_a"]["event_identity"] == e["fallback_b"]["event_identity"]
                and original["event_id"] not in original["event_identity"]
            ),
            "CB-1070": (
                set(original["device"]) == {"type", "platform", "browser", "location"}
                and set(original["device"]["location"]) == {"country", "region", "city"}
                and e["reject_precise_location"] is True
            ),
            "CB-1071": (
                controlbot["provider"] == "controlbot"
                and set(controlbot["actor"]) == {"actor_ref", "context_ref"}
                and controlbot["actor"]["actor_ref"].startswith("controlbot:actor/")
                and controlbot["actor"]["context_ref"].startswith("controlbot:context/")
                and e["reject_missing_actor"] is True
                and e["reject_secret"] is True
            ),
        }
        self.assertEqual(set(assertions), EXECUTABLE)
        for row, verdict in assertions.items():
            with self.subTest(row=row):
                self.assertTrue(verdict, "Existing PHP contract failed " + row)

    def test_falla_cerrado(self) -> None:
        """AC-02: deny missing, forged, ambiguous or untrusted input."""
        x = probe()
        for name in (
            "bad_primary", "bad_independent", "missing_independent",
            "bad_policy", "forged_event", "extra_event", "missing_event",
        ):
            with self.subTest(input=name):
                self.assertIs(x[name], True)
        e = scenario("event")
        for name in (
            "reject_confirmed_incomplete", "reject_extra_field",
            "reject_precise_location", "reject_secret", "reject_missing_actor",
        ):
            with self.subTest(input=name):
                self.assertIs(e[name], True)
        r, a = scenario("retention"), scenario("alert")
        self.assertIs(r["wrong_ack_rejected"], True)
        self.assertIs(r["pre_event_ack_rejected"], True)
        self.assertEqual(a["unknown_primary"]["decision"], "owner_review")
        self.assertEqual(a["unknown_independent"]["decision"], "owner_review")
        self.assertEqual(a["warning_no_alert"]["decision"], "no_alert")
        self.assertIsNone(a["warning_no_alert"]["payload"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: only local fixtures; all domain classes run without I/O."""
        for name in SCENARIOS:
            self.assertTrue(scenario(name))
        payload = scenario("alert")["critical_independent"]["payload"]
        for field in ("event_id", "provider", "actor", "device", "fingerprint"):
            self.assertNotIn(field, payload)
        self.assertNotIn("device", scenario("retention")["expired"]["retain_minimum"])
        self.assertNotIn("actor", scenario("retention")["held"]["retain_minimum"])
        self.assertTrue(probe()["bad_primary"])


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""#1234: executable behavior for local scheduler and Owner Briefing Delivery.

The original Python functions are compiled from the checked-in orq.py AST, not
reimplemented as stand-ins. No orq.py module startup, HTTP server, gh CLI,
external provider, production identity, local state write or coverage-matrix read.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import threading
import time
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
ORQ = ROOT / "tools" / "orquestador-local" / "orq.py"
FIXTURE = ROOT / "tests" / "owner_briefing_delivery_scenarios.php"

# Non-executable rows must not be disguised as PHP / planner successes.
# The reasons are explained individually in the PR and issue, not asserted here.
EXECUTABLE = frozenset({
    "CB-0836", "CB-0838", "CB-0839", "CB-0840",
    "CB-0842", "CB-0843", "CB-0844",
    "CB-0850", "CB-0851", "CB-0852", "CB-0853", "CB-0854",
})
NON_EXECUTABLE = frozenset({
    "CB-0835", "CB-0837", "CB-0841", "CB-0845",
    "CB-0846", "CB-0847", "CB-0848", "CB-0849",
})
NAMES = {
    "chat_items", "issue_waiting", "unblock_candidates",
    "unblock_tries_recent", "latest_checks", "get_data", "parse_gate",
}
PHP_PROBE = r"""
require 'src/OwnerBriefing.php';
require 'src/OwnerBriefingDelivery.php';
use ControlBot\Briefing\OwnerBriefingDelivery;
$snapshot=[
 'delivered'=>[], 'today'=>[
  ['summary'=>'Synthetic planning event','evidence'=>'controlbot:issue/108']],
 'broken'=>[], 'decisions'=>[], 'costs'=>[],
];
$tick=[
 'version'=>1,'source'=>'scheduler',
 'schedule_ref'=>'controlbot:schedule/owner-briefing-daily',
 'day'=>'2026-09-30','freshness'=>'fresh',
];
$policy=[
 'version'=>1,'policy_ref'=>'controlbot:policy/owner-briefing-v1',
 'enabled'=>true,'channel'=>'email','channel_available'=>true,
];
$first=OwnerBriefingDelivery::plan($snapshot,$tick,$policy);
$receipt=[
 'version'=>1,'delivery_ref'=>$first['delivery_ref'],
 'day'=>$first['day'],'briefing_fingerprint'=>$first['briefing_fingerprint'],
 'status'=>'delivered',
];
$cases=[];
$unknownTick=$tick; $unknownTick['freshness']='unknown';
$cases['unknown_tick']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$unknownTick,$policy);
$badTick=$tick; $badTick['source']='browser';
$cases['untrusted_tick']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$badTick,$policy);
$missingTick=$tick; unset($missingTick['schedule_ref']);
$cases['missing_tick']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$missingTick,$policy);
$badDay=$tick; $badDay['day']='2026-02-30';
$cases['invalid_day']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$badDay,$policy);
$invalidPolicy=$policy; $invalidPolicy['enabled']='yes';
$cases['invalid_policy']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$tick,$invalidPolicy);
$unknownChannel=$policy; $unknownChannel['channel']='chat';
$cases['unapproved_channel']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$tick,$unknownChannel);
$unavailable=$policy; $unavailable['channel_available']=false;
$cases['unavailable']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$tick,$unavailable);
$forgedReceipt=$receipt; $forgedReceipt['delivery_ref']='controlbot:briefing-delivery/forged';
$cases['forged_receipt']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$tick,$policy,$forgedReceipt);
$badReceipt=$receipt; $badReceipt['status']='pending';
$cases['unconfirmed_receipt']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$tick,$policy,$badReceipt);
$badRefPolicy=$policy; $badRefPolicy['policy_ref']='controlbot:token/private';
$cases['unsafe_ref']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$tick,$badRefPolicy);
$extraTick=$tick; $extraTick['identity_id']='owner';
$cases['extra_claim']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$extraTick,$policy);
$cases['valid_receipt']=static fn()=>OwnerBriefingDelivery::plan($snapshot,$tick,$policy,$receipt);
$out=['initial'=>$first];
foreach($cases as $name=>$call) {
 try {
  $r=$call();
  $out[$name]=[
   'decision'=>$r['decision'], 'status'=>$r['delivery_status'],
   'reasons'=>$r['reasons'], 'intent'=>$r['delivery_intent'],
  ];
 } catch (InvalidArgumentException $e) {
  $out[$name]=['invalid'=>true];
 }
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
"""


@lru_cache(maxsize=1)
def local_scheduler() -> dict:
    """Execute the exact checked-in scheduler function bodies without startup."""
    if not ORQ.is_file():
        raise AssertionError("scheduler source is unavailable")
    module = ast.parse(ORQ.read_text(encoding="utf-8"))
    functions = [
        node for node in module.body
        if isinstance(node, ast.FunctionDef) and node.name in NAMES
    ]
    if {f.name for f in functions} != NAMES:
        raise AssertionError("scheduler API missing")
    names = {
        "re": re, "json": json, "time": time, "threading": threading,
        "CACHE_SECONDS": 300,
        "_lock": threading.Lock(), "_fresh_collection": threading.Event(),
        "_cache": {"at": 0, "data": None, "error": None},
        "UNBLOCK_TRIES": {}, "agent_blocked": lambda ref: False,
    }
    compiled = ast.Module(body=functions, type_ignores=[])
    exec(compile(compiled, str(ORQ), "exec"), names)
    return names


def local_php(arguments: list[str]) -> dict:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("PHP CLI is required for executable coverage")
    # Preserve only PHP coverage instrumentation, never application credentials.
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    for name in (
        "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
    ):
        if name in os.environ:
            env[name] = os.environ[name]
    with tempfile.TemporaryDirectory(prefix="controlbot-1234-") as home:
        env["HOME"] = home
        result = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0", *arguments],
            cwd=ROOT, env=env, text=True, capture_output=True,
            timeout=60, check=False,
        )
    # The checked-in fixture has an inert PHP 8.5 warning: it imports the
    # already-global InvalidArgumentException symbol. It does not change data.
    # Do not swallow unexpected warnings, errors or nonzero exit codes.
    harmless = (
        "PHP Warning:  The use statement with non-compound name "
        "'InvalidArgumentException' has no effect in "
    )
    errors = [
        line for line in result.stderr.splitlines()
        if line.strip() and not (
            line.startswith(harmless)
            and line.endswith("owner_briefing_delivery_scenarios.php on line 8")
        )
    ]
    if result.returncode or errors:
        raise AssertionError("Local PHP execution failed: " + "\\n".join(errors)[-750:])
    try:
        value = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("PHP behavior returned malformed JSON") from exc
    if not isinstance(value, dict):
        raise AssertionError("PHP behavior returned non-object")
    return value


@lru_cache(maxsize=1)
def briefing() -> dict:
    if not FIXTURE.is_file():
        raise AssertionError("OwnerBriefingDelivery local fixture is missing")
    return local_php([str(FIXTURE)])


@lru_cache(maxsize=1)
def briefing_negatives() -> dict:
    return local_php(["-r", PHP_PROBE])


class CoberturaControlbot51Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: assertions call the original Python/PHP functions, no matrix."""
        sched = local_scheduler()
        current = {
            "issue": "ControlBot#1234", "at": 100, "n": 2, "fp": "synthetic",
            "parked": [
                {"issue": "ControlBot#1233", "at": 90, "n": 1},
                {"issue": "ControlBot#1232", "at": 80, "n": 1},
            ],
        }
        items = sched["chat_items"](current)
        waiting = sched["issue_waiting"](
            {"work": [{"n": 1234, "pr": {"pend": 2}}]},
            1234, "trabajando", True,
        )
        sched["UNBLOCK_TRIES"] = {"ControlBot#22": [1900, 1980]}
        with mock.patch.object(sched["time"], "time", return_value=2000):
            allowed = sched["unblock_candidates"](
            {"ControlBot": {
                "open_numbers": [20, 21, 22],
                "blocked": [
                    {"n": 20, "cause": "needs CI repair", "h": 5},
                    {"n": 21, "cause": "roadmap", "h": 4},
                    {"n": 22, "cause": "needs CI repair", "h": 3},
                ],
            }}, set(),
            )
            recent = sched["unblock_tries_recent"]("ControlBot#22", now=2000)
        # Reruns override older failures only inside the same check context.
        checks = sched["latest_checks"]([
            {"name": "Validar", "startedAt": "2026-10-29T10:00:00Z",
             "conclusion": "failure", "head_sha": "a"},
            {"name": "Validar", "startedAt": "2026-10-29T10:10:00Z",
             "conclusion": "success", "head_sha": "a"},
            {"name": "CodeQL", "startedAt": "2026-10-29T10:03:00Z",
             "conclusion": "failure", "head_sha": "a"},
        ])
        sched["_cache"] = {"at": 0, "data": {"synthetic": True}, "error": None}
        sched["collect"] = lambda: (_ for _ in ()).throw(RuntimeError("offline"))
        cache, cache_error = sched["get_data"](force=False)
        degraded, degraded_error = sched["get_data"](force=True)
        gate = {
            "title_simple": "Synthetic approval", "safe_default": "B",
            "options": [{"id": "A", "label": "Approve", "effect": "review"},
                        {"id": "B", "label": "Deny", "effect": "no change"}],
        }
        parsed = sched["parse_gate"](
            "<!-- factory-human-gate " + json.dumps(gate) + " -->"
        )
        delivery = briefing()
        probe = briefing_negatives()
        first, retry, changed = (delivery[x] for x in ("first", "retry", "changed"))
        checks_by_name = {x["name"]: x for x in checks}
        predicates = {
            "CB-0836": len(items) == 3 and items[0]["issue"] == "ControlBot#1234"
                       and items[1]["issue"] == "ControlBot#1233" and waiting,
            "CB-0838": allowed == ["ControlBot#20"] and len(recent) == 2,
            "CB-0839": len(checks) == 2
                       and checks_by_name["Validar"]["conclusion"] == "success",
            "CB-0840": checks_by_name["CodeQL"]["conclusion"] == "failure"
                       and checks_by_name["Validar"]["head_sha"] == "a",
            "CB-0842": cache == {"synthetic": True} and cache_error is None,
            "CB-0843": degraded == cache and degraded_error == "offline",
            "CB-0844": parsed is not None
                       and [x["id"] for x in parsed["options"]] == ["A", "B"]
                       and parsed["safe_default"] == "B",
            "CB-0850": first["decision"] == "deliver"
                       and first["schedule_ref"].startswith("controlbot:schedule/")
                       and first["briefing"]["sections"]["today"]["status"] == "real",
            "CB-0851": first["delivery_ref"] == retry["delivery_ref"]
                       and first["dedupe_key"] == retry["dedupe_key"]
                       and first["briefing_fingerprint"] != changed["briefing_fingerprint"]
                       and first["delivery_ref"] != changed["delivery_ref"],
            "CB-0852": first["delivery_status"] == "pending"
                       and first["delivery_intent"]["delivery_ref"] == first["delivery_ref"]
                       and retry["delivery_status"] == "suppressed"
                       and retry["delivery_intent"] is None,
            "CB-0853": all(
                delivery[name]["decision"] == "suppress"
                and delivery[name]["delivery_intent"] is None
                for name in ("stale", "blocked", "unavailable")
            ) and probe["unknown_tick"]["decision"] == "suppress",
            "CB-0854": first["delivery_status"] == "pending"
                       and probe["initial"]["delivery_status"] == "pending"
                       and "provider_receipt" not in first,
        }
        self.assertEqual(
            {f"CB-{x:04d}" for x in list(range(835, 850)) + list(range(850, 855))},
            EXECUTABLE | NON_EXECUTABLE,
        )
        self.assertFalse(EXECUTABLE & NON_EXECUTABLE)
        self.assertEqual(set(predicates), EXECUTABLE)
        for row_id, condition in predicates.items():
            with self.subTest(row=row_id):
                self.assertTrue(condition, "Existing code did not meet " + row_id)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing evidence, stale authority and invalid input fail closed."""
        sched = local_scheduler()
        self.assertIsNone(sched["parse_gate"]("ordinary issue without human gate"))
        self.assertIsNone(sched["parse_gate"](
            '<!-- factory-human-gate {"options":[]} -->'
        ))
        self.assertIsNone(sched["parse_gate"](
            '<!-- factory-human-gate {"options":[{"id":"1","label":"bad"}]} -->'
        ))
        self.assertEqual(sched["chat_items"](None), [])
        sched["UNBLOCK_TRIES"] = {"ControlBot#22": [1900, 1950]}
        denied = sched["unblock_candidates"](
            {"ControlBot": {"open_numbers": [22, 23], "blocked": [
                {"n": 22, "cause": "CI", "h": 1},
                {"n": 23, "cause": "readiness", "h": 2},
            ]}}, set(),
        )
        # Use a controlled monotonic instant, not today's wall-clock time.
        with mock.patch.object(sched["time"], "time", return_value=2000):
            self.assertEqual(denied, ["ControlBot#22"])
            self.assertEqual(sched["unblock_candidates"](
                {"ControlBot": {"open_numbers": [22, 23], "blocked": [
                    {"n": 22, "cause": "CI", "h": 1},
                    {"n": 23, "cause": "readiness", "h": 2},
                ]}}, set(),
            ), [])
        probe = briefing_negatives()
        for name in (
            "untrusted_tick", "missing_tick", "invalid_day", "invalid_policy",
            "unapproved_channel", "forged_receipt", "unconfirmed_receipt",
            "unsafe_ref", "extra_claim",
        ):
            with self.subTest(case=name):
                self.assertEqual(probe[name], {"invalid": True})
        for name in ("unknown_tick", "unavailable", "valid_receipt"):
            with self.subTest(case=name):
                row = probe[name]
                self.assertEqual(row["decision"], "suppress")
                self.assertEqual(row["status"], "suppressed")
                self.assertIsNone(row["intent"])
        self.assertIn("scheduler_unknown", probe["unknown_tick"]["reasons"])
        self.assertIn("duplicate", probe["valid_receipt"]["reasons"])
        self.assertTrue(briefing()["invalid_snapshot_rejected"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: only synthetic offline fixtures, no provider credentials or I/O."""
        self.assertTrue(ORQ.is_file() and FIXTURE.is_file())
        source = Path(__file__).read_text(encoding="utf-8")
        for module in ast.walk(ast.parse(source)):
            if isinstance(module, ast.Import):
                self.assertFalse(
                    {v.name.split(".")[0] for v in module.names}
                    & {"socket", "requests", "urllib", "httpx", "pymysql"},
                )
        self.assertNotIn("matriz" + "-cobertura", source)
        for result in (briefing()["first"], briefing_negatives()["initial"]):
            self.assertEqual(result["delivery_status"], "pending")
            self.assertIn("controlbot:", result["delivery_ref"])
            self.assertNotIn("sent_at", result)
            self.assertNotIn("provider_id", result)
        self.assertEqual(local_scheduler()["get_data"]().__class__, tuple)


if __name__ == "__main__":
    unittest.main()

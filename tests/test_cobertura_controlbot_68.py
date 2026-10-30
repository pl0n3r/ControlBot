#!/usr/bin/env python3
"""ControlBot #1251: real, synthetic PHP proofs for WeeklyFocus CB-1124..1129.

All executable assertions call the checked-in PHP implementation. The remaining
13 epic/integration rows are classified in the PR and issue without pretending
a local fixture proves production, browser, backup or external authority.
Never read coverage-matrix files or claim a matrix status from a test.
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
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tests" / "weekly_focus_scenarios.php"
IMPLEMENTATION = ROOT / "src" / "WeeklyFocus.php"
SCHEDULER = ROOT / "src" / "SchedulerSelection.php"
A_ROWS = frozenset({"CB-1124", "CB-1125", "CB-1126", "CB-1127",
                    "CB-1128", "CB-1129"})
NON_OFFLINE = frozenset({*(f"CB-{n:04d}" for n in range(1152, 1164)),
                         "CB-1170"})
ALL_ROWS = A_ROWS | NON_OFFLINE

# Executes the original PHP classes, using the repository's fixture constructors,
# and asserts invalid requests NEVER silently become a selected work item.
PHP_NEGATIVE_PROBE = r"""
ob_start();
require 'tests/weekly_focus_scenarios.php';
ob_end_clean();
use ControlBot\Scheduler\WeeklyFocus;
function rejected(callable $run): bool {
    try { $run(); return false; }
    catch (\InvalidArgumentException $e) { return true; }
}
$alpha='controlbot:project/alpha';
$beta='controlbot:project/beta';
$original=focus([$beta,$alpha]);
$candidates=[candidate('work-a',701,'high'),
             candidate('work-b',702,'high')];
$meta=metadata(['work-a'=>$alpha,'work-b'=>$beta]);
$future=WeeklyFocus::revise($original,[$alpha,$beta],1,200,'controlbot:actor/owner');
$before=WeeklyFocus::preference($original,$candidates,$meta);
$after=WeeklyFocus::preference($future,$candidates,$meta);
$allBlocked=WeeklyFocus::preference(
    focus([$alpha]),[candidate('work-a',701,'high',false)],
    metadata(['work-a'=>$alpha]));
$allUnavailable=WeeklyFocus::preference(
    focus([$alpha]),[candidate('work-a',701,'high')],
    ['work-a'=>['focus_ref'=>$alpha,'ref_state'=>'unavailable']]);
$spoofFocus=$original; $spoofFocus['policy_rank']=1;
$badState=$meta; $badState['work-a']['ref_state']='ready';
$badRef=$meta; $badRef['work-a']['focus_ref']='https://example.invalid/7';
$missing=$meta; unset($missing['work-b']);
echo json_encode([
  'only_suggestion'=>!array_key_exists('selected_key',$before)
       && !array_key_exists('policy_rank',$before)
       && array_key_exists('preferred_key',$before),
  'blocked_no_choice'=>$allBlocked['preferred_key']===null
       && $allBlocked['preference_reason']==='no_eligible_candidate'
       && $allBlocked['focus_version']===null,
  'unavailable_no_choice'=>$allUnavailable['preferred_key']===null
       && $allUnavailable['preference_reason']==='no_eligible_candidate',
  'future_only'=>$original['version']===1 && $future['version']===2
       && $original['ordered_refs'][0]===$beta
       && $future['ordered_refs'][0]===$alpha
       && $before['preferred_key']==='work-b'
       && $after['preferred_key']==='work-a'
       && WeeklyFocus::activeAt([$original,$future],150)['version']===1
       && WeeklyFocus::activeAt([$original,$future],250)['version']===2,
  'spoofed_policy_rank_rejected'=>rejected(fn()=>WeeklyFocus::normalize($spoofFocus)),
  'wrong_cohort_rejected'=>rejected(fn()=>WeeklyFocus::preference(
      $original,[candidate('work-a',701,'high'),candidate('work-b',702,'low')],$meta)),
  'missing_metadata_rejected'=>rejected(fn()=>WeeklyFocus::preference($original,$candidates,$missing)),
  'bad_state_rejected'=>rejected(fn()=>WeeklyFocus::preference($original,$candidates,$badState)),
  'bad_ref_rejected'=>rejected(fn()=>WeeklyFocus::preference($original,$candidates,$badRef)),
  'missing_version_rejected'=>rejected(fn()=>WeeklyFocus::revise($original,[$alpha],0,200,'controlbot:actor/owner')),
  'stale_version_rejected'=>rejected(fn()=>WeeklyFocus::revise($original,[$alpha],2,200,'controlbot:actor/owner')),
  'stale_timestamp_rejected'=>rejected(fn()=>WeeklyFocus::revise($original,[$alpha],1,100,'controlbot:actor/owner')),
  'duplicate_focus_rejected'=>rejected(fn()=>WeeklyFocus::normalize(focus([$alpha,$alpha]))),
  'untyped_ref_rejected'=>rejected(fn()=>WeeklyFocus::normalize(focus(['https://example.invalid/7']))),
  'history_gap_rejected'=>rejected(fn()=>WeeklyFocus::activeAt(
      [$original,focus([$alpha],3,300)],400)),
],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),PHP_EOL;
"""


def _php(arguments: list[str]) -> str:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable, cannot claim coverage")
    for path in (SCRIPT, IMPLEMENTATION, SCHEDULER):
        if not path.is_file():
            raise AssertionError(f"UNKNOWN: PHP code missing: {path.name}")
    base = [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
            "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,shell_exec,system,exec,passthru,proc_open,popen"]
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    # The CI coverage wrapper requires these synthetic, non-secret control paths.
    for key in ("CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
                "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE"):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="cb1251-offline-") as home:
        env["HOME"] = home
        result = subprocess.run(base + arguments, cwd=ROOT, env=env,
                                capture_output=True, text=True, timeout=60,
                                check=False)
    # Existing PHP fixture uses a harmless top-level non-compound use statement.
    # Ignore only that known compile-time warning; fail on every other diagnostic.
    benign = "The use statement with non-compound name 'InvalidArgumentException' has no effect"
    unexpected = [line for line in result.stderr.splitlines() if benign not in line]
    if result.returncode != 0 or unexpected:
        raise AssertionError("UNKNOWN: real PHP failed: " + result.stderr[-650:])
    return result.stdout.strip()


@lru_cache(maxsize=2)
def php_scenarios() -> dict:
    try:
        result = json.loads(_php([str(SCRIPT)]))
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed PHP scenario JSON") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: malformed PHP fixture result")
    return result


@lru_cache(maxsize=2)
def php_negative_probe() -> dict:
    try:
        result = json.loads(_php(["-r", PHP_NEGATIVE_PROBE]))
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed PHP negative-case JSON") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: malformed PHP negative probe")
    return result


class CoberturaControlbot68Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each genuinely offline row exercises PHP class behavior."""
        d = php_scenarios()
        probe = php_negative_probe()
        first, second = d["first"], d["second"]
        rows = {
            "CB-1124": (
                d["low_cannot_win"] is True
                and d["critical_protected"] is True
                and probe["wrong_cohort_rejected"] is True
                and first["priority"] == second["priority"] == "high"
            ),
            "CB-1125": (
                d["parallel_authority_absent"] is True
                and probe["only_suggestion"] is True
                and probe["spoofed_policy_rank_rejected"] is True
                and "selected_key" not in first
                and "policy_rank" not in first
            ),
            "CB-1126": (
                d["blocked"]["preferred_key"] == "work-b"
                and d["unavailable"]["preferred_key"] == "work-b"
                and probe["blocked_no_choice"] is True
                and probe["unavailable_no_choice"] is True
            ),
            "CB-1127": (
                first["preferred_key"] == "work-b"
                and first["focus_version"] == 1
                and first["focus_position"] == 1
                and first["focus_influenced"] is True
                and first["preference_reason"] == "weekly_focus_tiebreak"
                and re.fullmatch(r"[0-9a-f]{64}", first["request_fingerprint"]) is not None
                and d["shared"]["focus_influenced"] is False
                and d["shared"]["preference_reason"] == "canonical_cohort_order"
            ),
            "CB-1128": (
                probe["future_only"] is True
                and first["preferred_key"] == "work-b"
                and second["preferred_key"] == "work-a"
                and d["history_150"]["version"] == 1
                and d["history_250"]["version"] == 2
            ),
            "CB-1129": (
                second["focus_version"] == 2
                and d["conflict_rejected"] is True
                and probe["stale_version_rejected"] is True
                and probe["missing_version_rejected"] is True
                and probe["stale_timestamp_rejected"] is True
            ),
        }
        self.assertEqual(set(rows), A_ROWS)
        self.assertEqual(len(ALL_ROWS), 19)
        for ident, valid in rows.items():
            with self.subTest(requirement=ident):
                self.assertIs(valid, True, "Real PHP contract failed: " + ident)

    def test_falla_cerrado(self) -> None:
        """AC-02: incomplete metadata, blocked refs and stale revisions reject."""
        d, p = php_scenarios(), php_negative_probe()
        for key in (
            "only_suggestion", "blocked_no_choice", "unavailable_no_choice",
            "spoofed_policy_rank_rejected", "wrong_cohort_rejected",
            "missing_metadata_rejected", "bad_state_rejected",
            "bad_ref_rejected", "missing_version_rejected",
            "stale_version_rejected", "stale_timestamp_rejected",
            "duplicate_focus_rejected", "untyped_ref_rejected",
            "history_gap_rejected",
        ):
            with self.subTest(negative_path=key):
                self.assertIs(p[key], True)
        self.assertIs(d["untyped_rejected"], True)
        self.assertEqual(d["blocked"]["preferred_key"], "work-b")
        self.assertEqual(d["unavailable"]["preferred_key"], "work-b")

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic fixtures, no outbound I/O, secrets or product writes."""
        self.assertEqual(len(ALL_ROWS), 19)
        self.assertEqual(len(A_ROWS), 6)
        source = IMPLEMENTATION.read_text(encoding="utf-8").lower()
        for banned in ("curl_", "fsockopen", "stream_socket_client",
                       "new pdo", "mysqli", "file_put_contents",
                       "shell_exec", "proc_open", "selected_key'=>",
                       "policy_rank'=>", "schedulerassignmentcommit"):
            with self.subTest(prohibited=banned):
                self.assertNotIn(banned, source)
        self.assertIs(php_negative_probe()["future_only"], True)
        self.assertRegex(php_scenarios()["first"]["request_fingerprint"],
                         r"^[0-9a-f]{64}$")
        self.assertTrue(SCRIPT.name == "weekly_focus_scenarios.php")
        self.assertTrue(IMPLEMENTATION.name == "WeeklyFocus.php")
        for node in ast.walk(ast.parse(Path(__file__).read_text(encoding="utf-8"))):
            if isinstance(node, ast.Import):
                modules = {x.name.split(".")[0] for x in node.names}
            elif isinstance(node, ast.ImportFrom):
                modules = {(node.module or "").split(".")[0]}
            else:
                continue
            self.assertFalse(modules & {"requests", "urllib", "httpx",
                                        "socket", "pymysql"})


if __name__ == "__main__":
    unittest.main()

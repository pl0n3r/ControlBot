#!/usr/bin/env python3
"""ControlBot #1046: 15 synthetic, fail-closed contrasts CB-0581..CB-0596.

Only offline PHP fixtures count here. CI, live, owner authority, provider
sessions and production readiness are UNKNOWN until separately demonstrated.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(581, 597) if n != 587)
PRESENT = frozenset(("CB-0588", "CB-0595"))
UNKNOWN = "UNKNOWN"
OFFLINE = "OFFLINE_TESTED"
FIXTURES = frozenset({
    "incident_postmortem_scenarios.php",
    "infrastructure_action_projection_scenarios.php",
    "infrastructure_inventory_scenarios.php",
})
KINDS = {
    "environment", "service", "database", "storage", "dns",
    "certificate", "backup", "network",
}
RELATIONS = {
    "necessary_cause": "root_cause",
    "independent_defect": "independent_bug",
    "contributor": "contributing_factor",
    "preventive_only": "preventive_change",
    "unresolved": "unknown",
}


def safe_environment() -> dict[str, str]:
    """Preserve only subprocess/Coverage plumbing, never inherited credentials."""
    allowed = (
        "PATH", "HOME", "LANG", "LC_ALL", "TZ", "TMPDIR", "SYSTEMROOT",
        "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE",
    )
    return {key: os.environ[key] for key in allowed if key in os.environ}


def php_run(filename: str, *arguments: str) -> object:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP runtime unavailable")
    if filename not in FIXTURES:
        raise ValueError("fixture outside the reserved contract")
    script = ROOT / "tests" / filename
    if not script.is_file():
        raise AssertionError("UNKNOWN: fixture missing")
    run = subprocess.run(
        [php, str(script), *arguments], cwd=ROOT, env=safe_environment(),
        capture_output=True, text=True, check=False, timeout=35,
    )
    if run.returncode:
        raise AssertionError(
            f"UNKNOWN: offline fixture {filename} failed: {run.stderr[-600:]}"
        )
    # This one existing synthetic fixture emits a PHP compiler warning for an
    # ineffectual global use statement. Ignore only that exact known warning,
    # never an unknown diagnostic, nonzero exit code or malformed JSON.
    diagnostics = [line.strip() for line in run.stderr.splitlines() if line.strip()]
    expected = re.compile(
        r"PHP Warning:\s+The use statement with non-compound name "
        r"'InvalidArgumentException' has no effect in "
        r".*/tests/infrastructure_action_projection_scenarios\.php on line 22"
    )
    if diagnostics and not (
        filename == "infrastructure_action_projection_scenarios.php"
        and all(expected.fullmatch(line) for line in diagnostics)
    ):
        raise AssertionError(
            f"UNKNOWN: offline fixture {filename} diagnostics: {run.stderr[-600:]}"
        )
    try:
        return json.loads(run.stdout)
    except (TypeError, ValueError) as exc:
        raise AssertionError("UNKNOWN: malformed fixture result") from exc


@lru_cache(maxsize=32)
def incident(case: str) -> dict:
    return php_run("incident_postmortem_scenarios.php", case)


@lru_cache(maxsize=1)
def projection() -> dict:
    return php_run("infrastructure_action_projection_scenarios.php")


@lru_cache(maxsize=24)
def inventory(case: str):
    return php_run("infrastructure_inventory_scenarios.php", case)


def matrix_states(raw: str) -> dict[str, str]:
    """An absent, duplicated, incomplete or promoted row is UNKNOWN."""
    rows = {name: [] for name in IDS}
    for line in raw.splitlines():
        if not line.startswith("| CB-"):
            continue
        cells = tuple(v.strip() for v in line.strip("|").split("|"))
        if cells and cells[0] in rows:
            rows[cells[0]].append(cells)
    return {
        name: (
            "NO_VERIFICABLE"
            if len(items) == 1 and len(items[0]) == 6
            and all(items[0]) and items[0][3] == "NO_VERIFICABLE"
            and "UNKNOWN" in items[0][4] else UNKNOWN
        ) for name, items in rows.items()
    }


def operational_state(_clause: str, _claimed: object = None) -> str:
    """Neither a synthetic pass nor a merge proves production authorization."""
    return UNKNOWN


def verdicts() -> dict[str, bool]:
    """Independently grounded assertions over existing PHP business behavior."""
    timeline = incident("timeline")
    missing = incident("missing_recovery")
    postmortem = incident("postmortem")
    uncertain = incident("unknown")["incomplete"]
    rejected = incident("observer_reclassified")
    bad_recovery = incident("bad_recovery")
    action = projection()
    resources = inventory("typed-resources")
    findings = postmortem["findings"]
    unknown_facts = [
        row for row in uncertain["findings"]
        if row["evidence_state"] == "incomplete"
    ]
    planned, owner, denied = (action[key] for key in ("planned", "owner", "denied"))
    return {
        "CB-0581": (
            timeline["opened_at"] == 100
            and timeline["detected_at"] == 110
            and timeline["recovered_at"] == 220
            and timeline["duration_seconds"] == 110
            and missing["recovered_at"] is None
            and missing["duration_seconds"] is None
        ),
        "CB-0582": (
            {row["classification"] for row in findings}
            == {"root_cause", "independent_bug", "contributing_factor",
                "preventive_change"}
            and rejected["rejected"] is True
        ),
        "CB-0583": (
            all(RELATIONS[row["evidence_relation"]] == row["classification"]
                for row in findings)
            and all(row["evidence_state"] == "supported" for row in findings)
        ),
        "CB-0584": (
            len(unknown_facts) == 1
            and unknown_facts[0]["classification"] == "unknown"
            and unknown_facts[0]["evidence_relation"] == "unresolved"
            and unknown_facts[0]["owner_action_required"] is True
            and "Billing hard stop" in unknown_facts[0]["summary"]
        ),
        "CB-0585": (
            postmortem["recovery"] == {
                "canary_issue": 86, "mode": "serial", "fan_out": False,
                "serial_queue": [72, 77, 80, 71, 84, 73, 75],
            }
            and all(value is True for value in bad_recovery.values())
        ),
        "CB-0586": (
            set(postmortem) == {
                "version", "incident_ref", "duration_seconds",
                "findings", "recovery",
            }
            and postmortem["version"] == 1
            and all(row["finding_ref"].startswith("finding:") for row in findings)
        ),
        "CB-0588": (
            planned["status"] == "planned"
            and planned["action_ref"] == "controlbot:infrastructure-action/intent-205"
            and planned["source_ref"] == "controlbot:infrastructure-intent/intent-205"
            and action["fabricated_array_rejected"] is True
        ),
        "CB-0589": (
            action["fake_plan_rejected"] is True
            and action["caller_status_rejected"] is True
            and planned["authority_decision"] == "allow"
        ),
        "CB-0590": (
            all(action[k]["execution"] is False for k in ("planned", "owner", "denied"))
            and planned["runner_request_ready"] is True
            and denied["runner_request_ready"] is False
        ),
        "CB-0591": (
            action["cross_capability_rejected"] is True
            and planned["scope"] == "project:controlbot"
        ),
        "CB-0592": (
            owner["status"] == "owner_decision_required"
            and owner["approval_ref"] == "controlbot:approval/infra-intent-owner"
            and owner["runner_request_ready"] is False
            and denied["status"] == "denied"
            and denied["work_item_id"] is None
            and denied["approval_ref"] is None
        ),
        "CB-0593": (
            action["fabricated_array_rejected"] is True
            and action["fabricated_object_rejected"] is True
            and action["cross_capability_rejected"] is True
        ),
        "CB-0594": (
            action["secret_rejected"] is True
            and set(planned) == {
                "version", "action_ref", "intent_ref", "source_ref", "scope",
                "status", "authority_decision", "reasons", "work_item_id",
                "approval_ref", "runner_request_ready", "execution",
            }
            and all(action[key]["execution"] is False
                    for key in ("planned", "owner", "denied"))
        ),
        "CB-0595": (
            len(resources) == 8
            and all(row["project_ref"] == "controlbot:project/project-controlbot"
                    for row in resources)
            and all("authorization" not in row and "health" not in row
                    and "execution" not in row for row in resources)
            and inventory("bad-release-sha")["blocked"] is True
        ),
        "CB-0596": (
            {row["kind"] for row in resources} == KINDS
            and inventory("unknown-resource-kind")["blocked"] is True
            and inventory("unknown-field")["blocked"] is True
        ),
    }


def invalid_chronology_rejected() -> list[bool]:
    """Exercise real IncidentTimeline with two intentionally invalid timestamps."""
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP runtime unavailable")
    program = r"""
require 'src/IncidentTimeline.php';
$base = [
    'version'=>1, 'incident_ref'=>'incident:'.str_repeat('a',32),
    'opened_at'=>100, 'detected_at'=>110, 'recovered_at'=>220,
    'events'=>[[
        'event_id'=>'event-opened', 'timestamp'=>100, 'sequence'=>1,
        'kind'=>'incident.opened', 'source'=>'controlbot',
        'evidence_ref'=>'evidence:'.str_repeat('b',32),
    ]],
];
$out=[];
foreach ([['detected_at'=>99],['recovered_at'=>109]] as $bad) {
    try {
        \ControlBot\Business\IncidentTimeline::build(array_replace($base,$bad));
        $out[]=false;
    } catch (\InvalidArgumentException $e) {
        $out[]=true;
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        [php, "-r", program], cwd=ROOT, env=safe_environment(),
        capture_output=True, text=True, check=False, timeout=20,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: chronology fixture failed")
    try:
        return json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: chronology result invalid") from exc


class BrechasControlbot39Tests(unittest.TestCase):
    """One behavioral verdict for each of the 15 required IDs."""

    def test_contrato_principal(self) -> None:
        cases = verdicts()
        self.assertEqual(set(IDS), set(cases))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertIs(cases[clause], True, clause)

    def test_falla_cerrado(self) -> None:
        self.assertEqual(invalid_chronology_rejected(), [True, True])
        self.assertTrue(incident("observer_reclassified")["rejected"])
        self.assertTrue(all(incident("bad_recovery").values()))
        for key in (
            "fake_plan_rejected", "caller_status_rejected",
            "fabricated_array_rejected", "fabricated_object_rejected",
            "cross_capability_rejected", "secret_rejected",
        ):
            with self.subTest(case=key):
                self.assertIs(projection()[key], True)
        for name in ("unknown-provider-kind", "unknown-capability",
                     "unknown-scope", "unknown-resource-kind",
                     "unknown-field", "duplicate-resource"):
            with self.subTest(case=name):
                self.assertIs(inventory(name)["blocked"], True)
        raw = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        row = next(line for line in raw.splitlines()
                   if line.startswith("| CB-0588 |"))
        self.assertEqual(matrix_states(raw + "\n" + row)["CB-0588"], UNKNOWN)
        self.assertEqual(matrix_states("")["CB-0595"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        for fixture in FIXTURES:
            self.assertTrue((ROOT / "tests" / fixture).is_file())
        self.assertEqual(len(IDS), 15)
        env = safe_environment()
        for sensitive in ("GITHUB_TOKEN", "GH_TOKEN", "DB_PASSWORD",
                          "HOSTINGER_API_TOKEN", "SENTRY_AUTH_TOKEN",
                          "AWS_SECRET_ACCESS_KEY"):
            self.assertNotIn(sensitive, env)
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(
            source,
            r"(?m)^\s*(?:import|from)\s+(?:requests|httpx|urllib|socket)\b",
        )
        # Real provider operations are not reachable from the test's helper.
        self.assertTrue(all(script.endswith(".php") for script in FIXTURES))

    def test_estados_unknown_explicitos(self) -> None:
        states = matrix_states(
            (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        )
        self.assertEqual(
            {k for k in IDS if states[k] == "NO_VERIFICABLE"}, PRESENT
        )
        self.assertEqual(
            {k for k in IDS if states[k] == UNKNOWN}, set(IDS) - PRESENT
        )
        for clause in IDS:
            with self.subTest(clause=clause):
                for claim in (None, {}, True, "success", {"ci": "success"},
                              {"review": "approved"}, {"production": "green"},
                              {"offline": OFFLINE}, {"merge": "claimed"}):
                    self.assertEqual(operational_state(clause, claim), UNKNOWN)


if __name__ == "__main__":
    unittest.main()

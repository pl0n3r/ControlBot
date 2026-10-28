#!/usr/bin/env python3
"""ControlBot #1031: offline regression for CB-0349..CB-0363.

Only synthetic PHP fixtures and read-only repository documents are inspected.
Neither passing tests nor matrix inventory proves a live finance read,
privileged GitHub authority, production readiness, or real customer data.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = tuple(f"CB-{i:04d}" for i in range(349, 364))
UNKNOWN = "UNKNOWN"
OFFLINE = "OFFLINE_TESTED"
# The coverage matrix is a sparse inventory: twelve clauses of this
# contiguous slice currently have no individual row. Never create a
# fictitious matrix row or silently treat an absent one as covered.
MATRIX_PRESENT_AT_CUT = frozenset(("CB-0350", "CB-0358", "CB-0363"))
FIXTURES = {
    "capital": "capital_policy_scenarios.php",
    "finance": "finance_runtime_scenarios.php",
    "planning": "financial_planning_scenarios.php",
    "venture": "venture_finance_scenarios.php",
    "github": "github_project_snapshot_scenarios.php",
}
CLAUSES = {
    "CB-0349": "finance integration is not an accounting or payment service",
    "CB-0350": "identity and exact Venture grant precede financial disclosure",
    "CB-0351": "read scope, Venture, period and currency agree",
    "CB-0352": "green requires compatible fresh financial evidence",
    "CB-0353": "CapitalPolicy delegates to BudgetGuard and DecisionRights",
    "CB-0354": "ControlBot authority is mapped explicitly to Factory",
    "CB-0355": "ready_hint never becomes canonical dispatch authority",
    "CB-0356": "stale/unknown financial evidence cannot become ready",
    "CB-0357": "finance rights, budget, human gate and Factory stay separate",
    "CB-0358": "either actual or comparison freshness unknown denies variance",
    "CB-0359": "stale financial series is never promoted to fresh",
    "CB-0360": "forecast cannot replace an absent actual",
    "CB-0361": "attribution requires target, rule and provenance",
    "CB-0362": "closed schemas and opaque, non-sensitive references",
    "CB-0363": "GitHub is read-only pending explicit production authority",
}


def matrix_states(text: str) -> dict[str, str]:
    """Return an explicit state for all fifteen IDs, including absent rows."""
    rows: dict[str, list[tuple[str, ...]]] = {key: [] for key in IDS}
    for line in text.splitlines():
        if not line.startswith("| CB-"):
            continue
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        key = cells[0] if cells else ""
        if key in rows:
            rows[key].append(cells)
    result = {}
    for key, items in rows.items():
        if len(items) != 1:
            result[key] = UNKNOWN
            continue
        cells = items[0]
        if (len(cells) != 6 or cells[3] != "NO_VERIFICABLE"
                or "UNKNOWN" not in cells[4] or not all(cells)):
            result[key] = UNKNOWN
            continue
        result[key] = "NO_VERIFICABLE"
    return result


@lru_cache(maxsize=32)
def scenario(which: str, name: str) -> dict | None:
    """Execute an existing PHP fixture in a scrubbed offline environment."""
    filename = FIXTURES.get(which)
    php = shutil.which("php")
    if filename is None or php is None:
        return None
    path = ROOT / "tests" / filename
    if not path.is_file():
        return None
    try:
        result = subprocess.run(
            [php, str(path), name], cwd=ROOT,
            env={"PATH": os.path.dirname(php) + os.pathsep + "/usr/bin:/bin"},
            capture_output=True, text=True, check=False, timeout=40,
        )
        if result.returncode != 0 or result.stderr.strip():
            return None
        value = json.loads(result.stdout)
        return value if isinstance(value, dict) else None
    except (OSError, ValueError, subprocess.TimeoutExpired):
        return None


ACTUAL_UNKNOWN_PHP = r"""
require 'src/FinancialPlanning.php';
$series = static function (string $kind, string $freshness): array {
    return [
        'kind' => $kind, 'venture_id' => 'condor', 'period' => '2026-09',
        'currency' => 'COP', 'amount_minor' => 1000,
        'source_ref' => 'controlbot:finance/' . $kind . '-synthetic',
        'observed_at' => 2000, 'as_of' => 1900,
        'freshness' => $freshness,
        'confidence' => $freshness === 'unknown' ? 'unknown' : 'estimated',
    ];
};
$input = [
    'version' => 1, 'venture_id' => 'condor', 'period' => '2026-09',
    'currency' => 'COP', 'actual' => $series('actual', 'unknown'),
    'budget' => $series('budget', 'fresh'),
    'target' => $series('target', 'fresh'),
    'forecast' => $series('forecast', 'fresh'), 'attributions' => [],
];
$out = \ControlBot\Business\FinancialPlanning::compare($input);
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
"""


def actual_unknown() -> dict | None:
    """Exact clause CB-0358: the existing suite only varied forecast freshness."""
    php = shutil.which("php")
    if php is None:
        return None
    try:
        result = subprocess.run(
            [php, "-r", ACTUAL_UNKNOWN_PHP], cwd=ROOT,
            env={"PATH": os.path.dirname(php) + os.pathsep + "/usr/bin:/bin"},
            capture_output=True, text=True, check=False, timeout=20,
        )
        if result.returncode or result.stderr.strip():
            return None
        value = json.loads(result.stdout)
        return value if isinstance(value, dict) else None
    except (OSError, ValueError, subprocess.TimeoutExpired):
        return None


def operational_state(_clause: str, _observation: object = None) -> str:
    """A synthetic receipt, PR link or local pass cannot prove deployment."""
    return UNKNOWN


def clause_checks() -> dict[str, bool]:
    """One independently evaluated behavioral condition per specified ID."""
    no_pay = scenario("capital", "no-payments") or {}
    scope = scenario("finance", "scope") or {}
    owner = scenario("finance", "owner") or {}
    factory = scenario("finance", "factory") or {}
    rights = scenario("finance", "authorities") or {}
    fresh = scenario("finance", "freshness") or {}
    planning = scenario("planning", "compatible") or {}
    missing = scenario("planning", "missing-actual") or {}
    attributed = scenario("planning", "attribution") or {}
    stale = scenario("planning", "evidence") or {}
    unknown_forecast = scenario("planning", "deterministic") or {}
    unknown_actual = actual_unknown() or {}
    provenance = scenario("venture", "provenance") or {}
    github = scenario("github", "routes") or {}

    read = scope.get("allowed") or {}
    cross = scope.get("cross") or {}
    snapshot = read.get("snapshot") or {}
    plan = read.get("planning") or {}
    result = owner.get("result") or {}
    permitted = rights.get("allowed") or {}
    denied = rights.get("denied") or {}
    item = factory.get("work_item") or {}
    evidence = permitted.get("capital") or {}
    details = evidence.get("authority_evidence") or {}
    unknown_variances = unknown_actual.get("variances") or {}
    attributions = {r["attribution_id"]: r for r in attributed.get("attributions", [])
                    if isinstance(r, dict) and "attribution_id" in r}
    nonpay = no_pay.get("evaluated") or {}

    return {
        "CB-0349": (no_pay.get("payment") not in (None, "executed")
                    and no_pay.get("leaked") is False
                    and nonpay.get("decision") != "allow"),
        "CB-0350": (read.get("status") == "allow"
                    and cross.get("status") == "deny"
                    and cross.get("snapshot") is None
                    and cross.get("planning") is None),
        "CB-0351": (read.get("scope") == "venture:" + str(snapshot.get("venture_id"))
                    and all(snapshot.get(field) == plan.get(field)
                            for field in ("venture_id", "period", "currency"))
                    and read.get("status") == "allow"),
        "CB-0352": (read.get("state") == "green"
                    and (fresh.get("stale") or {}).get("state") == "stale"
                    and (fresh.get("unknown") or {}).get("state") == "unknown"),
        "CB-0353": (permitted.get("authority_source") == "CapitalPolicy"
                    and {"budget_guard", "decision_rights"} <= set(details)),
        "CB-0354": (item.get("authority_level") == "operational"
                    and item.get("origin_system") == "capital"
                    and item.get("work_type") == "finance_analysis"),
        "CB-0355": (factory.get("queue") == "factory"
                    and type(factory.get("ready_hint")) is bool
                    and "work_item" in factory
                    and "dispatch" not in factory),
        "CB-0356": ((fresh.get("handoff") or {}).get("ready_hint") is False
                    and (fresh.get("handoff") or {}).get("freshness") == "stale"),
        "CB-0357": (result.get("status") == "owner_decision_required"
                    and result.get("execution") is False
                    and (owner.get("gate") or {}).get("safe_default") == "B"
                    and denied.get("status") == "deny"
                    and denied.get("execution") is False),
        "CB-0358": (unknown_actual.get("status") == "unknown"
                    and unknown_actual.get("reason") == "actual_freshness_unknown"
                    and len(unknown_variances) == 3
                    and all(v["status"] == "unavailable"
                            and v["reason"] == "actual_unknown"
                            and v["delta_minor"] is None
                            for v in unknown_variances.values())
                    and ((unknown_forecast.get("unknown") or {})
                         .get("variances") or {}).get("forecast", {})
                        .get("reason") == "forecast_unknown"),
        "CB-0359": ((stale.get("series") or {}).get("budget") or {})
                        .get("freshness") == "stale"
                    and ((stale.get("series") or {}).get("budget") or {})
                        .get("confidence") == "estimated",
        "CB-0360": (missing.get("reason") == "actual_missing"
                    and missing.get("status") == "unavailable"
                    and (missing.get("series") or {}).get("actual") is None
                    and (missing.get("series") or {}).get("forecast") is not None
                    and all(v.get("delta_minor") is None
                            for v in (missing.get("variances") or {}).values())),
        "CB-0361": ((attributions.get("shared-platform") or {}).get("status") == "unattributed"
                    and all((attributions.get("shared-platform") or {}).get(k) is None
                            for k in ("target_scope", "rule_ref", "provenance_ref"))),
        "CB-0362": (len(provenance) >= 3
                    and all(value != "accepted" for value in provenance.values())
                    and "customer" not in json.dumps(item).lower()
                    and "token" not in json.dumps(item).lower()),
        "CB-0363": (len(github.get("calls") or []) >= 8
                    and all(isinstance(call, list) and call[0] == "GET"
                            and call[3] is None for call in github["calls"])
                    and "GitHub" not in json.dumps(item)),
    }


class BrechasControlbot24Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: all 15 clauses have behavior or explicit UNKNOWN evidence."""
        results = clause_checks()
        self.assertEqual(set(results), set(IDS))
        self.assertEqual(set(CLAUSES), set(IDS))
        states = matrix_states(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(states), set(IDS))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertTrue(CLAUSES[clause])
                self.assertTrue(results[clause], CLAUSES[clause])
                self.assertIn(states[clause], (UNKNOWN, "NO_VERIFICABLE"))
                self.assertEqual(operational_state(clause, results[clause]), UNKNOWN)
        # Only these three entries exist in the sparse canonical matrix.
        for clause in MATRIX_PRESENT_AT_CUT:
            self.assertEqual(states[clause], "NO_VERIFICABLE")

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicated or falsely promoted evidence never passes."""
        original = MATRIX.read_text(encoding="utf-8")
        states = matrix_states(original)
        self.assertEqual(set(states), set(IDS))
        for clause in MATRIX_PRESENT_AT_CUT:
            row = next(r for r in original.splitlines()
                       if r.startswith("| " + clause + " |"))
            for corrupted in (
                original.replace(row + "\n", "", 1),
                original + "\n" + row,
                original.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(clause=clause):
                    self.assertEqual(matrix_states(corrupted)[clause], UNKNOWN)
        for clause in set(IDS) - MATRIX_PRESENT_AT_CUT:
            self.assertEqual(states[clause], UNKNOWN,
                             "missing row cannot be promoted: " + clause)
        mismatched = scenario("finance", "scope") or {}
        self.assertIsNone((mismatched.get("cross") or {}).get("snapshot"))
        forecast = scenario("planning", "deterministic") or {}
        self.assertIsNone(((forecast.get("unknown") or {})
                           .get("variances") or {}).get("forecast", {}).get("delta_minor"))
        self.assertIsNone(scenario("not-configured", "fake"))
        self.assertEqual(operational_state("CB-0358", {"ci": "success"}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: all fixtures local, no secrets, network or real recipients."""
        for fixture in FIXTURES.values():
            self.assertTrue((ROOT / "tests" / fixture).is_file())
        self.assertEqual(set(CLAUSES), set(IDS))
        data = scenario("finance", "factory") or {}
        item = data.get("work_item") or {}
        self.assertEqual(item.get("origin_system"), "capital")
        self.assertTrue(all(str(ref).startswith("controlbot:")
                            for ref in item.get("evidence_refs", [])))
        for forbidden in ("customers", "transactions", "revenue_streams",
                          "cash_in", "cash_out", "password", "secret", "token"):
            self.assertNotIn(forbidden, json.dumps(item).lower())
        self.assertEqual(operational_state("CB-0363", {"real_github": False}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: a synthetic pass is not operating, authorized or GREEN."""
        states = matrix_states(MATRIX.read_text(encoding="utf-8"))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertNotEqual(states[clause], "CUBIERTO")
                for claim in (None, {}, True, "green", "success",
                              {"ci": "success"}, {"runtime": "claimed"},
                              {"finance": OFFLINE}, {"review": "approved"},
                              {"production": "claimed"}, {"merge": "claimed"}):
                    self.assertEqual(operational_state(clause, claim), UNKNOWN)
        data = actual_unknown()
        self.assertIsNotNone(data)
        self.assertEqual(data["reason"], "actual_freshness_unknown")
        self.assertEqual(data["series"]["actual"]["freshness"], "unknown")
        self.assertTrue(all(v["delta_minor"] is None
                            for v in data["variances"].values()))


if __name__ == "__main__":
    unittest.main()

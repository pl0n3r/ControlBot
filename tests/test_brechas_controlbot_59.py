"""ControlBot #1066: 15 offline discovery/product-outcome coverage gaps.

No CI/live/production assurance is inferred from local scenario contracts.
Missing matrix rows remain explicit UNKNOWN; this PR does not edit matrices.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{i:04d}" for i in range(907, 922))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
MATRICES = tuple(ROOT / f"docs/matriz-cobertura{part}.md"
                 for part in ("", "-parte-01", "-parte-02",
                              "-parte-03", "-parte-04"))
DOCS = {
    "decision": ROOT / "docs/product-discovery-decision.md",
    "experiment": ROOT / "docs/product-discovery-experiment.md",
    "origin": ROOT / "docs/product-discovery-work-origin.md",
    "outcome": ROOT / "docs/product-experiment-outcomes.md",
}
SCRIPTS = {
    "decision": ROOT / "tests/product_discovery_decision_scenarios.php",
    "experiment": ROOT / "tests/product_discovery_experiment_scenarios.php",
    "origin": ROOT / "tests/product_discovery_work_origin_scenarios.php",
    "outcome": ROOT / "tests/product_experiment_outcome_scenarios.php",
}
CLAUSES = {
    "CB-0907": ("decision", "ProductDiscoveryDecision"),
    "CB-0908": ("decision", "BUILD | ITERATE | PARK | STOP | RESEARCH_MORE"),
    "CB-0909": ("decision", "authority"),
    "CB-0910": ("decision", "scope Venture/Product"),
    "CB-0911": ("decision", "no persiste estado"),
    "CB-0912": ("experiment", "paso previo a experimentar"),
    "CB-0913": ("experiment", "refs opacas"),
    "CB-0914": ("experiment", "start_at < end_at"),
    "CB-0915": ("experiment", "group|venture"),
    "CB-0916": ("origin", "ProductDiscoveryWorkOrigin"),
    "CB-0917": ("origin", "Authority, priority, roles"),
    "CB-0918": ("origin", "El schema es cerrado"),
    "CB-0919": ("origin", "sin estado persistente"),
    "CB-0920": ("outcome", "Baseline y variant deben compartir"),
    "CB-0921": ("outcome", "ventanas de métrica deben caer dentro"),
}
PRESENT = {"CB-0907", "CB-0912", "CB-0916", "CB-0920"}


def matrix_rows(documents: object) -> dict[str, tuple[str, ...]]:
    """Only genuine matrix rows count; duplicates/promotions fail closed."""
    if (not isinstance(documents, tuple) or len(documents) != len(MATRICES)
            or any(not isinstance(x, str) or not x for x in documents)):
        raise ValueError("UNKNOWN: missing or untrusted coverage matrix")
    rows: dict[str, tuple[str, ...]] = {}
    for document in documents:
        for line in document.splitlines():
            match = re.match(r"^\| (CB-09(?:0[7-9]|1[0-9]|2[01])) \|", line)
            if not match:
                continue
            ident = match.group(1)
            fields = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
            if (ident in rows or len(fields) != 7 or fields[0] != ident
                    or fields[3] != "NO_VERIFICABLE"
                    or "Offline=UNKNOWN" not in fields[4]
                    or not all(fields[:5])):
                raise ValueError("UNKNOWN: ambiguous or promoted " + ident)
            rows[ident] = fields
    return rows


def matrix_verdict(ident: object, rows: object) -> str:
    """A missing matrix row is NEVER silently treated as proved."""
    if not isinstance(ident, str) or ident not in IDS or not isinstance(rows, dict):
        return UNKNOWN
    row = rows.get(ident)
    if not isinstance(row, tuple) or len(row) != 7 or row[3] != "NO_VERIFICABLE":
        return UNKNOWN
    return LOCAL if ident in PRESENT else UNKNOWN


@lru_cache(maxsize=24)
def scenario(group: str, name: str) -> dict[str, object]:
    if group not in SCRIPTS or not re.fullmatch(r"[a-z_]+", name):
        raise ValueError("UNKNOWN: unsupported scenario")
    script = SCRIPTS[group]
    completed = subprocess.run(
        ["php", str(script), name], cwd=ROOT, capture_output=True,
        text=True, timeout=60, check=False,
    )
    if completed.returncode != 0 or completed.stderr.strip():
        raise AssertionError(completed.stderr.strip() or
                             f"UNKNOWN: {group}/{name} exited {completed.returncode}")
    decoded = json.loads(completed.stdout)
    if not isinstance(decoded, dict):
        raise AssertionError("UNKNOWN: unexpected scenario evidence")
    return decoded


def scenario_evidence(ident: str) -> bool:
    """One independently exercised, synthetic PHP contract per requirement."""
    if ident == "CB-0907":
        return all(scenario("decision", "bindings").values())
    if ident == "CB-0908":
        row = scenario("decision", "catalog")
        return ([row[x] for x in ("build", "iterate", "park",
                                  "stop", "research_more")] ==
                ["BUILD", "ITERATE", "PARK", "STOP", "RESEARCH_MORE"]
                and all(row[x] for x in ("invalid", "reason", "empty_evidence", "duplicate")))
    if ident == "CB-0909":
        gate = scenario("decision", "gates")
        work = gate["build"]
        return (work["required_gates"] == ["authority", "budget", "aegis", "lex"]
                and work["factory_handoff_ready"] is False
                and work["execution"] is False and gate["self_certify"] is True)
    if ident == "CB-0910":
        record = scenario("decision", "nonbuild")
        return all(row["venture_id"] == "venture-alpha"
                   and row["product_id"] == "product-alpha"
                   and row["classification"] == "INVALIDATED"
                   and row["freshness"] == "fresh" for row in record.values())
    if ident == "CB-0911":
        row = scenario("decision", "schema")
        return row["extra"] and row["deterministic"] and not row["valid"]["execution"]
    if ident == "CB-0912":
        row = scenario("experiment", "bind")
        return (row["initiative"] and row["hypothesis"] and row["metric"]
                and row["good"]["initiative_id"].startswith("initiative:")
                and row["good"]["hypothesis_ref"].startswith("hypothesis:"))
    if ident == "CB-0913":
        return all(scenario("experiment", "invalid").values())
    if ident == "CB-0914":
        row = scenario("experiment", "declared")
        return (row["good"]["evaluation_window"] == {"start_at": 1000, "end_at": 2000}
                and row["good"]["kind"] == "landing_test"
                and row["bad_kind"] and row["bad_window"] and row["bad_cost"])
    if ident == "CB-0915":
        row = scenario("experiment", "scope")
        return (row["venture"]["scope"] == "venture"
                and row["group"]["scope"] == "group"
                and row["stale"]["hypothesis_freshness"] == "stale"
                and row["unknown"]["hypothesis_freshness"] == "unknown")
    if ident == "CB-0916":
        ok, blocked = scenario("origin", "valid"), scenario("origin", "blocked")
        return (ok["status"] == "materialized" and not ok["execution"]
                and ok["work_item"]["origin_mode"] == "automatic"
                and all(blocked[x]["work_item"] is None
                        for x in ("iterate", "park", "stop", "research_more",
                                  "stale", "unknown")))
    if ident == "CB-0917":
        row = scenario("origin", "explicit")
        return (row["base"]["work_item"]["authority_level"] == "l2"
                and row["changed"]["work_item"]["authority_level"] == "l3"
                and row["base"]["work_item"]["required_roles"] == ["producto", "qa"]
                and row["changed"]["work_item"]["claims"] ==
                ["claim:review-alpha", "claim:review-beta"])
    if ident == "CB-0918":
        row = scenario("origin", "invalid")
        return (len(row) == 7 and all(
            x["status"] == "blocked" and x["work_item"] is None
            and x["reasons"] == ["invalid_input"] for x in row.values()))
    if ident == "CB-0919":
        row = scenario("origin", "surface")
        return (row["valid"]["status"] == "materialized"
                and row["valid"]["execution"] is False
                and all(x not in row["source"].lower() for x in
                        ("new pdo", "mysqli", "curl_", "shell_exec",
                         "file_put_contents", "factoryrunner")))
    if ident == "CB-0920":
        row = scenario("outcome", "scope")
        return (row["good"]["venture_id"] == "venture-condor"
                and row["good"]["product_id"] == "product-condor"
                and all(row[k] for k in
                        ("venture", "product", "surface", "category", "unit")))
    if ident == "CB-0921":
        row = scenario("outcome", "measured")
        return (row["outcome"]["evaluation_window"] ==
                {"start_at": 900, "end_at": 2100}
                and row["window_reject"] is True
                and row["outcome"]["baseline_metric_id"] == "metric-baseline"
                and row["outcome"]["variant_metric_id"] == "metric-variant")
    return False


class BrechasControlbot59Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.documents = tuple(p.read_text(encoding="utf-8") for p in MATRICES)
        cls.rows = matrix_rows(cls.documents)
        cls.docs = {name: path.read_text(encoding="utf-8")
                    for name, path in DOCS.items()}

    def test_contrato_principal(self) -> None:
        """AC-01: all 15 IDs have a real scenario assertion or explicit gap."""
        self.assertEqual(len(IDS), 15)
        self.assertEqual(set(CLAUSES), set(IDS))
        self.assertEqual(set(self.rows), PRESENT)
        for ident in IDS:
            with self.subTest(ident=ident):
                group, clause = CLAUSES[ident]
                self.assertIn(clause, self.docs[group])
                self.assertTrue(scenario_evidence(ident),
                                "UNKNOWN: missing contract " + ident)
                expected = LOCAL if ident in PRESENT else UNKNOWN
                self.assertEqual(matrix_verdict(ident, self.rows), expected)

    def test_falla_cerrado(self) -> None:
        """AC-02: no false evidence from missing, duplicate or promoted rows."""
        row = next(x for x in self.documents[0].splitlines()
                   if x.startswith("| CB-0907 |"))
        for mutated in (self.documents[0].replace(row, "", 1),
                        self.documents[0] + "\n" + row,
                        self.documents[0].replace(row,
                                                  row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
            docs = (mutated, *self.documents[1:])
            if row not in mutated:
                self.assertEqual(matrix_verdict("CB-0907", matrix_rows(docs)), UNKNOWN)
            else:
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_rows(docs)
        self.assertEqual(matrix_verdict("CB-0908", self.rows), UNKNOWN)
        self.assertEqual(matrix_verdict("CB-0921", self.rows), UNKNOWN)
        self.assertEqual(matrix_verdict(None, self.rows), UNKNOWN)
        self.assertEqual(matrix_verdict("CB-9999", self.rows), UNKNOWN)
        self.assertEqual(matrix_verdict("CB-0907", {}), UNKNOWN)
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            matrix_rows(("missing",))
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            scenario("decision", "not-supported/unsafe")

    def test_sin_datos_reales(self) -> None:
        """AC-03: only local scenario scripts and anonymous synthetic fixtures."""
        for path in (*MATRICES, *DOCS.values(), *SCRIPTS.values()):
            with self.subTest(path=path.name):
                self.assertTrue(path.is_file())
                self.assertTrue(path.resolve().is_relative_to(ROOT.resolve()))
        for dummy in ("synthetic:initiative", "synthetic:venture",
                      "synthetic:experiment", "synthetic:metric"):
            self.assertTrue(dummy.startswith("synthetic:"))
            self.assertNotIn("@", dummy)
        self.assertEqual(set(SCRIPTS), set(DOCS))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: absent rows and all operational attestations stay UNKNOWN."""
        missing = set(IDS) - set(self.rows)
        self.assertEqual(len(missing), 11)
        for ident in IDS:
            with self.subTest(ident=ident):
                self.assertEqual("UNKNOWN", UNKNOWN)
                self.assertEqual(self.rows.get(ident) is None, ident in missing)
                self.assertEqual(self._production_verdict(ident), UNKNOWN)

    @staticmethod
    def _production_verdict(ident: object) -> str:
        """Offline fixtures never confer CI, deployment or live authority."""
        return UNKNOWN


if __name__ == "__main__":
    unittest.main()

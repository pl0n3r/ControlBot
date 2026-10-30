"""Contraste ejecutable CB-0470..CB-0484 sin activar producción ni runtime."""
from __future__ import annotations

from test_brechas_controlbot_20 import credible_offline_coverage_row

import json
import tempfile
import unittest
from pathlib import Path

from tests.test_github_readonly_projection_pipeline import run as pipeline
from tests.test_github_readonly_projection_writer import (
    canonical_snapshot, run as writer,
)
from tests.test_github_readonly_runtime_boundary import grant, run as boundary
from tests.test_github_readonly_installation_source import page
from tests.test_github_workflow_intent_executor import candidate, execute

ROOT = Path(__file__).resolve().parents[1]
CLAUSES = {f"CB-{n:04d}" for n in range(470, 485)}
MATRICES = (
    ROOT / "docs/matriz-cobertura.md",
    ROOT / "docs/matriz-cobertura-parte-02.md",
)


def rows_by_clause():
    rows = {}
    for matrix in MATRICES:
        for line in matrix.read_text(encoding="utf-8").splitlines():
            if not line.startswith("| CB-"):
                continue
            cells = [item.strip() for item in line.strip().strip("|").split("|")]
            if cells[0] in CLAUSES:
                if cells[0] in rows:
                    raise AssertionError(f"Cláusula duplicada: {cells[0]}")
                rows[cells[0]] = cells
    return rows


def safe(result):
    """Todos los resultados PHP preservan el veto operativo."""
    for field in ("runtime_authorized", "production_verified", "execute_actions"):
        assert result[field] is False, field
    assert result["authority"] == "unchanged"
    return True


class BrechasControlbot32Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: predicado por cada una de las quince filas de la matriz."""
        rows = rows_by_clause()
        self.assertEqual(set(rows), CLAUSES)
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as path:
            off = pipeline(path)
            active = pipeline(path, enabled=True)
            truncated = pipeline(path, enabled=True, source_mode="truncated")
            self.assertTrue(safe(off["result"]))
            self.assertTrue(safe(active["result"]))
            self.assertTrue(safe(truncated["result"]))
            with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as wr:
                disabled_writer = writer(wr)
                accepted = writer(wr, enabled=True)
                stale = writer(
                    wr, enabled=True, snapshots=[canonical_snapshot(observed=600)]
                )
            without_grant = boundary()
            with_grant = boundary(g=grant(), pages=[
                page(1, next_page=2, items=[{"id": 3, "state": "open"}]),
                page(2, items=[{"id": 5, "state": "closed"}]),
            ])
            limited = boundary(g=grant(), pages=[{**page(), "status": 429}])
            dispatch = execute(candidate())
            frozen = execute(candidate("project.freeze"))
            audit = json.dumps(with_grant["audit"])
            verdicts = {
                "CB-0470": active["result"]["status"] == "CURRENT"
                    and truncated["result"]["status"] == "UNKNOWN"
                    and active["file"] == truncated["file"],
                "CB-0471": safe(active["result"]) and safe(off["result"]),
                # La autorización histórica no certifica permiso operativo vigente.
                "CB-0472": credible_offline_coverage_row(rows["CB-0472"])
                    and active["result"]["production_verified"] is False,
                "CB-0473": disabled_writer["result"]["status"] == "UNKNOWN"
                    and disabled_writer["calls"] == 0
                    and accepted["calls"] == 2
                    and json.loads(accepted["file"])["version"] == 1,
                "CB-0474": stale["result"]["status"] == "UNKNOWN"
                    and stale["file"] == accepted["file"],
                "CB-0475": all(not accepted["result"][x] for x in (
                    "runtime_authorized", "production_verified", "execute_actions"
                )),
                # La decisión offline no sustituye aprobación de go-live.
                "CB-0476": without_grant["out"]["status"] == "UNKNOWN"
                    and without_grant["calls"] == [],
                "CB-0477": with_grant["out"]["status"] == "CURRENT"
                    and len(with_grant["calls"]) == 2
                    and {c["method"] for c in with_grant["calls"]} == {"GET"},
                "CB-0478": without_grant["out"]["fixture_projection"] is None
                    and without_grant["calls"] == [],
                "CB-0479": with_grant["out"]["reason"] == "fixture_only"
                    and with_grant["out"]["fixture_projection"]["pages"] == 2
                    and limited["out"]["status"] == "UNKNOWN"
                    and limited["out"]["fixture_projection"] is None,
                "CB-0480": safe(with_grant["out"]) and safe(limited["out"])
                    and len(with_grant["audit"]) <= 32
                    and all(x not in audit for x in ("private", "token", "email")),
                "CB-0481": with_grant["out"]["fixture_projection"]["items_count"] == 2
                    and all(c["mode"] == "offline_test_only" for c in with_grant["calls"]),
                "CB-0482": dispatch["decision"] == "deny"
                    and dispatch["execution"] is False
                    and dispatch["runtime_authorized"] is False,
                "CB-0483": dispatch["fake_effect"]["status"] == "simulated"
                    and len(dispatch["fake_state"]["dispatches"]) == 1
                    and dispatch["decision"] == "deny",
                "CB-0484": frozen["fake_effect"]["status"] == "simulated"
                    and frozen["fake_state"]["frozen"] is True
                    and frozen["audit"][0]["reversible"] is True,
            }
            self.assertEqual(set(verdicts), CLAUSES)
            for clause, passed in verdicts.items():
                with self.subTest(clause=clause):
                    self.assertTrue(passed, clause)

    def test_falla_cerrado(self):
        """AC-02: fuentes hostiles, carreras y errores no validan ni borran estado."""
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as path:
            old = pipeline(path, enabled=True)["file"]
            self.assertIsNotNone(old)
            for patch in (
                {"source_mode": "forbidden"}, {"source_mode": "timeout"},
                {"http_mode": "limited"}, {"http_mode": "timeout"},
                {"source_mode": "truncated"}, {"race": True},
                {"snapshots": [canonical_snapshot(observed=1000, sha="b" * 40)]},
                {"expected": []}, {"now": 1150},
            ):
                with self.subTest(patch=patch):
                    result = pipeline(path, enabled=True, **patch)
                    self.assertTrue(safe(result["result"]))
                    self.assertEqual(result["result"]["status"], "UNKNOWN")
                    self.assertEqual(result["file"], old)
        for bad_grant in (None, {**grant(), "owner_verified": False},
                          {**grant(), "sha": "b" * 40}):
            denied = boundary(g=bad_grant)
            self.assertEqual(denied["out"]["status"], "UNKNOWN")
            self.assertIsNone(denied["out"]["fixture_projection"])
            self.assertEqual(denied["calls"], [])
        for status in (403, 429):
            denied = boundary(g=grant(), pages=[{**page(), "status": status}])
            self.assertEqual(denied["out"]["status"], "UNKNOWN")
            self.assertTrue(safe(denied["out"]))
        invalid = candidate()
        invalid["intent"]["params"]["workflow_ref"] = "deploy.yml"
        result = execute(invalid)
        self.assertEqual(result["fake_effect"]["status"], "denied")
        self.assertEqual(result["audit"], [])
        self.assertEqual(result["fake_state"]["dispatches"], [])
        self.assertIs(result["runtime_authorized"], False)

    def test_sin_datos_reales(self):
        """AC-03: sólo temporales y PHP con closures sintéticas, nunca HTTP real."""
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as path:
            result = pipeline(path, enabled=True)
            self.assertTrue(safe(result["result"]))
            self.assertEqual(json.loads(result["file"])["version"], 1)
            self.assertTrue(Path(path, "projection.json").is_file())
        self.assertFalse(Path(path).exists())
        for filename in (
            "src/GitHubReadOnlyProjectionPipeline.php",
            "src/GitHubReadOnlyProjectionWriter.php",
            "src/GitHubReadOnlyRuntimeBoundary.php",
            "src/GitHubWorkflowIntentExecutor.php",
        ):
            source = (ROOT / filename).read_text(encoding="utf-8")
            for banned in ("curl_exec(", "stream_socket_client(", "fsockopen("):
                with self.subTest(filename=filename, banned=banned):
                    self.assertNotIn(banned, source)
        allowed = boundary(g=grant())
        self.assertTrue(safe(allowed["out"]))
        self.assertNotIn("private", json.dumps(allowed["audit"]))
        fake = execute(candidate("project.freeze"))
        self.assertIs(fake["runtime_authorized"], False)
        self.assertIs(fake["execution"], False)
        self.assertEqual(fake["decision"], "deny")

    def test_estados_unknown_explicitos(self):
        """AC-04: ausencia de evidencia productiva no se convierte en GREEN."""
        rows = rows_by_clause()
        self.assertEqual(set(rows), CLAUSES)
        for clause, row in rows.items():
            with self.subTest(clause=clause):
                self.assertTrue(credible_offline_coverage_row(row))
                self.assertIn("UNKNOWN", row[4])
                self.assertIn("live=UNKNOWN", row[4])
                self.assertIn("operando=UNKNOWN", row[4])
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as path:
            off = pipeline(path)
            self.assertEqual(off["result"]["status"], "UNKNOWN")
            self.assertEqual(off["source_calls"], 0)
            self.assertEqual(off["http_calls"], 0)
        denied = boundary()
        self.assertEqual(denied["out"]["status"], "UNKNOWN")
        self.assertIsNone(denied["out"]["fixture_projection"])
        for outcome in ("failure", "ambiguous"):
            payload = candidate()
            payload["fixture"]["outcome"] = outcome
            result = execute(payload)
            self.assertEqual(result["fake_effect"]["status"],
                             "failed" if outcome == "failure" else "ambiguous")
            self.assertIs(result["runtime_authorized"], False)
            self.assertEqual(result["audit"], [])


if __name__ == "__main__":
    unittest.main()

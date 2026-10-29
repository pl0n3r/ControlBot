#!/usr/bin/env python3
"""#1212: cobertura conductual PHP offline de CB-0472..CB-0488.

No hay accesos a GitHub, runtime, hosting, secretos, red ni datos reales.
Los helpers importados invocan clases PHP reales con fixtures sintéticos.
"""
from __future__ import annotations

import copy
import json
from pathlib import Path
import re
import shutil
import tempfile
import unittest

from tests.test_github_readonly_projection_writer import (
    canonical_snapshot, manifest_for, run as projection_run,
)
from tests.test_github_readonly_runtime_boundary import (
    grant, page, run as boundary_run,
)
from tests.test_github_workflow_intent_executor import (
    candidate, execute,
)
from tests.test_global_search import scenario

ROOT = Path(__file__).resolve().parents[1]
ALL_IDS = {
    "CB-0472", "CB-0473", "CB-0474", "CB-0475", "CB-0476",
    "CB-0477", "CB-0478", "CB-0479", "CB-0480", "CB-0481",
    "CB-0482", "CB-0483", "CB-0484", "CB-0485", "CB-0487", "CB-0488",
}
# C: no sustituir una decisión del dueño ni la autoridad de CI con un test.
NON_OFFLINE = {
    "CB-0476": (
        "C", "Autorización histórica #926/A y journal: no es función PHP verificable",
    ),
    "CB-0481": (
        "C", "CI, revisión y squash V3 son gates externos, no comportamiento de la clase",
    ),
}
OFFLINE = ALL_IDS - set(NON_OFFLINE)


def safe_flags(result: dict) -> bool:
    return all(result[key] is False for key in (
        "runtime_authorized", "production_verified", "execute_actions",
    ))


class CoberturaControlbot29Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 14 comprobaciones con ejecuciones PHP reales y sintéticas."""
        self.assertIsNotNone(shutil.which("php"), "PHP CLI real es obligatorio")
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as directory:
            off = projection_run(directory)
            on = projection_run(directory, enabled=True)
            truncated = canonical_snapshot()
            truncated["repositories"][0]["checks"]["truncated"] = True
            rejected = projection_run(directory, [truncated], enabled=True)
            published = json.loads(on["file"])
            stable = on["file"] == rejected["file"]

        two_pages = boundary_run(
            g=grant(),
            pages=[
                page(1, next_page=2, items=[{"id": 3, "state": "open"}]),
                page(2, items=[{"id": 5, "state": "closed"}]),
            ],
        )
        no_grant = boundary_run(g=None)
        dispatched = execute(candidate())
        frozen = execute(candidate("project.freeze"))
        exact = scenario("number")
        freshness = scenario("freshness")
        freshness_by_repo = {
            row["repo"]: row["freshness"] for row in freshness["items"]
        }
        checks = {
            "CB-0472": (
                on["result"]["status"] == "CURRENT"
                and safe_flags(on["result"])
                and published["version"] == 1
                and published["projects"][0]["project_id"] == "factory-control"
            ),
            "CB-0473": (
                off["result"]["status"] == "UNKNOWN"
                and off["calls"] == 0
                and off["file"] is None
                and on["calls"] == 2
                and on["result"]["status"] == "CURRENT"
            ),
            "CB-0474": (
                rejected["result"]["status"] == "UNKNOWN"
                and stable
                and rejected["result"]["reason"] == "partial_or_stale"
            ),
            "CB-0475": (
                safe_flags(off["result"])
                and safe_flags(on["result"])
                and safe_flags(rejected["result"])
            ),
            "CB-0477": (
                two_pages["out"]["status"] == "CURRENT"
                and [row["page"] for row in two_pages["calls"]] == [1, 2]
                and all(row["method"] == "GET" for row in two_pages["calls"])
                and all(row["mode"] == "offline_test_only"
                        for row in two_pages["calls"])
            ),
            "CB-0478": (
                no_grant["out"]["status"] == "UNKNOWN"
                and no_grant["calls"] == []
                and no_grant["out"]["fixture_projection"] is None
            ),
            "CB-0479": (
                two_pages["out"]["reason"] == "fixture_only"
                and two_pages["out"]["fixture_projection"]["pages"] == 2
                and two_pages["out"]["fixture_projection"]["items_count"] == 2
            ),
            "CB-0480": (
                safe_flags(two_pages["out"])
                and safe_flags(no_grant["out"])
                and two_pages["out"]["authority"] == "unchanged"
                and len(two_pages["audit"]) == 1
                and set(two_pages["audit"][0]) == {"status", "reason"}
            ),
            "CB-0482": (
                dispatched["decision"] == "deny"
                and dispatched["execution"] is False
                and dispatched["runtime_authorized"] is False
            ),
            "CB-0483": (
                dispatched["fake_effect"]["status"] == "simulated"
                and dispatched["fake_state"]["dispatches"] == [{
                    "workflow": "ci.yml", "ref": "main",
                }]
                and dispatched["decision"] == "deny"
            ),
            "CB-0484": (
                frozen["fake_effect"]["status"] == "simulated"
                and frozen["fake_state"]["frozen"] is True
                and frozen["audit"][0]["reversible"] is True
                and len(frozen["ledger"]) == 1
                and re.fullmatch(r"[a-f0-9]{64}",
                                 frozen["audit"][0]["intent_hash"]) is not None
            ),
            "CB-0485": (
                dispatched["execution"] is False
                and frozen["execution"] is False
                and dispatched["runtime_authorized"] is False
                and frozen["runtime_authorized"] is False
            ),
            "CB-0487": (
                exact["total"] == 1
                and exact["items"][0]["number_or_id"] == 78
                and exact["items"][0]["canonical_url"].endswith("/issues/78")
            ),
            "CB-0488": (
                freshness_by_repo["pl0n3r/factory"] == "stale"
                and freshness_by_repo["pl0n3r/FactoryRunner"] == "unavailable"
                and freshness_by_repo["pl0n3r/ControlBot"] == "fresh"
            ),
        }
        self.assertEqual(set(checks), OFFLINE)
        self.assertEqual(OFFLINE | set(NON_OFFLINE), ALL_IDS)
        for key, passed in checks.items():
            with self.subTest(row=key):
                self.assertTrue(passed, key + " sin evidencia PHP real")

    def test_falla_cerrado(self) -> None:
        """AC-02: entradas parciales, caducadas, sin grants o con replay."""
        with tempfile.TemporaryDirectory(prefix="cb-projection-fixture-") as directory:
            original = projection_run(directory, enabled=True)["file"]
            self.assertIsNotNone(original)
            good = canonical_snapshot()
            altered = canonical_snapshot(sha="b" * 40)
            truncated = copy.deepcopy(good)
            truncated["repositories"][0]["checks"]["truncated"] = True
            invalid = [
                {"snapshots": [good], "manifest": manifest_for([altered])},
                {"snapshots": [good], "race": True},
                {"snapshots": [good, good]},
                {"snapshots": [canonical_snapshot(observed=600)]},
                {"snapshots": [canonical_snapshot(observed=1100)]},
                {"snapshots": [canonical_snapshot(sha="bad-sha")]},
                {"snapshots": [truncated]},
                {"snapshots": [good], "omit_witness": True},
            ]
            for case in invalid:
                with self.subTest(writer=sorted(case)):
                    out = projection_run(directory, enabled=True, **case)
                    self.assertEqual("UNKNOWN", out["result"]["status"])
                    self.assertEqual(original, out["file"])
                    self.assertTrue(safe_flags(out["result"]))

        for g in [None, {}, {**grant(), "owner_verified": False},
                  {**grant(), "owner": "other"},
                  {**grant(), "expires_at": 1050}]:
            with self.subTest(grant=g):
                out = boundary_run(g=g)
                self.assertEqual("UNKNOWN", out["out"]["status"])
                self.assertIsNone(out["out"]["fixture_projection"])
                self.assertEqual([], out["calls"])
                self.assertTrue(safe_flags(out["out"]))
        for pages in [[{**page(), "status": 403}],
                      [{**page(), "status": 429}],
                      [{**page(), "truncated": True}],
                      [{**page(), "complete": False}],
                      [page(1, next_page=2)]]:
            with self.subTest(pages=pages):
                out = boundary_run(g=grant(), pages=pages)
                self.assertEqual("UNKNOWN", out["out"]["status"])
                self.assertIsNone(out["out"]["fixture_projection"])
                self.assertTrue(safe_flags(out["out"]))

        first = execute(candidate("project.freeze"))
        replay = candidate("project.freeze")
        replay["state"] = first["fake_state"]
        replay["audit"] = first["audit"]
        replay["ledger"] = first["ledger"]
        again = execute(replay)
        self.assertTrue(again["replayed"])
        self.assertEqual(first["audit"], again["audit"])
        self.assertEqual(first["fake_state"], again["fake_state"])
        conflict = copy.deepcopy(replay)
        conflict["intent"]["params"]["reason_ref"] = "controlbot:fixture/other"
        denied = execute(conflict)
        self.assertEqual("idempotency_conflict", denied["fake_effect"]["reason"])
        self.assertEqual(first["fake_state"], denied["fake_state"])
        no_owner = candidate()
        no_owner["fixture"]["approval"]["approved"] = False
        self.assertEqual("denied", execute(no_owner)["fake_effect"]["status"])
        self.assertEqual([], execute(no_owner)["audit"])
        restricted = scenario("permissions")
        self.assertNotIn(120, [row["number_or_id"] for row in restricted["items"]])
        self.assertNotIn(121, [row["number_or_id"] for row in restricted["items"]])
        redacted = scenario("sanitize")
        self.assertTrue(redacted["network_rejected"])
        self.assertTrue(redacted["dot_rejected"])
        self.assertIn("redacted", json.dumps(redacted["search"]).lower())

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures, rutas y resultados nunca conceden permisos live."""
        self.assertEqual(16, len(ALL_IDS))
        self.assertEqual(14, len(OFFLINE))
        self.assertEqual(set(NON_OFFLINE), {"CB-0476", "CB-0481"})
        self.assertTrue(all(kind == "C" and reason
                            for kind, reason in NON_OFFLINE.values()))
        self.assertTrue(all((ROOT / path).is_file() for path in (
            "src/GitHubReadOnlyProjectionWriter.php",
            "src/GitHubReadOnlyRuntimeBoundary.php",
            "src/GitHubWorkflowIntentExecutor.php",
            "src/GlobalSearchCore.php",
        )))
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source,
                            r"\b(?:ghp_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{16,})")
        self.assertNotIn("curl_" + "exec(", source)
        self.assertNotIn("requests." + "get(", source)
        self.assertNotIn("https:" + "//", source)
        result = execute(candidate())
        self.assertIs(result["runtime_authorized"], False)
        self.assertIs(result["execution"], False)
        self.assertEqual("deny", result["decision"])
        self.assertNotIn("fixture_proof", result)

    def test_validar_exact_head(self) -> None:
        """AC-04: el workflow exige HEAD del PR; CI remoto es autoridad."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("pull_request:", workflow)
        self.assertIn("github.event.pull_request.head.sha", workflow)
        self.assertNotIn("continue-on-error: true", workflow)


if __name__ == "__main__":
    unittest.main()

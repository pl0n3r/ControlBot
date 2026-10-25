#!/usr/bin/env python3
"""Pruebas agregadas del contrato read-only del GitHub Control Plane.

La entrada es un fixture sintético con la misma forma validada por PHP; NO
acredita que se hayan consultado repositorios o producción reales.
"""
from __future__ import annotations

import copy
import runpy
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VIEW_TESTS = runpy.run_path(str(ROOT / "tests" / "test_github_project_view.py"))
UI_TESTS = runpy.run_path(str(ROOT / "tests" / "test_github_project_ui.py"))
project = VIEW_TESTS["project"]
canonical_snapshot = VIEW_TESTS["canonical_snapshot"]
render = UI_TESTS["render"]


def project_snapshot(observed_at: int = 900) -> dict:
    snapshot = canonical_snapshot(observed_at)
    sibling = copy.deepcopy(snapshot["repositories"][0])
    sibling["repository_id"] = "factory"
    sibling["repository"] = "pl0n3r/Factory"
    sibling["source_ref"] = "https://github.com/pl0n3r/Factory"
    sibling["main_sha"] = "c" * 40
    sibling["checks"]["items"][0].update(
        {"name": "Validar", "status": "completed", "conclusion": "failure"}
    )
    sibling["pull_requests"]["items"][0]["number"] = 31
    sibling["issues"]["items"][0]["number"] = 34
    sibling["latest_release"]["tag_name"] = "v1.0.40"
    snapshot["repositories"].append(sibling)
    return snapshot


class GitHubControlPlaneTests(unittest.TestCase):
    def test_project_view_aggregates_real_repo_ci_pr_issue_and_release_state(self):
        """Integra los contratos PHP de repositorios reales con datos sintéticos."""
        view = project(project_snapshot())
        self.assertEqual(view["state"], "current")
        self.assertEqual(len(view["repositories"]), 2)
        self.assertEqual(
            [row["repository"] for row in view["repositories"]],
            ["pl0n3r/ControlBot", "pl0n3r/Factory"],
        )
        self.assertEqual(
            [row["source_ref"] for row in view["repositories"]],
            [
                "https://github.com/pl0n3r/ControlBot",
                "https://github.com/pl0n3r/Factory",
            ],
        )
        self.assertEqual(view["repositories"][1]["main_sha"], "c" * 40)
        self.assertEqual(
            view["repositories"][1]["checks"]["items"][0]["conclusion"],
            "failure",
        )
        self.assertEqual(view["repositories"][1]["pull_requests"]["items"][0]["number"], 31)
        self.assertEqual(view["repositories"][1]["issues"]["items"][0]["number"], 34)
        self.assertEqual(view["repositories"][1]["latest_release"]["tag_name"], "v1.0.40")
        html = render(view)
        for expected in (
            "pl0n3r/ControlBot", "pl0n3r/Factory", "v1.0.40",
            "failure", "Pull requests", "#31", "Issues", "#34",
        ):
            self.assertIn(expected, html)
        self.assertNotIn("HEALTHY", html)
        self.assertNotIn("PRODUCTION GREEN", html)

    def test_stale_truncated_and_invalid_sources_fail_closed_across_view_and_ui(self):
        stale = project(project_snapshot(observed_at=500))
        self.assertEqual(stale["state"], "unknown")
        self.assertEqual(stale["freshness"], "stale")
        self.assertIn("UNKNOWN / STALE", render(stale))

        truncated = project_snapshot()
        truncated["repositories"][1]["issues"]["truncated"] = True
        view = project(truncated)
        self.assertEqual(view["state"], "unknown")
        self.assertEqual(view["repositories"][1]["state"], "unknown")
        self.assertIn("Truncado: sí · UNKNOWN", render(view))

        invalid = project_snapshot()
        invalid["repositories"][1]["source_ref"] = "https://wrong.invalid/no"
        unknown = project(invalid)
        self.assertEqual(unknown["state"], "unknown")
        self.assertEqual(unknown["repositories"], [])
        self.assertNotIn("pl0n3r/Factory", render(unknown))


if __name__ == "__main__":
    unittest.main()

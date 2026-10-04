#!/usr/bin/env python3
"""Regression: Factory 1.0.24 policy caller permission envelope."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CALLER = ROOT / ".github/workflows/politica.yml"


class PolicyCallerPermissionsTests(unittest.TestCase):
    def _text(self) -> str:
        return CALLER.read_text(encoding="utf-8")

    @staticmethod
    def _direct_mapping(
        lines: list[str],
        header_index: int,
        child_indent: int,
    ) -> dict[str, str]:
        mapping: dict[str, str] = {}
        for line in lines[header_index + 1 :]:
            if not line.strip():
                continue
            indent = len(line) - len(line.lstrip())
            if indent < child_indent:
                break
            if indent != child_indent:
                continue
            match = re.fullmatch(r"\s*([a-z-]+):\s*([a-z]+)\s*", line)
            if match is None:
                break
            mapping[match.group(1)] = match.group(2)
        return mapping

    def _permission_maps(
        self,
    ) -> tuple[dict[str, str], dict[str, dict[str, str]]]:
        lines = self._text().splitlines()

        global_index = next(
            index
            for index, line in enumerate(lines)
            if line == "permissions:"
        )
        global_permissions = self._direct_mapping(lines, global_index, 2)

        jobs_index = next(
            index for index, line in enumerate(lines) if line == "jobs:"
        )
        job_permissions: dict[str, dict[str, str]] = {}
        current_job: str | None = None
        for index, line in enumerate(lines[jobs_index + 1 :], start=jobs_index + 1):
            job_match = re.fullmatch(r"  ([a-z0-9-]+):\s*", line)
            if job_match is not None:
                current_job = job_match.group(1)
                continue
            if current_job is not None and line == "    permissions:":
                job_permissions[current_job] = self._direct_mapping(
                    lines,
                    index,
                    6,
                )

        return global_permissions, job_permissions

    def test_policy_caller_grants_exact_reusable_permissions(self) -> None:
        _, jobs = self._permission_maps()
        self.assertEqual(
            jobs["politica"],
            {
                "contents": "read",
                "pull-requests": "read",
                "issues": "write",
                "checks": "read",
            },
        )

    def test_policy_caller_does_not_expand_other_permissions(self) -> None:
        global_permissions, job_permissions = self._permission_maps()
        self.assertEqual(
            global_permissions,
            {
                "contents": "read",
                "pull-requests": "read",
            },
        )
        self.assertEqual(
            job_permissions,
            {
                "politica": {
                    "contents": "read",
                    "pull-requests": "read",
                    "issues": "write",
                    "checks": "read",
                },
            },
        )

    def test_policy_caller_keeps_factory_v1_and_existing_contract(self) -> None:
        text = self._text()
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/politica.yml@v1",
            text,
        )
        self.assertIn(
            "if: github.event.pull_request.draft == false",
            text,
        )
        self.assertIn(
            "types: [opened, synchronize, reopened, edited, ready_for_review]",
            text,
        )
        self.assertIn(
            "group: ${{ github.workflow }}-${{ "
            "github.event.pull_request.number || github.ref || github.run_id }}",
            text,
        )
        self.assertIn("cancel-in-progress: true", text)


if __name__ == "__main__":
    unittest.main()

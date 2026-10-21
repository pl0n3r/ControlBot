"""Contrato fail-closed del caller ControlBot del observador GET-only V3."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
CALLER = ROOT / ".github/workflows/observador-reservas-consumidor.yml"


class ObservadorReservasConsumidorTests(unittest.TestCase):
    def test_published_reusable_and_local_events_are_explicit(self):
        text = CALLER.read_text(encoding="utf-8")
        self.assertIn("name: Observación local de reservas V3 (Fase A, solo lectura)", text)
        self.assertRegex(text, r"(?m)^on:\n  schedule:\n    - cron: '\*/10 \* \* \* \*'\n  workflow_dispatch:$")
        self.assertIn("uses: pl0n3r/Factory/.github/workflows/observador-reservas-consumidor.yml@v1", text)
        self.assertEqual(1, len(re.findall(r"(?m)^    uses: ", text)))
        for forbidden in ("pull_request:", "pull_request_target:", "issue_comment:",
                          "workflow_run:", "repository_dispatch:", "push:",
                          "secrets:", "run:", "with:"):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, text)

    def test_permissions_and_schedule_stay_read_only_and_non_mutating(self):
        text = CALLER.read_text(encoding="utf-8")
        self.assertEqual(2, len(re.findall(r"(?m)^\s*permissions:$", text)))
        permissions = re.findall(r"(?m)^( {2}| {6})(contents|issues|pull-requests|checks|actions): ([a-z]+)$", text)
        self.assertEqual(10, len(permissions))
        for indent in ("  ", "      "):
            self.assertEqual({"contents", "issues", "pull-requests", "checks", "actions"},
                             {name for spaces, name, value in permissions if spaces == indent and value == "read"})
        self.assertFalse(any(value != "read" for _, _, value in permissions))
        self.assertNotRegex(text, r"(?m)^\s*[^#\n]+: write$")
        self.assertIn("group: lease-consumer-caller-${{ github.repository_id }}", text)
        self.assertIn("cancel-in-progress: false", text)
        self.assertIn("name: Observar reservas locales, sin liberar", text)
        self.assertNotIn("FACTORY_LEASE_AUTO_RELEASE_ENABLED: 'true'", text)
        self.assertNotIn("gh api", text)


if __name__ == "__main__":
    unittest.main()

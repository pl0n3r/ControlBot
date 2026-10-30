#!/usr/bin/env python3
"""Banda de salud del dashboard: el estado manda, con su palabra, y el pulso solo corre sin «reducir movimiento»."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

SOURCE = (Path(__file__).resolve().parents[1] / "src" / "DashboardUi.php").read_text(encoding="utf-8")
CSS = SOURCE[SOURCE.index(".health-core {"):SOURCE.index(".dashboard-grid {")]


class DashboardHealthBannerTests(unittest.TestCase):
    def test_each_health_state_has_its_own_token_color_and_the_unknown_state_stays_muted(self) -> None:
        for state, token in (("healthy", "--green"), ("warning", "--amber"), ("critical", "--red")):
            with self.subTest(state=state):
                self.assertIn(f".health-{state} {{ --state: var({token}); }}", CSS)
        self.assertIn("--state: var(--muted);", CSS)

    def test_banner_colors_come_only_from_tokens(self) -> None:
        self.assertEqual(re.findall(r"#[0-9a-fA-F]{3,6}\b", CSS), [])

    def test_the_status_word_is_text_and_not_only_color(self) -> None:
        self.assertIn("<strong>' . self::healthLabel($health) . '</strong>", SOURCE)
        self.assertIn("text-transform: uppercase;", CSS.split(".health-copy strong", 1)[1])

    def test_pulse_runs_only_when_motion_is_allowed_and_the_block_stays_flat(self) -> None:
        pulse = CSS.index("animation: core-pulse")
        self.assertIn("prefers-reduced-motion: no-preference", CSS[:pulse].rsplit("@media", 1)[1])
        self.assertNotIn("box-shadow", CSS)
        self.assertNotIn("text-shadow", CSS)


if __name__ == "__main__":
    unittest.main()

"""Contract tests for the dashboard's health banner (offline, CSS only)."""

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(scenario: str) -> str:
    return subprocess.run(
        ["php", str(ROOT / "tests/dashboard_ui_scenarios.php"), scenario],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=True,
    ).stdout


def banner_styles(html: str) -> str:
    return html.split(".health-core {", 1)[1].split(".dashboard-grid {", 1)[0]


class DashboardHealthBannerTests(unittest.TestCase):
    def test_each_health_state_has_its_own_token_color_and_the_unknown_state_stays_muted(self):
        css = banner_styles(render("empty"))
        for health, token in (
            ("healthy", "green"),
            ("warning", "amber"),
            ("critical", "red"),
            ("unknown", "muted"),
        ):
            self.assertRegex(
                css,
                rf"\.health-{health}\s*\{{\s*--health-accent:\s*var\(--{token}\);",
            )
        self.assertIn("border-left: 6px solid var(--health-accent)", css)
        self.assertIn('class="health-core health-unknown"', render("empty"))
        self.assertIn('class="health-core health-unknown"', render("invalid-health"))

    def test_banner_colors_come_only_from_tokens(self):
        css = banner_styles(render("empty"))
        self.assertNotRegex(css, r"#[\da-fA-F]{3,8}\b|rgba?\(|hsla?\(")
        self.assertIn("background: var(--panel)", css)
        self.assertIn("border: 1px solid var(--line)", css)
        self.assertIn("border-color: var(--health-accent)", css)
        self.assertIn("color: var(--health-accent)", css)

    def test_the_status_word_is_text_and_not_only_color(self):
        for scenario, health, label in (
            ("escape", "healthy", "estable"),
            ("real", "warning", "atención"),
            ("empty", "unknown", "desconocido"),
        ):
            html = render(scenario)
            self.assertIn(f'health-core health-{health}', html)
            self.assertIn(f'<strong>{label}</strong>', html)
        html = render("empty")
        self.assertIn(".health-copy strong {\n  color: var(--health-accent);", html)
        self.assertIn("font-size: clamp(2.5rem, 11vw, 5rem)", html)

    def test_pulse_runs_only_when_motion_is_allowed_and_the_block_stays_flat(self):
        css = banner_styles(render("empty"))
        self.assertIn("@media (prefers-reduced-motion: no-preference)", css)
        outside_motion = css.split("@media (prefers-reduced-motion: no-preference)", 1)[0]
        self.assertNotIn("animation:", outside_motion)
        self.assertIn("animation: health-pulse", css)
        self.assertIn("@keyframes health-pulse", css)
        html = render("empty")
        self.assertIn("@media (prefers-reduced-motion: reduce)", html)
        self.assertNotIn("box-shadow", html)
        self.assertNotIn("text-shadow", html)


if __name__ == "__main__":
    unittest.main()

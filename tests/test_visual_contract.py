import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(script: str, scenario: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / script), scenario],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return result.stdout


def css_from(html: str) -> str:
    start = html.index("<style>") + len("<style>")
    end = html.index("</style>", start)
    return html[start:end]


def srgb_channel(value: int) -> float:
    channel = value / 255
    return channel / 12.92 if channel <= 0.04045 else ((channel + 0.055) / 1.055) ** 2.4


def luminance(hex_color: str) -> float:
    red, green, blue = (
        int(hex_color[index:index + 2], 16)
        for index in (1, 3, 5)
    )
    return (
        0.2126 * srgb_channel(red)
        + 0.7152 * srgb_channel(green)
        + 0.0722 * srgb_channel(blue)
    )


def contrast_ratio(first: str, second: str) -> float:
    high, low = sorted((luminance(first), luminance(second)), reverse=True)
    return (high + 0.05) / (low + 0.05)


def canonical_tokens() -> dict[str, str]:
    source = (ROOT / "src" / "UiTheme.php").read_text(encoding="utf-8")
    return dict(re.findall(r"--([a-z-]+):\s*(#[0-9a-fA-F]{6});", source))


def css_min_width_values(css: str) -> list[str]:
    return [
        value.strip()
        for value in re.findall(r"(?<!\()min-width\s*:\s*([^;}\n]+)", css)
    ]


class VisualContractTests(unittest.TestCase):
    def test_canonical_palette_meets_wcag_aa(self):
        tokens = canonical_tokens()
        for required in ("bg", "panel", "text", "muted", "cyan", "green", "amber", "red"):
            self.assertIn(required, tokens)

        for foreground in ("text", "muted", "cyan", "green", "amber", "red"):
            for background in ("bg", "panel"):
                with self.subTest(foreground=foreground, background=background):
                    self.assertGreaterEqual(
                        contrast_ratio(tokens[foreground], tokens[background]),
                        4.5,
                    )

    def test_decisions_are_iphone_safe(self):
        html = render("decision_ui_scenarios.php", "ready")
        css = css_from(html)
        self.assertIn(
            'name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"',
            html,
        )
        self.assertIn(".shell { width: min(100%, 1120px);", css)
        self.assertIn("min-height: 52px", css)
        self.assertIn(":focus-visible", css)
        self.assertIn(".meta > span { min-width: 0; overflow-wrap: anywhere; }", css)
        self.assertIn("overflow-wrap: anywhere", css)
        self.assertIn("@media (min-width: 760px)", css)
        self.assertTrue(all(value == "0" for value in css_min_width_values(css)))

    def test_dashboard_is_iphone_safe(self):
        html = render("dashboard_ui_scenarios.php", "empty")
        css = css_from(html)
        self.assertIn(
            'name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"',
            html,
        )
        self.assertIn(
            ".dashboard-grid { display: grid; gap: 16px; grid-template-columns: 1fr; }",
            css,
        )
        self.assertIn(
            ".metric-list span { min-width: 0; color: var(--muted); overflow-wrap: anywhere; }",
            css,
        )
        self.assertIn(
            '.metric-list strong { min-width: 0; text-align: right; font-family: "JetBrains Mono", ui-monospace, monospace; overflow-wrap: anywhere; }',
            css,
        )
        self.assertIn("@media (min-width: 760px)", css)
        self.assertIn("@media (min-width: 1040px)", css)
        self.assertTrue(all(value == "0" for value in css_min_width_values(css)))

    def test_both_surfaces_respect_reduced_motion(self):
        for script, scenario in (
            ("decision_ui_scenarios.php", "ready"),
            ("dashboard_ui_scenarios.php", "empty"),
        ):
            with self.subTest(script=script):
                css = css_from(render(script, scenario))
                self.assertIn("@media (prefers-reduced-motion: reduce)", css)
                self.assertIn("animation: none !important", css)
                self.assertIn("transition: none !important", css)
                self.assertIn("scroll-behavior: auto !important", css)


if __name__ == "__main__":
    unittest.main()

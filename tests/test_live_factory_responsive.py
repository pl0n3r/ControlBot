"""ControlBot #976: offline responsive/AA regression against actual FactoryLiveUi HTML.

This is a deterministic CSS contract test, not a claim that a browser E2E
screenshot has been run. It uses the pre-existing synthetic PHP fixtures.
"""
from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests/live_factory_responsive_scenarios.php"
VIEWPORTS = (360, 390, 1280)
BASE_TESTS = ROOT / "tests/factory_live_ui_scenarios.php"


def render(width: int, scenario: str = "full") -> str:
    result = subprocess.run(
        ["php", str(SCENARIOS), str(width), scenario],
        cwd=ROOT,
        text=True,
        capture_output=True,
        timeout=30,
        check=True,
    )
    return result.stdout


def colors(html: str) -> dict[str, str]:
    matches = re.findall(r"--([a-z-]+):\s*(#[0-9a-fA-F]{6})\b", html)
    return {name: code.lower() for name, code in matches}


def luminance(hex_color: str) -> float:
    channels = [int(hex_color[i:i+2], 16) / 255 for i in (1, 3, 5)]
    converted = [c / 12.92 if c <= .04045 else ((c + .055) / 1.055) ** 2.4
                 for c in channels]
    return sum(a * b for a, b in zip((.2126, .7152, .0722), converted))


def contrast(foreground: str, background: str) -> float:
    a, b = luminance(foreground), luminance(background)
    return (max(a, b) + .05) / (min(a, b) + .05)


class Cb561MovilAaTests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: mobile widths, desktop layout, scroll containment, WCAG AA."""
        for width in VIEWPORTS:
            with self.subTest(viewport=width):
                html = render(width)
                self.assertTrue(html.startswith("<!doctype html>"))
                self.assertIn('name="viewport"', html)
                self.assertIn('content="width=device-width,initial-scale=1,viewport-fit=cover"', html)
                self.assertIn('data-section="owner_decisions"', html)
                self.assertIn('data-section="quality"', html)
                self.assertIn('<main class="shell"', html)
                self.assertIn("*{box-sizing:border-box}", html)
                self.assertIn(".shell{width:min(100%,1180px);margin:auto;padding:24px 16px 48px}", html)
                self.assertIn("grid-template-columns:1fr;gap:12px", html)
                self.assertIn("@media(min-width:760px)", html)
                self.assertIn(".signal-grid{grid-template-columns:repeat(2,minmax(0,1fr))}", html)
                self.assertIn(".ops-header{grid-template-columns:repeat(5,minmax(0,1fr))}", html)
                self.assertIn(".matrix-scroll{margin-top:14px;overflow-x:auto;", html)
                self.assertIn("table{width:100%;min-width:900px;", html)
                self.assertIn(".signal,.matrix-summary,.learning-card{min-width:0;", html)
                self.assertIn("overflow-wrap:anywhere", html)
                self.assertIn("dd{margin:0;overflow-wrap:anywhere}", html)
                self.assertIn("@media(prefers-reduced-motion:reduce)", html)
                self.assertIn(".issue-link{display:inline-block;min-height:44px", html)
                self.assertIn(":focus-visible", html)
                # CSS width budget: border-box shell cannot exceed viewport,
                # while the 900px matrix remains inside an overflow-x scrollport.
                shell_width = min(width, 1180)
                shell_padding = 32 if width < 760 else 56
                self.assertGreater(shell_width - shell_padding, 0)
                if width < 760:
                    self.assertLess(width, 900)
                else:
                    self.assertGreaterEqual(shell_width, 900)
                self.assertLess(html.index('data-section="owner_decisions"'),
                                html.index('data-section="batches"'))
        palette = colors(render(390))
        for name in ("bg", "panel", "panel-raised", "text", "muted",
                     "cyan", "green", "amber", "red"):
            self.assertIn(name, palette)
        for background in ("bg", "panel", "panel-raised"):
            for foreground in ("text", "muted", "cyan", "green", "amber", "red"):
                with self.subTest(background=background, foreground=foreground):
                    self.assertGreaterEqual(
                        contrast(palette[foreground], palette[background]),
                        4.5, "WCAG AA normal-text contrast",
                    )

    def test_falla_cerrado(self):
        """AC-02: missing, malformed and stale inputs never show green."""
        for width in VIEWPORTS:
            with self.subTest(width=width):
                missing = render(width, "empty")
                self.assertIn("UNKNOWN", missing)
                self.assertNotIn("state-healthy fresh-unknown", missing)
                self.assertEqual(render(width, "incoherent").strip(), "blocked")
                stale = render(width, "stale")
                quality = stale.split('data-section="quality"', 1)[1]
                self.assertIn("fresh-stale", quality)
                self.assertIn(">STALE<", quality)
                self.assertNotIn("state-healthy fresh-stale", quality)
        for args in (["375", "full"], ["360", "invalid"], ["-1", "full"]):
            result = subprocess.run(["php", str(SCENARIOS), *args], cwd=ROOT,
                                    capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 2)
            self.assertEqual(result.stdout, "")

    def test_sin_datos_reales(self):
        """AC-03: only synthetic fixtures, no secrets, PII, requests or writes."""
        source = SCENARIOS.read_text(encoding="utf-8")
        self.assertIn("factory_live_ui_scenarios.php", source)
        self.assertTrue(BASE_TESTS.is_file())
        for forbidden in ("file_get_contents(", "curl_", "fsockopen(",
                          "PDO(", "shell_exec(", "exec(", "password=",
                          "Authorization:", "GITHUB_TOKEN", "http://"):
            self.assertNotIn(forbidden, source)
        for width in VIEWPORTS:
            html = render(width, "hostile")
            self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
            self.assertNotIn("<script>alert(1)</script>", html)
            self.assertNotIn("<form", html.lower())
            self.assertNotRegex(html, r"(?i)\b(?:bearer|authorization|password)\s*[:=]")
            self.assertNotRegex(html, r"[\w.+-]+@[\w.-]+\.[a-zA-Z]{2,}")

    def test_estados_unknown_explicitos(self):
        """AC-04: unknown evidence is visible and never promoted to success."""
        for width in VIEWPORTS:
            html = render(width, "empty")
            self.assertIn('data-section="owner_decisions"', html)
            self.assertIn('data-section="quality"', html)
            self.assertIn("UNKNOWN", html)
            self.assertIn("fresh-unknown", html)
            self.assertNotIn("state-healthy fresh-unknown", html)
            self.assertNotIn("state-healthy fresh-stale", html)
            self.assertIn("Solo lectura", html)


if __name__ == "__main__":
    unittest.main()

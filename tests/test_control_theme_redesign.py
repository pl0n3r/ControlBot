"""Pruebas #1169: contraste AA, tokens, carril y preferencias del sistema."""
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def source(path):
    return (ROOT / path).read_text(encoding="utf-8")


def shell():
    return subprocess.run(
        ["php", str(ROOT / "tests/control_center_shell_scenarios.php"), "canonical"],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=30,
    ).stdout


def palettes():
    blocks = re.findall(r":root\s*\{([^}]+)\}", source("src/UiTheme.php"))
    if len(blocks) != 2:
        raise AssertionError("Se requieren dos temas")
    return [
        dict(re.findall(r"--([a-z-]+):\s*(#[0-9a-fA-F]{6});", block))
        for block in blocks
    ]


def brightness(value):
    channels = (int(value[i:i+2], 16) / 255 for i in (1, 3, 5))
    linear = [c / 12.92 if c <= .04045 else ((c+.055)/1.055)**2.4
              for c in channels]
    return sum(a*b for a,b in zip(linear, (.2126, .7152, .0722)))


def contrast(a, b):
    hi, lo = sorted((brightness(a), brightness(b)), reverse=True)
    return (hi+.05)/(lo+.05)


class ControlThemeRedesignTests(unittest.TestCase):
    def test_both_palettes_meet_wcag_aa_on_every_surface(self):
        for theme in palettes():
            for fg in ("text", "muted", "cyan", "green", "amber", "red", "body-soft"):
                for bg in ("bg", "panel", "panel-raised", "field-bg"):
                    with self.subTest(fg=fg, bg=bg, theme=theme["bg"]):
                        self.assertGreaterEqual(contrast(theme[fg], theme[bg]), 4.5)
            for fg, bg in (("on-accent", "cyan"), ("on-green", "green"),
                           ("warning-text", "warning-bg")):
                self.assertGreaterEqual(contrast(theme[fg], theme[bg]), 4.5)

    def test_pages_use_tokens_instead_of_fixed_colors(self):
        for path in ("src/DecisionUi.php", "src/OwnerLoginGate.php"):
            with self.subTest(path=path):
                css = source(path)
                self.assertIn("UiTheme::tokensCss()", css)
                self.assertNotRegex(css, r"#[0-9a-fA-F]{6}\b")
                for token in ("panel", "text", "field-bg"):
                    self.assertIn("var(--" + token + ")", css)
        self.assertIn("var(--warning-bg)", source("src/DecisionUi.php"))
        self.assertIn("var(--on-green)", source("src/DecisionUi.php"))
        self.assertIn("var(--on-accent)", source("src/OwnerLoginGate.php"))

    def test_shell_is_a_rail_on_desktop_and_a_bar_on_mobile(self):
        html = shell()
        for fragment in ("aria-current=\"page\"", "control-brand-caption",
                         "@media (min-width: 860px)", "@media (max-width: 859px)",
                         "grid-template-columns: var(--rail-width) minmax(0, 1fr)",
                         "flex-direction: column", "overflow-x: auto",
                         "flex-wrap: nowrap", "text-decoration: underline",
                         ".nav-active::before", 'aria-label="Navegación principal"'):
            self.assertIn(fragment, html)
        self.assertNotIn("<script", html.lower())

    def test_follows_the_system_theme_and_reduced_motion(self):
        html = shell()
        for fragment in ("color-scheme: light", "color-scheme: dark",
                         "@media (prefers-color-scheme: dark)"):
            self.assertIn(fragment, html)
        self.assertNotIn("<script", html.lower())
        for path in ("src/ControlCenterShell.php", "src/DecisionUi.php",
                     "src/OwnerLoginGate.php"):
            css = source(path).replace(" ", "")
            for fragment in ("prefers-reduced-motion:reduce",
                             "animation:none!important", "transition:none!important",
                             "scroll-behavior:auto!important"):
                self.assertIn(fragment, css)


if __name__ == "__main__":
    unittest.main()

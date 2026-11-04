"""Contrato de diseño del panel «Orquestador en vivo» (issue #1443)."""
import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render() -> str:
    return subprocess.run(
        ['php', str(ROOT / 'tests/factory_orchestrator_live_ui_scenarios.php'), 'full'],
        check=True, capture_output=True, text=True,
    ).stdout


class PanelDesignTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.html = render()
        cls.css = re.search(r'<style>(.*?)</style>', cls.html, re.S).group(1)

    def test_estado_nunca_solo_por_color(self):
        self.assertIn('class="glyph"', self.html)
        self.assertRegex(self.html, r'<h3 class="status"><i class="glyph"[^>]*>[●◐■?]</i>\w')

    def test_etiquetas_en_espanol(self):
        for word in ('Reservado', 'solo lectura', 'Vigente'):
            self.assertIn(word, self.html)

    def test_frentes_agrupados_por_repositorio(self):
        self.assertIn('class="repo-group"', self.html)
        self.assertIn('class="front-list"', self.html)

    def test_filtros_solo_css_accesibles(self):
        self.assertIn('aria-label="Filtros de vista"', self.html)
        self.assertNotIn('<script', self.html)
        self.assertIn(':has(', self.css)
        self.assertIn(':focus-visible', self.css)

    def test_movimiento_contenido_y_reducible(self):
        self.assertNotIn('transition:all', self.css.replace(' ', ''))
        self.assertIn('@media(prefers-reduced-motion:reduce)', self.css.replace(' ', ''))
        for ms in re.findall(r'(\d+)ms', self.css):
            self.assertLessEqual(int(ms), 250)

    def test_claro_y_oscuro_conservan_tokens_de_contraste(self):
        css = self.css.replace(' ', '')
        self.assertIn('@media(prefers-color-scheme:dark)', css)
        self.assertNotIn('\\n:root{color-scheme:dark;', self.css)
        self.assertIn('color-scheme: light;', self.css)
        self.assertIn('background:var(--surface-gradient)', css)
        self.assertIn('box-shadow:var(--surface-shadow)', css)
        for token in ('--repo-pink', '--repo-orange', '--seg-bg', '--sky', '--violet'):
            self.assertIn(token, css)

    def test_paneles_compartidos_sin_debug_visible(self):
        def scenario(path, name):
            return json.loads(subprocess.run(
                ['php', str(ROOT / path), name],
                check=True, capture_output=True, text=True,
            ).stdout)

        agent = scenario('tests/factory_agent_activity_scenarios.php', 'full')['html']
        health = scenario('tests/factory_public_health_scenarios.php', 'ui_escape')['raw_html']
        self.assertIn('data-freshness="current"', agent)
        self.assertIn('data-source-age-seconds="10"', agent)
        self.assertIn('hace 12 s', agent)
        self.assertIn('data-health="reachable"', health)
        self.assertIn('data-source-age-seconds="0"', health)
        self.assertIn('Alcanzable', health)
        for markup in (agent, health):
            visible = re.sub(r'\s(?:title|data-[\w-]+)="[^"]*"', '', markup)
            self.assertNotIn('freshness=', visible)
            self.assertNotIn('timestamp=', visible)
            self.assertNotIn(' · antigüedad=', visible)
            self.assertNotIn('Endpoint:', visible)
        self.assertIn('&lt;script&gt;', health)

    def test_numeros_tabulares_y_objetivos_tactiles(self):
        self.assertIn('tabular-nums', self.css)
        self.assertIn('min-height:44px', self.css)


if __name__ == '__main__':
    unittest.main()

"""Contrato de diseño del Orquestador en vivo (#1443)."""
import json, re, subprocess, unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
def cli(path, mode):
    return subprocess.check_output(['php', str(ROOT / path), mode], text=True)
class PanelDesignTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.html = cli('tests/factory_orchestrator_live_ui_scenarios.php', 'full')
        cls.css = re.search(r'<style>(.*?)</style>', cls.html, re.S).group(1)
    def test_hierarchy_status_and_localization(self):
        for token in ('class="glyph"', 'Reservado', 'solo lectura', 'Vigente', 'class="repo-group"', 'class="front-list"'):
            self.assertIn(token, self.html)
        self.assertRegex(self.html, r'<h3 class="status"><i class="glyph"[^>]*>[●◐■?]</i>\w')
    def test_filters_motion_and_touch(self):
        self.assertIn('aria-label="Filtros de vista"', self.html)
        self.assertNotIn('<script', self.html)
        for token in (':has(', ':focus-visible', 'tabular-nums', 'min-height:44px', '@media(prefers-reduced-motion:reduce)'):
            self.assertIn(token, self.css.replace(' ', ''))
        self.assertNotIn('transition:all', self.css.replace(' ', ''))
        for ms in re.findall(r'(\d+)ms', self.css): self.assertLessEqual(int(ms), 250)
    def test_color_schemes(self):
        css = self.css.replace(' ', '')
        for token in ('@media(prefers-color-scheme:dark)', 'background:var(--surface-gradient)',
                      'box-shadow:var(--surface-shadow)', '--repo-pink', '--repo-orange', '--seg-bg', '--sky', '--violet'):
            self.assertIn(token, css)
        self.assertIn('color-scheme: light;', self.css)
        self.assertNotIn('\n:root{color-scheme:dark;', self.css)
    def test_shared_sections_show_human_age_not_debug(self):
        activity = json.loads(cli('tests/factory_agent_activity_scenarios.php', 'full'))['html']
        health = json.loads(cli('tests/factory_public_health_scenarios.php', 'ui_escape'))['raw_html']
        for html, markers in ((activity, ('data-freshness="current"', 'data-source-age-seconds="10"', 'hace 12 s')),
                              (health, ('data-health="reachable"', 'data-source-age-seconds="0"', 'Alcanzable'))):
            for marker in markers: self.assertIn(marker, html)
            visible = re.sub(r'\s(?:title|data-[\w-]+)="[^"]*"', '', html)
            for debug in ('freshness=', 'timestamp=', ' · antigüedad=', 'Endpoint:'):
                self.assertNotIn(debug, visible)
        self.assertIn('&lt;script&gt;', health)
if __name__ == '__main__':
    unittest.main()

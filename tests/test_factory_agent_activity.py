import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    result = subprocess.run(
        ['php', str(ROOT / 'tests/factory_agent_activity_scenarios.php'), name],
        cwd=ROOT, check=True, capture_output=True, text=True, timeout=20,
    )
    return json.loads(result.stdout)


class FactoryAgentActivityTests(unittest.TestCase):
    def test_per_repo_available_reserved_prs_and_age(self):
        view = scenario('full')['view']
        self.assertEqual(7, len(view['projects']))
        self.assertTrue(view['read_only'])
        self.assertFalse(view['queue_empty'])
        for project in view['projects']:
            self.assertEqual((project['available'], project['reserved'], project['open_prs']), (1, 1, 1))
            self.assertEqual(project['activity_state'], 'OBSERVED')
            self.assertEqual(project['last_signal_age_seconds'], 12)
            self.assertEqual(project['last_signal_kind'], 'coordination')

    def test_empty_queue_requires_known_zero_and_unlockable_planned(self):
        result = scenario('queue')
        self.assertTrue(result['valid']['queue_empty'])
        self.assertFalse(result['valid']['projects'][0]['queue_empty'])
        self.assertTrue(result['valid']['projects'][2]['queue_empty'])
        self.assertIsNone(result['unknown']['queue_empty'])
        self.assertIsNone(result['stale']['queue_empty'])

    def test_source_timestamp_and_freshness_fail_closed(self):
        result = scenario('safety')
        self.assertTrue(result['bad_repo'])
        self.assertTrue(result['bad_source'])
        self.assertTrue(result['duplicate'])
        incomplete = result['partial']['projects'][0]
        self.assertEqual(incomplete['activity_state'], 'UNKNOWN')
        self.assertIsNone(incomplete['last_signal_at'])
        self.assertIsNone(incomplete['last_signal_age_seconds'])
        unknown = result['unknown']['projects'][0]
        self.assertEqual(unknown['activity_state'], 'UNKNOWN')
        self.assertIsNone(unknown['available'])
        self.assertIsNone(unknown['open_prs'])

    def test_private_read_only_ui_escapes_untrusted_values(self):
        result = scenario('safety')
        html = result['html']
        self.assertIn('data-section="agent_activity"', html)
        self.assertIn('read-only', (ROOT/'src/FactoryAgentActivityUi.php').read_text().lower())
        for dangerous in ('<script', '<form', 'data-delete', 'data-dispatch', '<button', 'onclick='):
            self.assertNotIn(dangerous, html.lower())
        self.assertNotIn('token=', html)
        self.assertIn('UNKNOWN', html)
        source = (ROOT/'src/FactoryAgentActivityUi.php').read_text()
        self.assertIn('htmlspecialchars(', source)
        self.assertIn('ENT_QUOTES', source)

    def test_aggregate_validate_gate_contract(self):
        workflow = (ROOT/'.github/workflows/ci.yml').read_text()
        self.assertIn('name: Validar', workflow)
        self.assertIn('needs: [factory, contrato]', workflow)
        source = (ROOT/'src/FactoryAgentActivity.php').read_text()
        self.assertNotIn('curl_', source)
        self.assertNotIn('file_get_contents(', source)
        self.assertIn('planned_unlockable', source)


if __name__ == '__main__':
    unittest.main()

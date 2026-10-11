"""Offline acceptance for per-area readiness evidence and non-leaking failures."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / 'tests/readiness_evidence_ledger_scenarios.php'


def fixture(name):
    process = subprocess.run(
        ['php', str(SCENARIOS), name], cwd=ROOT, check=True,
        capture_output=True, text=True, timeout=20,
    )
    return process.stdout.strip()


class ReadinessEvidenceLedgerTests(unittest.TestCase):
    def test_expired_evidence_is_stale_and_never_current(self):
        report = json.loads(fixture('stale'))
        signal = report['signals'][0]
        self.assertEqual((signal['freshness'], signal['result'], signal['reason']),
                         ('stale', 'UNKNOWN', 'expired'))
        self.assertTrue(report['has_unknown_or_stale'])
        self.assertFalse(report['authorizes_live'])
        empty = json.loads(fixture('unknown'))
        self.assertEqual(empty['signals'][0]['reason'], 'evidence_missing')
        self.assertIsNone(empty['signals'][0]['source_ref'])

    def test_each_signal_requires_source_and_revision(self):
        report = json.loads(fixture('revision'))
        signal = report['signals'][0]
        self.assertEqual(signal['revision'], 'v1.2.3')
        self.assertTrue(signal['source_ref'].startswith('https://github.com/pl0n3r/'))
        self.assertEqual(signal['freshness'], 'current')
        self.assertFalse(report['authorizes_live'])

    def test_deploy_health_smoke_remain_distinct(self):
        report = json.loads(fixture('separate'))
        self.assertEqual([row['area'] for row in report['signals']], ['deploy', 'health', 'smoke'])
        self.assertEqual(len(report['signals']), 3)
        self.assertTrue(all(row['result'] == 'PASS' for row in report['signals']))
        self.assertFalse(report['authorizes_live'])

    def test_secrets_and_pii_are_rejected_without_echo(self):
        for case in ('secret', 'pii', 'duplicate', 'invalid'):
            with self.subTest(case=case):
                output = fixture(case)
                self.assertEqual(output, 'blocked')
                self.assertNotIn('SECRET_VALUE', output)
                self.assertNotIn('alice@example.com', output)


if __name__ == '__main__':
    unittest.main()

"""Offline B2 configuration preflight: never operational evidence."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIO = ROOT / 'tests' / 'b2_recovery_preflight_scenarios.php'
DOC = ROOT / 'docs' / 'backblaze-b2-preflight.md'
CODE = ROOT / 'src' / 'BackblazeB2Preflight.php'


def scenario(name):
    result = subprocess.run(
        ['php', str(SCENARIO), name], cwd=ROOT,
        capture_output=True, text=True, timeout=15, check=True,
    )
    return json.loads(result.stdout)


class B2RecoveryPreflightTests(unittest.TestCase):
    def test_sanitized_positive_is_not_operational_evidence(self):
        data = scenario('positive')
        self.assertEqual(data, {
            'version': 1, 'configuration_status': 'consistent',
            'operational_status': 'UNKNOWN', 'reasons': [],
        })
        self.assertNotIn('HEALTHY', str(data))
        self.assertNotIn('receipt', str(data))

    def test_redacted_or_unsafe_buckets_are_incomplete(self):
        expectations = {
            'redacted': 'retention_unreadable',
            'public': 'private_bucket_required',
            'no-retention': 'default_retention_missing',
            'encryption-redacted': 'encryption_unreadable',
            'encryption-off': 'encryption_missing',
            'lock-disabled': 'object_lock_disabled',
            'missing': 'missing_metadata',
        }
        for name, reason in expectations.items():
            with self.subTest(name=name):
                data = scenario(name)
                self.assertEqual(data['configuration_status'], 'incomplete')
                self.assertEqual(data['operational_status'], 'UNKNOWN')
                self.assertIn(reason, data['reasons'])
                self.assertTrue(all(isinstance(x, str) and len(x) < 40 for x in data['reasons']))

    def test_reject_secrets_unknown_fields_and_malformed_shapes(self):
        for name in ('secret-injection', 'id-injection', 'nested-injection',
                     'malformed', 'unreadable-with-value'):
            with self.subTest(name=name):
                self.assertEqual(scenario(name), {
                    'error_code': 'invalid_shape', 'operational_status': 'UNKNOWN',
                })

    def test_contract_is_offline_and_documents_live_gate(self):
        code = CODE.read_text(encoding='utf-8')
        doc = DOC.read_text(encoding='utf-8')
        for banned in ('curl_', 'file_put_contents(', 'shell_exec(', 'getenv(', 'http_get('):
            self.assertNotIn(banned, code)
        for required in ('solo offline', 'readBucketRetentions', 'readBucketEncryption',
                         'UNKNOWN', '#858', '#182', 'Object Lock', 'operador autorizado'):
            self.assertIn(required, doc)
        self.assertIn('operational_status', code)
        self.assertNotIn('bucketId', code)
        self.assertNotIn('applicationKey', code)


if __name__ == '__main__':
    unittest.main()

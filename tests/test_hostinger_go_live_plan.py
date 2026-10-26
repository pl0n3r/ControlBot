"""AC-01..04: offline, fail-closed and process-level behavior (#915)."""
from __future__ import annotations
import json
import subprocess
import sys
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / 'scripts/hostinger_go_live_plan.py'
SHA = 'a' * 40
TARGET = 'control.condorapp.com.co'
GATES = ('owner_approval', 'hostinger_operator', 'legal_privacy', 'backup_restore',
         'rollback', 'artifact_provenance', 'owner_only_https', 'production_observation')


def fixture():
    return {'version': 1, 'target': TARGET, 'sha': SHA, 'phase': 'construccion',
            'evidence': [
                {'gate': gate, 'source_ref': f'controlbot:fixture/{gate}', 'scope': TARGET,
                 'sha': SHA, 'verified': True, 'fresh': True} for gate in GATES]}


def execute(payload):
    raw = payload if isinstance(payload, bytes) else json.dumps(payload).encode()
    proc = subprocess.run([sys.executable, str(SCRIPT)], input=raw,
                          capture_output=True, timeout=15, check=False)
    return json.loads(proc.stdout), proc


class HostingerGoLivePlanTests(unittest.TestCase):
    def never_runtime(self, report):
        for key in ('runtime_authorized', 'deploy_authorized', 'go_live_authorized', 'execution'):
            self.assertIs(report[key], False)
        self.assertNotIn(report['decision'], ('GO', 'GREEN', 'VALIDATED_IN_PRODUCTION'))

    def test_rejects_untrusted_scope_sha_and_extra_fields(self):
        for field, value in (('target', 'example.net'), ('sha', SHA[:-1] + 'b'),
                             ('sha', 'bad'), ('phase', 'live'), ('version', 2)):
            with self.subTest(field=field, value=value):
                payload = fixture()
                payload[field] = value
                report, proc = execute(payload)
                self.assertEqual(report['decision'], 'NO_GO')
                self.never_runtime(report)
                self.assertEqual(proc.returncode, 1)
        payload = fixture()
        payload['approval'] = True
        self.assertEqual(execute(payload)[0]['reason'], 'invalid_input')
        self.assertEqual(execute(b'{}')[0]['decision'], 'NO_GO')

    def test_missing_stale_or_unverified_evidence_stays_no_go(self):
        for modifier in ('missing', 'stale', 'unverified', 'mismatched_sha', 'mismatched_scope'):
            with self.subTest(modifier=modifier):
                payload = fixture()
                if modifier == 'missing':
                    payload['evidence'].pop()
                elif modifier == 'stale':
                    payload['evidence'][2]['fresh'] = False
                elif modifier == 'unverified':
                    payload['evidence'][2]['verified'] = False
                elif modifier == 'mismatched_sha':
                    payload['evidence'][2]['sha'] = 'b' * 40
                else:
                    payload['evidence'][2]['scope'] = 'other.example.net'
                report, proc = execute(payload)
                self.assertEqual(report['decision'], 'NO_GO')
                self.never_runtime(report)
                self.assertEqual(proc.returncode, 1)
                self.assertTrue(report['missing_gates'])

    def test_consistent_fixture_is_review_only_never_live(self):
        report, proc = execute(fixture())
        self.assertEqual(proc.returncode, 0)
        self.assertEqual(report['decision'], 'REVIEW_ONLY')
        self.assertEqual(report['missing_gates'], [])
        self.assertEqual(set(report['gate_status']), set(GATES))
        self.assertEqual(set(report['next_steps']), set(GATES))
        self.assertIn('operator', report['reason'])
        self.never_runtime(report)
        minimal = fixture()
        minimal['evidence'] = []
        report, proc = execute(minimal)
        self.assertEqual(report['decision'], 'NO_GO')
        self.assertEqual(report['missing_gates'], list(GATES))
        self.never_runtime(report)

    def test_secret_like_payload_duplicate_or_invalid_data_fails_closed(self):
        for reference in ('controlbot:secret_access', 'controlbot:api_key_1234',
                          'controlbot:alice@example.com', 'https://example.com/opaque',
                          'controlbot:bearer123', 'controlbot:session_test'):
            with self.subTest(reference=reference):
                payload = fixture()
                payload['evidence'][0]['source_ref'] = reference
                report, proc = execute(payload)
                self.assertEqual(report['decision'], 'NO_GO')
                self.never_runtime(report)
                self.assertNotIn(reference, proc.stdout.decode())
        duplicate = fixture()
        duplicate['evidence'][1]['source_ref'] = duplicate['evidence'][0]['source_ref']
        self.assertEqual(execute(duplicate)[0]['decision'], 'NO_GO')
        duplicate = fixture()
        duplicate['evidence'][1]['gate'] = duplicate['evidence'][0]['gate']
        self.assertEqual(execute(duplicate)[0]['decision'], 'NO_GO')
        payload = fixture()
        payload['evidence'][0]['unexpected'] = 'TOKEN_NOT_ECHO'
        report, proc = execute(payload)
        self.assertEqual(report['decision'], 'NO_GO')
        self.assertNotIn('TOKEN_NOT_ECHO', proc.stdout.decode())
        for raw in (b'{"version":1,"version":2}', b'not-json', b' ' * 32769):
            report, proc = execute(raw)
            self.assertEqual(report['decision'], 'NO_GO')
            self.never_runtime(report)
        script = SCRIPT.read_text()
        for forbidden in ('urllib.request', 'requests.', 'subprocess.', 'os.system(', 'socket.', 'http.client'):
            self.assertNotIn(forbidden, script)


if __name__ == '__main__':
    unittest.main()

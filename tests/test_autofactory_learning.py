import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    p=subprocess.run(['php',str(ROOT/'tests/autofactory_learning_scenarios.php'),name],cwd=ROOT,text=True,capture_output=True,check=True)
    return json.loads(p.stdout)
class AutoFactoryLearningTests(unittest.TestCase):
    def test_deduplicates_and_builds_confident_allowlisted_policy(self):
        d=scenario('aggregate'); key='conversation-unavailable:error'
        self.assertEqual(d['state']['aggregates'][key]['retry']['samples'],20)
        self.assertEqual(d['again']['aggregates'][key]['retry']['samples'],20)
        self.assertEqual(d['policy']['contexts'][key]['action'],'retry')
        self.assertGreaterEqual(d['policy']['contexts'][key]['confidence'],.80)
    def test_dedup_is_installation_scoped_and_fields_can_be_reordered(self):
        d = scenario('installation_replay')
        self.assertEqual(d['samples'], 2)
        self.assertEqual(d['seen_count'], 2)
        self.assertEqual(d['replay_samples'], 2)
        self.assertEqual(d['replay_seen_count'], 2)
        self.assertFalse(d['raw_ids_stored'])
    def test_rejects_whole_batch_when_private_or_unknown_field_exists(self):
        self.assertTrue(scenario('atomic')['rejected'])
    def test_requires_twenty_samples(self):
        self.assertEqual(scenario('threshold')['contexts'],{})
    def test_policy_never_promotes_unlisted_actions_or_invalid_counts(self):
        d = scenario('untrusted_policy')
        self.assertEqual(d['selected_action'], 'wait')
        self.assertEqual(d['selected_rate'], .95)
        self.assertEqual(d['empty_contexts_json'], '{}')
        self.assertEqual(d['empty_policy_version'], 0)
    def test_expires_raw_dedup_evidence_after_thirty_days(self):
        d=scenario('expiry'); self.assertEqual(d['seen'],[])
if __name__=='__main__': unittest.main()

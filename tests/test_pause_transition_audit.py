import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"pause_transition_audit_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)

class PauseTransitionAuditTests(unittest.TestCase):
    def test_transition_records_actor_source_reason_states_and_deterministic_fingerprint(self):
        data=scenario("transition")
        self.assertTrue(data["same_create"])
        self.assertEqual(data["create"]["actor"],"owner-main")
        self.assertEqual(data["create"]["source"],"owner")
        self.assertEqual(data["create"]["reason"],"Operational freeze")
        self.assertIsNone(data["create"]["before_state"])
        self.assertEqual(data["create"]["after_state"],"active")
        self.assertEqual(data["release"]["before_state"],"active")
        self.assertEqual(data["release"]["after_state"],"releasing")
        self.assertEqual(len(data["create"]["fingerprint"]),64)

    def test_identity_provenance_or_invalid_state_transition_fails_closed(self):
        data=scenario("invalid")
        self.assertTrue(all(data.values()),data)

    def test_append_is_tail_only_idempotent_and_rejects_event_id_conflict(self):
        data=scenario("append")
        self.assertEqual(data["count"],2)
        self.assertTrue(data["replay_same"])
        self.assertTrue(data["conflict"])
        self.assertTrue(data["null_identity"])
        self.assertTrue(data["invalid_pair"])
        self.assertTrue(data["chain_gap"])
        self.assertTrue(data["second_creation"])
        self.assertTrue(data["history_provenance"])
        self.assertEqual(data["tail_event"]["after_state"],"releasing")

    def test_regressive_timestamp_or_history_rewrite_fails_closed(self):
        data=scenario("history")
        self.assertTrue(data["regressive_new"])
        self.assertTrue(data["reordered_existing"])

    def test_audit_is_secret_free_and_external_io_free(self):
        data=scenario("secret")
        self.assertTrue(data["actor_secret"])
        self.assertTrue(data["actor_pii"])
        self.assertTrue(data["reason_secret"])
        self.assertTrue(data["reason_pii"])
        self.assertTrue(data["evidence_pii"])
        self.assertEqual(data["hits"],[])

if __name__=="__main__":
    unittest.main()

import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class SecurityAlertingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        result=subprocess.run(
            ["php",str(ROOT/"tests"/"security_alert_scenarios.php")],
            cwd=ROOT,check=True,text=True,capture_output=True,timeout=60
        )
        cls.data=json.loads(result.stdout)

    def test_critical_alert_channel_is_independent_of_private_actions(self):
        d=self.data["critical_independent"]
        self.assertEqual(d["decision"],"independent_channel")
        self.assertEqual(d["channel"],"independent")
        self.assertEqual(self.data["primary_healthy"]["decision"],"primary_channel")

    def test_noncritical_event_does_not_escalate_without_policy(self):
        self.assertEqual(self.data["warning_no_alert"]["decision"],"no_alert")
        self.assertIsNone(self.data["warning_no_alert"]["payload"])
        self.assertEqual(self.data["warning_escalated"]["decision"],"independent_channel")

    def test_unknown_independent_channel_fails_closed_to_owner_review(self):
        d=self.data["unknown_independent"]
        self.assertEqual(d["decision"],"owner_review")
        self.assertEqual(d["channel"],"owner_review")

    def test_alert_intent_is_idempotent(self):
        a=self.data["idempotent_a"]
        b=self.data["idempotent_b"]
        self.assertEqual(a["event_identity"],b["event_identity"])
        self.assertEqual(a["intent_id"],b["intent_id"])
        self.assertEqual(a["channel"],b["channel"])
        self.assertEqual(a["policy_version"],b["policy_version"])

    def test_alert_payload_is_minimized_and_secret_free(self):
        payload=self.data["critical_independent"]["payload"]
        self.assertEqual(set(payload),{"event_identity","event_type","severity","confidence","timestamp","context"})
        self.assertEqual(payload["context"],{"account_scope":"controlbot:account/owner-primary"})
        for forbidden_key in ("event_id","fingerprint","provider","device","actor"):
            self.assertNotIn(forbidden_key,payload)
        text=json.dumps(payload).lower()
        for forbidden in ("pereira","risaralda","password:","token:","secret:","@"):
            self.assertNotIn(forbidden,text)

    def test_channel_selection_never_elevates_confidence(self):
        d=self.data["unknown_confidence"]
        self.assertEqual(d["decision"],"independent_channel")
        self.assertEqual(d["confidence"],"unknown")
        self.assertEqual(d["payload"]["confidence"],"unknown")

    def test_planner_has_no_external_io_or_actions(self):
        source=(ROOT/"src"/"SecurityAlertChannel.php").read_text(encoding="utf-8").lower()
        forbidden=("curl_","fsockopen","new pdo","mysqli","file_put_contents","file_get_contents","shell_exec","proc_open","exec(","system(","mail(","http://","https://")
        self.assertFalse(any(token in source for token in forbidden))

if __name__=="__main__":
    unittest.main()

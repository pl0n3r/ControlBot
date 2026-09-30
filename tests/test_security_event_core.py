import json, subprocess, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class SecurityEventCoreTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        r=subprocess.run(["php",str(ROOT/"tests"/"security_event_scenarios.php")],cwd=ROOT,check=True,text=True,capture_output=True,timeout=60)
        cls.d=json.loads(r.stdout)

    def test_new_device_is_minimized_and_secret_free(self):
        e=self.d["new_device"]
        self.assertEqual(e["event_type"],"new_device")
        self.assertEqual(e["confidence"],"provider_reported")
        self.assertEqual(e["device"]["location"],{"country":"CO","region":"Risaralda","city":"Pereira"})
        self.assertTrue(e["event_identity"].startswith("security-event:provider:"))
        text=json.dumps(e).lower()
        for word in ("password:","token:","authorization:","cookie:","recovery_code:","@"):
            self.assertNotIn(word,text)

    def test_provider_event_identity_is_idempotent(self):
        base,repeat,reordered=self.d["new_device"],self.d["repeat"],self.d["reordered"]
        self.assertEqual(base["event_identity"],repeat["event_identity"])
        self.assertEqual(base["fingerprint"],repeat["fingerprint"])
        self.assertEqual(base["event_identity"],reordered["event_identity"])
        self.assertEqual(base["fingerprint"],reordered["fingerprint"])
        self.assertEqual(self.d["fallback_a"]["event_identity"],self.d["fallback_b"]["event_identity"])

    def test_authentication_factor_changes_remain_distinct(self):
        factors=self.d["factors"]
        self.assertEqual(set(factors),{"passkey_added","totp_changed","recovery_method_changed"})
        self.assertEqual(len({v["event_identity"] for v in factors.values()}),3)
        self.assertEqual(len({v["fingerprint"] for v in factors.values()}),3)

    def test_sensitive_controlbot_action_is_audited_without_credentials(self):
        e=self.d["controlbot"]
        self.assertEqual(e["confidence"],"confirmed")
        self.assertEqual(e["actor"],{"actor_ref":"controlbot:actor/owner","context_ref":"controlbot:context/production-settings"})
        text=json.dumps(e).lower()
        for word in ("password","bearer ","cookie:","private key","otp:","@"):
            self.assertNotIn(word,text)

    def test_incomplete_evidence_never_claims_confirmed_compromise(self):
        self.assertEqual(self.d["incomplete_unknown"]["confidence"],"unknown")
        self.assertTrue(self.d["reject_confirmed_incomplete"])

    def test_invalid_sensitive_or_precise_inputs_fail_closed(self):
        for key in ("reject_extra_field","reject_precise_location","reject_secret","reject_missing_actor"):
            self.assertTrue(self.d[key],key)

    def test_core_has_no_external_io_or_actions(self):
        source=(ROOT/"src"/"SecurityEvent.php").read_text(encoding="utf-8").lower()
        forbidden=("curl_","fsockopen","new pdo","mysqli","file_put_contents","file_get_contents","shell_exec","proc_open","exec(","system(","mail(","http://","https://")
        self.assertFalse(any(x in source for x in forbidden))

if __name__=="__main__": unittest.main()

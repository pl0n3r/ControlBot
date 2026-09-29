import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def run(script,name=None):
    cmd=["php",str(ROOT/"tests"/script)]+([] if name is None else [name])
    return json.loads(subprocess.run(cmd,cwd=ROOT,check=True,text=True,capture_output=True).stdout)
def legacy(name): return run("external_api_request_gate_scenarios.php",name)
def meta(): return run("external_api_trusted_request_gate_scenarios.php")

class ExternalApiTrustedRequestGateTests(unittest.TestCase):
    def test_gate_accepts_only_verified_external_session_context_not_raw_records(self):
        d=meta()
        self.assertEqual(d["types"],["ControlBot\\Business\\VerifiedAccessContext","string","string","string","ControlBot\\ExternalApi\\VerifiedExternalSessionContext","int"])
        self.assertTrue(all(legacy("client").values()))

    def test_verified_session_identity_and_scope_must_match_verified_access(self):
        self.assertTrue(all(legacy("trusted_mismatch").values()))

    def test_deny_is_preserved_and_step_up_requires_verified_context_evidence(self):
        deny=legacy("deny"); mutation=legacy("mutation")
        self.assertEqual(deny["decision"],"deny"); self.assertIsNone(deny["step_up_ref"])
        self.assertEqual(mutation["without"]["decision"],"step_up_required")
        self.assertEqual(mutation["with"]["decision"],"allow")

    def test_output_contains_only_opaque_authentication_refs_without_secrets(self):
        rows=[legacy("read")["valid"],legacy("mutation")["with"]]
        for row in rows:
            self.assertRegex(row["device_ref"],r"^device:[a-f0-9]{32}$")
            self.assertRegex(row["session_ref"],r"^session:[a-f0-9]{32}$")
        self.assertRegex(rows[1]["step_up_ref"],r"^stepup:[a-f0-9]{32}$")
        payload=json.dumps(rows).lower()
        for x in ("token","cookie","otp","secret","credential","private_key","public_key"): self.assertNotIn(x,payload)

    def test_authorization_fields_still_come_only_from_external_api_access(self):
        self.assertEqual(legacy("read")["valid"]["capability"],"owner.cockpit.read")
        self.assertEqual(legacy("mutation")["with"]["capability"],"owner.decision.write")
        self.assertIn("ExternalApiAccess::authorize",meta()["source"])

    def test_gate_remains_pure_without_http_persistence_providers_factory_or_execution(self):
        source=meta()["source"].lower()
        for x in ("pdo(","mysqli","curl_","httprequest","factoryrunner","workitem","scheduler","dispatch","oauth","jwt"): self.assertNotIn(x,source)
if __name__=="__main__": unittest.main()

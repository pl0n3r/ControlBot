import json
import subprocess
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
def scenario(name):
    process = subprocess.run(
        ["php", str(ROOT / "tests" / "github_http_transport_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, check=False,
    )
    if process.returncode:
        raise AssertionError(process.stderr.strip() or process.stdout.strip())
    return json.loads(process.stdout)
class GithubHttpTransportTests(unittest.TestCase):
    def test_host_path_allowlist_rejects_before_transport(self):
        result = scenario("allowlist")
        self.assertEqual(result["sender_calls"], 0)
        self.assertEqual(result["secret_calls"], 0)
        self.assertEqual(len(result["receipts"]), 16)
        self.assertIsNone(result["receipts"]["invalid_word_id"]["intent_id"])
        for name in ("dot_repository_parent", "dot_repository_self", "dot_workflow_parent", "dot_workflow_self"):
            self.assertIn(name, result["receipts"])
        for receipt in result["receipts"].values():
            self.assertEqual(receipt["status"], "rejected")
            self.assertEqual(receipt["error_code"], "invalid_request")
            self.assertIsNone(receipt["evidence_ref"])
    def test_tls_redirect_and_oversize_fail_closed(self):
        result = scenario("closed")
        self.assertEqual(result["calls"], 4)
        self.assertEqual(result["tls"]["error_code"], "tls_not_verified")
        self.assertEqual(result["redirect"]["error_code"], "redirect_blocked")
        self.assertEqual(result["oversize"]["error_code"], "response_limit_exceeded")
        self.assertEqual(result["redirect_count"]["error_code"], "redirect_blocked")
        for name in ("tls", "redirect", "oversize", "redirect_count"):
            self.assertNotEqual(result[name]["status"], "executed")
    def test_secret_never_appears_in_error_or_evidence(self):
        result = scenario("secret")
        self.assertNotIn("fake-token-never-log-XYZ", json.dumps(result))
        self.assertEqual(result["calls"], 1)
        self.assertEqual(result["first"], result["again"])
        self.assertEqual(result["first"]["status"], "ambiguous")
        self.assertEqual(result["first"]["error_code"], "transport_uncertain")
        self.assertEqual(result["conflict"]["error_code"], "idempotency_conflict")
        self.assertEqual(result["type_conflict"]["error_code"], "idempotency_conflict")
        self.assertEqual(result["project_conflict"]["error_code"], "idempotency_conflict")
        self.assertEqual(result["intent_conflict"]["error_code"], "idempotency_conflict")
        self.assertEqual(result["identity_calls"], 1)
        self.assertEqual(result["identity_secrets"], 1)
        self.assertEqual(result["bad_provider"]["error_code"], "secret_provider_failed")
    def test_local_fake_without_external_network(self):
        reentrant = scenario("reentrant")
        self.assertEqual(reentrant["sender_calls"], 1)
        self.assertEqual(reentrant["secret_calls"], 1)
        self.assertEqual(reentrant["nested"]["status"], "rejected")
        self.assertEqual(reentrant["nested"]["error_code"], "idempotency_in_flight")
        self.assertEqual(reentrant["first"], reentrant["again"])
        self.assertEqual(reentrant["first"]["status"], "executed")
        result = scenario("local")
        self.assertEqual(result["calls"], 3)
        for field, value in (("intent_id", "intent-token-0001"), ("project_id", "project-secret-sample")):
            self.assertEqual(result["valid_ids"][field][0][field], value)
            self.assertEqual(result["valid_ids"][field][0], result["valid_ids"][field][1])
            self.assertEqual(result["valid_ids"][field][0]["status"], "executed")
        self.assertEqual(result["first"], result["again"])
        self.assertEqual(result["first"]["status"], "executed")
        self.assertTrue(result["first"]["evidence_ref"].startswith("stub:sha256:"))
        for field in ("intent_id", "project_id", "repository_id", "type", "status",
                      "idempotency_key", "request_digest", "evidence_ref", "started_at", "finished_at"):
            self.assertIn(field, result["first"])
        self.assertTrue(result["observed"]["token_is_fake"])
        guards = result["observed"]["guards"]
        self.assertTrue(guards["verify_tls_peer"])
        self.assertTrue(guards["verify_tls_host"])
        self.assertFalse(guards["follow_redirects"])
        self.assertEqual(guards["timeout_seconds"], 5)
        self.assertEqual(guards["max_response_bytes"], 65536)
if __name__ == "__main__":
    unittest.main()

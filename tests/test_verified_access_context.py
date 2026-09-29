import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"verified_access_context_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class VerifiedAccessContextTests(unittest.TestCase):
    def test_structural_context_cannot_self_certify(self):
        data=scenario("structural")
        self.assertTrue(data["constructor_private"])
        self.assertTrue(data["raw_factory_absent"])
        self.assertTrue(data["source_factory_absent"])
        self.assertFalse(data["array_is_context"])
        self.assertFalse(data["object_is_context"])
        self.assertTrue(data["malicious_source_cannot_mint"])

    def test_server_side_producer_preserves_canonical_access_semantics(self):
        data=scenario("server")
        self.assertEqual(data["source_call"],{
            "identityId":"identity-admin","scope":"venture:alpha",
            "capability":"hostinger.read","now":5000,
        })
        self.assertEqual(data["summary"]["identity_id"],"identity-admin")
        self.assertEqual(data["summary"]["scope"],"venture:alpha")
        self.assertEqual(data["decision"]["decision"],"allow")

    def test_copy_clone_and_reconstruction_do_not_preserve_provenance(self):
        data=scenario("copy")
        self.assertFalse(data["shape_is_context"])
        self.assertTrue(data["clone_blocked"])
        self.assertTrue(data["serialize_blocked"])

    def test_issuance_is_read_only_and_does_not_mutate_lifecycle_or_audit(self):
        data=scenario("readonly")
        self.assertTrue(data["unchanged"])
        self.assertEqual(data["source_calls"],1)
        self.assertFalse(data["has_audit"])
        self.assertFalse(data["has_top_level_state"])

    def test_context_is_secret_free_without_shared_signing_material(self):
        data=scenario("secrets")
        self.assertTrue(data["sensitive_blocked"])
        self.assertTrue(data["normal_allowed"])

    def test_decision_rights_consumes_verified_context_without_outcome_drift(self):
        data=scenario("decisions")
        self.assertEqual(data["allow"]["decision"],"allow")
        self.assertEqual(data["owner"]["decision"],"owner_decision_required")
        self.assertEqual(data["deny"]["decision"],"deny")
        self.assertEqual(data["deny"]["reasons"],["capability_not_granted"])


if __name__=="__main__":
    unittest.main()

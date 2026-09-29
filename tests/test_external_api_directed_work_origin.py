import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_directed_work_origin_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,
    )
    if run.returncode != 0:
        raise AssertionError(run.stderr.strip() or f"scenario {name} failed with {run.returncode}")
    return json.loads(run.stdout)

class ExternalApiDirectedWorkOriginTests(unittest.TestCase):
    def test_only_authorized_owner_mutation_can_materialize_result(self):
        d=scenario("authorized")
        self.assertEqual(d["ok"]["decision"]["outcome"],"approve")
        self.assertTrue(d["no_step"])
        self.assertTrue(d["wrong_scope"])
        self.assertTrue(d["revoked"])

    def test_client_cannot_supply_authority_policy_or_work_fields(self):
        d=scenario("client")
        self.assertTrue(all(d.values()))

    def test_reject_is_audited_without_work_item(self):
        d=scenario("reject")
        result=d["result"]
        self.assertEqual(result["decision"]["outcome"],"reject")
        self.assertIsNone(result["decision"]["approval_ref"])
        self.assertIsNone(result["work_item"])
        self.assertIsNone(result["work_item_ref"])
        self.assertEqual(result["audit"]["mutation"]["outcome"],"mutation_rejected")
        self.assertIsNone(result["audit"]["factory_handoff"])
        self.assertTrue(d["with_work"])

    def test_approve_materializes_factory_compatible_directed_work_item(self):
        d=scenario("approve")
        item=d["work_item"]
        required={
            "work_id","origin_mode","origin_system","group_id","work_type",
            "requested_capabilities","required_roles","authority_level","producer_ref",
            "priority_class","depends_on","claims","policy_ref","approval_ref",
            "evidence_refs","idempotency_key","observed_at",
        }
        self.assertTrue(required.issubset(item))
        self.assertEqual(item["origin_mode"],"directed")
        self.assertEqual(item["origin_system"],"human")
        self.assertEqual(item["authority_level"],"l4_owner")
        self.assertEqual(item["policy_ref"],"controlbot:policy/external-owner-v1")
        self.assertEqual(item["approval_ref"],d["decision"]["approval_ref"])
        self.assertTrue(item["producer_ref"].startswith("controlbot:identity/"))
        self.assertEqual(item["repository_ref"],"pl0n3r/ControlBot")
        self.assertEqual(item["requested_capabilities"],["php"])
        self.assertEqual(item["required_roles"],["ingenieria-software","qa"])

    def test_factory_handoff_is_audited_without_dispatch_or_execution(self):
        d=scenario("approve")
        handoff=d["audit"]["factory_handoff"]
        self.assertEqual(handoff["outcome"],"factory_handoff")
        self.assertEqual(handoff["work_item_ref"],d["work_item_ref"])
        self.assertEqual(handoff["approval_ref"],d["decision"]["approval_ref"])
        self.assertEqual(handoff["decision_ref"],d["decision"]["decision_ref"])
        serialized=json.dumps(d,sort_keys=True).lower()
        for forbidden in ("runner_id","order_id","dispatch_id","executed_at","provider_result"):
            self.assertNotIn(forbidden,serialized)

    def test_contract_is_deterministic_secret_free_and_external_io_free(self):
        first=scenario("approve")
        second=scenario("approve")
        self.assertEqual(first["work_item"],second["work_item"])
        self.assertEqual(first["work_item_ref"],second["work_item_ref"])
        self.assertEqual(first["decision"]["approval_ref"],second["decision"]["approval_ref"])
        p=scenario("policy")
        self.assertTrue(all(p.values()))
        pure=scenario("pure")
        self.assertEqual(pure["methods"],["process"])
        source=pure["source"].lower()
        for forbidden in (
            "new pdo","mysqli_connect(","curl_","http://","https://","factoryrunner",
            "dispatchworkflow","enqueue(","scheduler","file_put_contents","fopen(",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

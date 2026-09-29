import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
OPENAPI=ROOT/"openapi"/"controlbot-owner-v1.json"


def scenario(name):
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"external_api_contract_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,
    )
    return json.loads(run.stdout)


class ExternalApiContractTests(unittest.TestCase):
    def test_openapi_v1_contains_only_phase_zero_routes(self):
        spec=json.loads(OPENAPI.read_text(encoding="utf-8"))
        self.assertEqual(spec["openapi"],"3.1.0")
        self.assertEqual(spec["servers"],[{"url":"/api/v1"}])
        self.assertEqual(set(spec["paths"]),{
            "/cockpit",
            "/owner-inbox",
            "/owner-decisions/{decision_id}",
            "/owner-decisions/{decision_id}/decision",
        })

    def test_public_envelopes_are_closed_and_secret_free(self):
        data=scenario("envelopes")
        self.assertEqual(data["meta"]["freshness"]["state"],"current")
        self.assertEqual(data["unknown"]["freshness"],{
            "state":"unknown","observed_at":None,"source_ref":None,
        })
        serialized=json.dumps(data,sort_keys=True).lower()
        for forbidden in (
            "password","cookie","private_key","provider_credential",
            "access_token","refresh_token","session_secret",
        ):
            self.assertNotIn(forbidden,serialized)
        schemas=json.loads(OPENAPI.read_text(encoding="utf-8"))["components"]["schemas"]
        for schema in schemas.values():
            if isinstance(schema,dict) and schema.get("type")=="object":
                self.assertFalse(schema.get("additionalProperties",True))

    def test_client_cannot_supply_server_authority_fields(self):
        data=scenario("mutation")
        self.assertTrue(all(data["server_fields_rejected"].values()))

    def test_operations_have_scopes_correlation_and_typed_errors(self):
        catalog=scenario("catalog")
        self.assertEqual(len(catalog),4)
        for operation in catalog.values():
            self.assertRegex(operation["auth_scope"],r"^owner\.[a-z_]+\.(read|write)$")
            self.assertIn("mutation",operation)
            self.assertTrue(operation["freshness_required"])
        data=scenario("envelopes")
        self.assertEqual(len(data["ids"]["request_id"]),32)
        self.assertEqual(len(data["ids"]["correlation_id"]),32)
        self.assertEqual(data["error"]["code"],"forbidden")
        self.assertEqual(len(data["unicode_error"]["message"]),121)
        spec=json.loads(OPENAPI.read_text(encoding="utf-8"))
        self.assertIn("Unix epoch timestamp in seconds",spec["components"]["schemas"]["ResponseMeta"]["properties"]["generated_at"]["description"])
        fresh=spec["components"]["schemas"]["Freshness"]["oneOf"][0]["properties"]["observed_at"]
        self.assertIn("Unix epoch timestamp in seconds",fresh["description"])
        expected_errors={"400":["invalid_request"],"401":["unauthenticated"],"403":["forbidden"],
            "404":["not_found"],"409":["conflict","stale_state"],"429":["rate_limited"],"500":["internal_error"]}
        for status,codes in expected_errors.items():
            self.assertEqual(spec["components"]["responses"]["Error"+status]["x-error-codes"],codes)
        for item in spec["paths"].values():
            operation=item["post"] if "post" in item else item["get"]
            self.assertIn("401",operation["responses"])
            self.assertIn("403",operation["responses"])
            self.assertIn("500",operation["responses"])

    def test_operation_responses_expose_displayable_closed_owner_resources(self):
        spec=json.loads(OPENAPI.read_text(encoding="utf-8"))
        schemas=spec["components"]["schemas"]
        expected={"CockpitData":{"ventures"},"OwnerInboxData":{"entries"},
            "OwnerDecisionData":{"decision_id","question","options","state"},
            "DecisionMutationData":{"decision_id","outcome","state","audit_ref"}}
        for name,fields in expected.items():
            self.assertFalse(schemas[name]["additionalProperties"])
            self.assertTrue(fields.issubset(set(schemas[name]["required"])))
        response_refs={
            "CockpitResponse":"CockpitEnvelope","OwnerInboxResponse":"OwnerInboxEnvelope",
            "OwnerDecisionResponse":"OwnerDecisionEnvelope","DecisionMutationResponse":"DecisionMutationEnvelope"}
        for response,envelope in response_refs.items():
            ref=spec["components"]["responses"][response]["content"]["application/json"]["schema"]["$ref"]
            self.assertTrue(ref.endswith("/"+envelope))
        success_refs={("/cockpit","get"):"CockpitResponse",("/owner-inbox","get"):"OwnerInboxResponse",
            ("/owner-decisions/{decision_id}","get"):"OwnerDecisionResponse",
            ("/owner-decisions/{decision_id}/decision","post"):"DecisionMutationResponse"}
        for (path,method),response in success_refs.items():
            self.assertTrue(spec["paths"][path][method]["responses"]["200"]["$ref"].endswith("/"+response))

    def test_contract_exposes_no_direct_execution_surface(self):
        spec=json.loads(OPENAPI.read_text(encoding="utf-8"))
        serialized=json.dumps(spec,sort_keys=True).lower()
        for forbidden in (
            "/ssh","/sql","/secrets","factoryrunner","shell.arbitrary",
            "provider_credential","execution_target","scheduler",
        ):
            self.assertNotIn(forbidden,serialized)

    def test_idempotency_is_required_only_for_mutations(self):
        data=scenario("mutation")
        self.assertEqual(len(data["valid"]["idempotency_key"]),32)
        self.assertTrue(data["missing_idempotency_rejected"])
        self.assertTrue(data["bad_idempotency_rejected"])
        spec=json.loads(OPENAPI.read_text(encoding="utf-8"))
        mutation=spec["paths"]["/owner-decisions/{decision_id}/decision"]["post"]
        self.assertTrue(mutation["requestBody"]["required"])
        for path,item in spec["paths"].items():
            for method,operation in item.items():
                if method=="get":
                    self.assertNotIn("requestBody",operation)


if __name__=="__main__":
    unittest.main()

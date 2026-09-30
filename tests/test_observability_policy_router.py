import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"observability_policy_router_scenarios.php"),name],
        cwd=ROOT,check=False,text=True,capture_output=True,timeout=60)
    if r.returncode != 0:
        raise AssertionError(r.stderr.strip() or f"scenario {name} failed with {r.returncode}")
    return json.loads(r.stdout)

class ObservabilityPolicyRouterTests(unittest.TestCase):
    def test_incident_status_and_severity_project_deterministic_owner_inbox(self):
        d=scenario("classes")
        self.assertEqual(d["info"]["owner_inbox"]["class"],"fyi")
        self.assertEqual(d["warning"]["owner_inbox"]["class"],"watch")
        self.assertEqual(d["error"]["owner_inbox"]["class"],"watch")
        self.assertEqual(d["critical"]["owner_inbox"]["class"],"critical")
        self.assertEqual(d["critical"]["owner_inbox"]["required_authority_level"],"L3_GROUP_INSTITUTION")
        self.assertEqual(d["resolved"]["owner_inbox"]["class"],"fyi")
        self.assertIsNone(d["resolved"]["push_notification"])

    def test_only_error_and_critical_project_canonical_push_with_stable_dedupe(self):
        d=scenario("push")
        self.assertIsNone(d["info"]);self.assertIsNone(d["warning"])
        self.assertEqual(d["error"]["severity"],"attention")
        self.assertEqual(d["critical"]["severity"],"critical")
        self.assertEqual(d["critical"]["detail_operation_id"],"owner_inbox.read")
        self.assertTrue(d["critical"]["requires_authenticated_detail"])
        self.assertTrue(d["retry_same"])

    def test_fresh_critical_policy_projects_freeze_that_blocks_write_not_read(self):
        d=scenario("freeze")
        self.assertEqual(d["freeze"]["scope_type"],"project")
        self.assertEqual(d["freeze"]["source"],"policy")
        self.assertEqual(d["freeze"]["state"],"unknown")
        self.assertTrue(d["write"]["pause_blocked"]);self.assertFalse(d["write"]["pause_allows"])
        self.assertFalse(d["read"]["pause_blocked"]);self.assertTrue(d["read"]["pause_allows"])

    def test_freeze_fails_closed_for_stale_unknown_disabled_resolved_or_bad_provenance(self):
        d=scenario("blocked")
        for key in ("stale","unknown","disabled","resolved"): self.assertIsNone(d[key])
        self.assertTrue(d["bad_provenance"]);self.assertTrue(d["scope_mismatch"])

    def test_projection_is_deterministic_and_materially_bound(self):
        d=scenario("deterministic")
        self.assertTrue(d["same"] and d["fingerprint_same"] and d["material_change"] and d["dedupe_same"])

    def test_sensitive_invalid_or_extra_input_fails_closed_and_router_has_no_external_io(self):
        d=scenario("invalid")
        for key in ("extra","secret","occurrence","empty_timeline","reason","missing_primary",
                    "duplicate_fingerprint","chronology","class_drift","channel_drift","unknown_provenance"):
            self.assertTrue(d[key],key)
        self.assertEqual(scenario("pure")["hits"],[])

if __name__=="__main__": unittest.main()

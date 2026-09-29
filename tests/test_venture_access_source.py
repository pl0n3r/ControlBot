import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"venture_access_source_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)
class VentureAccessSourceTests(unittest.TestCase):
    def test_request_cannot_supply_grant_policy_or_authority(self):
        d=scenario("request"); self.assertTrue(d["extra_blocked"]); self.assertEqual(d["calls"],0)
    def test_runtime_resolves_access_only_through_injected_server_source(self):
        d=scenario("resolve"); self.assertEqual(d["calls"],1); self.assertEqual(d["resolved"]["grant"]["grant_id"],"grant-infra")
    def test_missing_ambiguous_or_mismatched_source_fails_closed(self):
        d=scenario("mismatch"); self.assertTrue(all(d.values()))
    def test_fake_source_is_composition_root_dependency_not_request_input(self):
        d=scenario("composition"); self.assertEqual(d["request_keys"],["identity_id","scope","capability"]); self.assertEqual(d["calls"],1)
    def test_resolution_is_read_only_without_lifecycle_or_provider_writes(self):
        d=scenario("readonly"); self.assertTrue(d["source_unchanged"]); self.assertFalse(d["has_runtime_state"])
    def test_resolved_snapshot_is_minimal_and_secret_free(self):
        self.assertTrue(scenario("secret")["blocked"])
if __name__=="__main__": unittest.main()

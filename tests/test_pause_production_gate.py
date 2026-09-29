import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"pause_production_gate_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)

class PauseProductionGateTests(unittest.TestCase):
    def test_project_freeze_blocks_write_but_typed_read_remains_available(self):
        data=scenario("project")
        self.assertFalse(data["write"]["pause_allows"])
        self.assertEqual(data["write"]["reason"],"pause_blocked")
        self.assertTrue(data["read"]["pause_allows"])
        self.assertEqual(data["read"]["capability"],"hostinger.read")
        self.assertEqual(data["read"]["reason"],"typed_read_during_pause")

    def test_gate_reports_canonical_pause_scope_precedence(self):
        data=scenario("precedence")
        self.assertFalse(data["pause_allows"])
        self.assertEqual(data["effective_pause"]["scope"],"global")
        self.assertEqual(data["effective_pause"]["pause_id"],"pause-global")

    def test_unknown_pause_write_and_invalid_operation_or_scope_fail_closed(self):
        data=scenario("invalid")
        self.assertFalse(data["unknown_write"]["pause_allows"])
        self.assertEqual(data["unknown_write"]["reason"],"pause_blocked")
        self.assertTrue(data["unknown_operation"])
        self.assertTrue(data["scope_mismatch"])

    def test_release_only_removes_pause_block_and_never_grants_authority(self):
        data=scenario("release")
        self.assertFalse(data["active"]["pause_allows"])
        self.assertTrue(data["released"]["pause_allows"])
        for value in data.values():
            self.assertTrue(value["requires_existing_authority"])
            self.assertEqual(value["authorization"],"not_granted")

    def test_gate_is_deterministic_and_external_io_free(self):
        data=scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["fingerprint"])
        self.assertEqual(data["hits"],[])

if __name__=="__main__":
    unittest.main()

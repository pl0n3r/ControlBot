import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "pause_production_gate_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class PauseProductionGateTests(unittest.TestCase):
    def test_project_freeze_blocks_write_but_typed_read_remains_available(self):
        data = scenario("project")
        self.assertTrue(data["write"]["pause_blocked"])
        self.assertFalse(data["write"]["pause_allows"])
        self.assertEqual(data["write"]["reason"], "pause_blocked")
        self.assertEqual(data["write"]["effective_scope"], "project")

        self.assertFalse(data["read"]["pause_blocked"])
        self.assertTrue(data["read"]["pause_allows"])
        self.assertEqual(data["read"]["capability"], "hostinger.read")
        self.assertEqual(data["read"]["reason"], "typed_read_during_pause")
        self.assertEqual(data["read"]["effective_scope"], "project")

    def test_gate_reports_canonical_pause_scope_precedence(self):
        data = scenario("precedence")
        self.assertTrue(data["pause_blocked"])
        self.assertFalse(data["pause_allows"])
        self.assertEqual(data["effective_scope"], "global")
        self.assertEqual(data["effective_pause_id"], "pause-global")
        self.assertEqual(data["effective_state"], "active")

    def test_unknown_pause_write_and_invalid_operation_or_scope_fail_closed(self):
        data = scenario("invalid")
        unknown = data["unknown_write"]
        self.assertTrue(unknown["pause_blocked"])
        self.assertFalse(unknown["pause_allows"])
        self.assertEqual(unknown["reason"], "pause_blocked")
        self.assertEqual(unknown["effective_state"], "unknown")
        self.assertTrue(data["unknown_operation"])
        self.assertTrue(data["scope_mismatch"])

    def test_release_only_removes_pause_block_and_never_grants_authority(self):
        data = scenario("release")
        self.assertTrue(data["active"]["pause_blocked"])
        self.assertFalse(data["active"]["pause_allows"])
        self.assertFalse(data["released"]["pause_blocked"])
        self.assertTrue(data["released"]["pause_allows"])
        for value in data.values():
            self.assertTrue(value["requires_existing_authority"])
            self.assertEqual(value["authorization"], "not_granted")
            self.assertNotIn("grant", value)
            self.assertNotIn("approval", value)

    def test_gate_is_deterministic_and_external_io_free(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["fingerprint"])
        self.assertEqual(data["hits"], [])


if __name__ == "__main__":
    unittest.main()

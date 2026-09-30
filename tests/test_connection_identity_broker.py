import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "connection_identity_broker_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True, timeout=30,
    )
    return json.loads(run.stdout)

class ConnectionIdentityBrokerTests(unittest.TestCase):
    def test_identity_resolves_only_for_authorized_exact_scope(self):
        data=scenario("authorized")
        self.assertEqual(data["calls"],1); self.assertEqual(data["rogue_calls"],0)
        self.assertEqual(data["callback_username"],"deploy_user_528")
        self.assertTrue(data["result"]["ok"])
        self.assertEqual(data["rogue"]["reason"],"executor_not_authorized")
        self.assertNotIn("deploy_user_528",json.dumps(data["result"]))

    def test_agent_surface_keeps_real_username_inside_callback_only(self):
        data=scenario("surface")
        self.assertEqual(data["callback_username"],"deploy_user_528")
        self.assertIsNone(data["surface"]["username"]); self.assertFalse(data["surface"]["resolvable"])
        self.assertFalse(data["visible_contains_username"])

    def test_unknown_revoked_stale_or_mismatched_identity_fails_closed(self):
        data=scenario("invalid")
        self.assertEqual(data["calls"],0)
        expected={
            "executor":"executor_not_authorized","unknown":"identity_reference_unknown",
            "provider":"identity_scope_mismatch","project":"identity_scope_mismatch",
            "environment":"identity_scope_mismatch","generation":"identity_scope_mismatch",
            "extra":"identity_context_invalid","revoked":"identity_reference_revoked",
        }
        for key,reason in expected.items():
            self.assertEqual(data["cases"][key]["reason"],reason)

    def test_rotation_invalidates_old_reference_and_preserves_scope(self):
        data=scenario("rotation")
        self.assertEqual(data["old"]["reason"],"identity_reference_revoked")
        self.assertTrue(data["resolved"]["ok"]); self.assertTrue(data["scope_preserved"])
        self.assertEqual(data["new"]["generation"],2)
        self.assertNotIn("deploy_user_rotated",json.dumps(data["new"]))

    def test_results_and_errors_redact_resolved_username(self):
        data=scenario("redaction")
        self.assertFalse(data["contains_username"])
        self.assertIn("[REDACTED]",json.dumps(data["result"]))
        self.assertIn("[REDACTED]",data["error"]["error"])

    def test_broker_has_no_network_or_shell_primitives(self):
        source=(ROOT/"src"/"ConnectionIdentityBroker.php").read_text(encoding="utf-8")
        for forbidden in ("ssh2_","curl_","fsockopen","stream_socket_client",
                          "proc_open(","shell_exec(","exec(","system("):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

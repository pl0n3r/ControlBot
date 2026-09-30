import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = "/srv/apps/brvtal/current"

def scenario(name: str) -> dict:
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "remote_workspace_broker_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True, timeout=20,
    )
    return json.loads(run.stdout)

class RemoteWorkspaceBrokerTests(unittest.TestCase):
    def test_workspace_resolves_only_for_authorized_exact_scope(self):
        data=scenario("authorized")
        self.assertTrue(data["ok"]["ok"])
        self.assertEqual(data["calls"],1)
        self.assertFalse(data["unauthorized"]["ok"])
        self.assertEqual(data["unauthorized"]["reason"],"executor_not_authorized")
        self.assertFalse(data["mismatch"]["ok"])

    def test_agent_surface_never_exposes_real_path(self):
        data=scenario("surface")
        self.assertIsNone(data["path"])
        self.assertFalse(data["resolvable"])
        self.assertNotIn(PATH,json.dumps(data))

    def test_unknown_revoked_stale_or_mismatched_workspace_fails_closed(self):
        data=scenario("failures")
        for case in data.values():
            self.assertFalse(case["ok"])
            self.assertIsNone(case["path"])

    def test_rotation_invalidates_old_reference_and_preserves_scope(self):
        data=scenario("rotation")
        self.assertEqual(data["new"]["generation"],2)
        self.assertEqual(data["new"]["project"],"brvtal")
        self.assertEqual(data["new"]["environment"],"production")
        self.assertFalse(data["old"]["ok"])
        self.assertTrue(data["resolved"]["ok"])
        self.assertNotIn("/srv/apps/brvtal/releases/2",json.dumps(data["resolved"]))

    def test_noncanonical_or_unsafe_paths_are_rejected(self):
        data=scenario("paths")
        self.assertTrue(data["valid"])
        for key,value in data.items():
            if key!="valid": self.assertTrue(value,key)

    def test_results_and_errors_redact_real_path(self):
        data=scenario("redaction")
        encoded=json.dumps(data)
        self.assertNotIn(PATH,encoded)
        self.assertIn("[REDACTED]",encoded)
        self.assertFalse(data["failure"]["ok"])

    def test_broker_has_no_network_filesystem_write_or_subprocess(self):
        source=(ROOT/"src"/"RemoteWorkspaceBroker.php").read_text()
        for forbidden in (
            "curl_","fsockopen","stream_socket_client","file_put_contents",
            "fopen(","unlink(","proc_open(","shell_exec(","system(","exec(",
        ):
            self.assertNotIn(forbidden,source)

if __name__=="__main__":
    unittest.main()

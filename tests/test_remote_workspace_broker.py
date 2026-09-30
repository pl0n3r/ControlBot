import json
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
PATH="/srv/apps/brvtal/current"

def scenario(name: str)->dict:
    run=subprocess.run(
        ["php",str(ROOT/"tests"/"remote_workspace_broker_scenarios.php"),name],
        cwd=ROOT,check=True,text=True,capture_output=True,timeout=20,
    )
    return json.loads(run.stdout)

class RemoteWorkspaceBrokerTests(unittest.TestCase):
    def test_workspace_resolves_only_for_authorized_exact_scope(self):
        d=scenario("authorized")
        self.assertTrue(d["ok"]["ok"])
        self.assertEqual(d["calls"],1)
        self.assertEqual(d["unauthorized"]["reason"],"executor_not_authorized")
        self.assertFalse(d["mismatch"]["ok"])

    def test_agent_surface_never_exposes_real_path(self):
        d=scenario("surface")
        self.assertEqual((d["path"],d["resolvable"]),(None,False))
        self.assertNotIn(PATH,json.dumps(d))

    def test_unknown_revoked_stale_or_mismatched_workspace_fails_closed(self):
        d=scenario("failures")
        self.assertEqual(set(d),{"unknown","revoked","stale","scope"})
        for case in d.values():
            self.assertFalse(case["ok"])
            self.assertIsNone(case["path"])

    def test_rotation_invalidates_old_reference_and_preserves_scope(self):
        d=scenario("rotation")
        self.assertEqual(
            (d["new"]["generation"],d["new"]["project"],d["new"]["environment"]),
            (2,"brvtal","production"),
        )
        self.assertFalse(d["old"]["ok"])
        self.assertTrue(d["resolved"]["ok"])
        self.assertNotIn("/srv/apps/brvtal/releases/2",json.dumps(d["resolved"]))

    def test_noncanonical_or_unsafe_paths_are_rejected(self):
        d=scenario("paths")
        self.assertTrue(d.pop("valid"))
        self.assertTrue(all(d.values()))

    def test_results_and_errors_redact_real_path(self):
        d=scenario("redaction")
        encoded=json.dumps(d)
        self.assertNotIn(PATH,encoded)
        self.assertIn("[REDACTED]",encoded)
        self.assertFalse(d["failure"]["ok"])

    def test_deduplication_refactor_keeps_shared_helpers_centralized(self):
        source=(ROOT/"src"/"RemoteWorkspaceBroker.php").read_text()
        for token in (
            "private const META_FIELDS",
            "private const CONTEXT_FIELDS",
            "private const SCOPE_FIELDS",
            "private static function hasExactKeys",
            "private static function sameScope",
            "private static function result",
        ):
            self.assertIn(token,source)
        for legacy in (
            "private const META =",
            "private const CONTEXT =",
            "private static function metadata(",
            "private static function context(",
            "private static function executorId(",
            "private static function referenceId(",
            "private static function slug(",
        ):
            self.assertNotIn(legacy,source)

    def test_broker_has_no_network_filesystem_write_or_subprocess(self):
        source=(ROOT/"src"/"RemoteWorkspaceBroker.php").read_text()
        forbidden=(
            "curl_","fsockopen","stream_socket_client","file_put_contents","fopen(",
            "unlink(","proc_open(","shell_exec(","system(","exec(",
        )
        self.assertFalse(any(token in source for token in forbidden))

if __name__=="__main__":
    unittest.main()

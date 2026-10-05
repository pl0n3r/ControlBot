from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
ENTRYPOINT = ROOT / "src" / "FactoryOrchestratorWebEntrypoint.php"
RUNBOOK = ROOT / "docs" / "runbooks" / "orchestrator-snapshot-cron.md"
HTACCESS = ROOT / ".htaccess"
NOW = 1_800_000_000
UNKNOWN_SOURCE = hashlib.sha256(b"UNKNOWN").hexdigest()
PRIVATE_SNAPSHOT = "/home/u151692719/domains/control.condorapp.com.co/private/orchestrator-live.json"

PHP_RUNNER = r'''
require $argv[1] . '/src/FactoryOrchestratorWebEntrypoint.php';
$environment = ['CONTROLBOT_OWNER_LOGIN' => 'owner'];
if ($argv[3] !== '__UNSET__') {
    $environment['CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH'] = $argv[3];
}
$response = \ControlBot\Business\FactoryOrchestratorWebEntrypoint::handle(
    [
        'REQUEST_URI' => '/api/orchestrator-live',
        'REQUEST_METHOD' => 'GET',
        'REMOTE_USER' => 'owner',
        'DOCUMENT_ROOT' => $argv[2],
    ],
    $environment,
    (int) $argv[4],
    null,
    $argv[5],
);
echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
'''


class OrchestratorSnapshotPathEnvTests(unittest.TestCase):
    def _snapshot_bytes(self) -> tuple[bytes, str]:
        snapshot = {
            "version": 1,
            "observed_at": NOW,
            "sections": {
                "batches": [],
                "owner_decisions": [],
                "releases": [],
                "blockers": [],
                "production": [],
                "quality": [],
                "work": [],
                "learning": [],
            },
        }
        canonical = json.dumps(snapshot, separators=(",", ":"), ensure_ascii=False).encode()
        fingerprint = hashlib.sha256(canonical).hexdigest()
        snapshot["fingerprint"] = fingerprint
        return json.dumps(snapshot, separators=(",", ":"), ensure_ascii=False).encode(), fingerprint

    def _invoke(self, configured_path: str | None, cache_path: Path) -> dict:
        completed = subprocess.run(
            [
                "php",
                "-r",
                PHP_RUNNER,
                str(ROOT),
                str(ROOT),
                configured_path if configured_path is not None else "__UNSET__",
                str(NOW),
                str(cache_path),
            ],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )
        response = json.loads(completed.stdout)
        self.assertEqual(response["status"], 200)
        return json.loads(response["body"])["snapshot"]

    def test_entrypoint_reads_validated_external_snapshot_path_from_environment(self) -> None:
        raw, fingerprint = self._snapshot_bytes()
        with tempfile.TemporaryDirectory() as temp_dir:
            base = Path(temp_dir)
            snapshot_path = base / "orchestrator-live.json"
            snapshot_path.write_bytes(raw)

            snapshot = self._invoke(str(snapshot_path), base / "cache.json")

        self.assertEqual(snapshot["source_snapshot"], fingerprint)
        self.assertNotEqual(snapshot["source_snapshot"], UNKNOWN_SOURCE)

    def test_invalid_or_in_tree_snapshot_path_fails_closed_to_unknown(self) -> None:
        raw, _ = self._snapshot_bytes()
        with tempfile.TemporaryDirectory() as temp_dir:
            base = Path(temp_dir)
            external = base / "orchestrator-live.json"
            external.write_bytes(raw)
            symlink = base / "snapshot-link.json"
            symlink.symlink_to(external)
            traversal = external.parent / ".." / external.parent.name / external.name

            with tempfile.NamedTemporaryFile(
                mode="wb",
                prefix="orchestrator-in-tree-",
                suffix=".json",
                dir=ROOT / "tests",
                delete=False,
            ) as handle:
                handle.write(raw)
                in_tree = Path(handle.name)

            try:
                cases = [
                    "relative/orchestrator-live.json",
                    str(traversal),
                    str(symlink),
                    str(in_tree),
                ]
                for index, configured in enumerate(cases):
                    with self.subTest(configured=configured):
                        snapshot = self._invoke(configured, base / f"cache-{index}.json")
                        self.assertEqual(snapshot["source_snapshot"], UNKNOWN_SOURCE)
                        self.assertEqual(snapshot["central"]["activity_state"], "UNKNOWN")
            finally:
                in_tree.unlink(missing_ok=True)

    def test_unset_environment_keeps_legacy_default(self) -> None:
        source = ENTRYPOINT.read_text(encoding="utf-8")
        self.assertIn("dirname(__DIR__) . '/var/orchestrator-live.json'", source)
        self.assertIn("array_key_exists(self::SNAPSHOT_ENV, $environment)", source)

    def test_runbook_documents_external_path_and_no_longer_claims_var_survives_deploys(self) -> None:
        runbook = RUNBOOK.read_text(encoding="utf-8")
        htaccess = HTACCESS.read_text(encoding="utf-8")

        self.assertIn(PRIVATE_SNAPSHOT, runbook)
        self.assertIn(
            "SetEnv CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH " + PRIVATE_SNAPSHOT,
            htaccess,
        )
        self.assertNotIn("no debe pisar el snapshot", runbook)
        self.assertNotRegex(htaccess, r"(?:ghp_|github_pat_)[A-Za-z0-9_]+")


if __name__ == "__main__":
    unittest.main()

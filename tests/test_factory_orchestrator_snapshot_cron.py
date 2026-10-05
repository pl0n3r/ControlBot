import json
import os
import re
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/orchestrator-snapshot-cron.php"
REFRESH = ROOT / "src/FactoryOrchestratorSnapshotRefresh.php"
RUNBOOK = ROOT / "docs/runbooks/orchestrator-snapshot-cron.md"


def base_environment() -> dict[str, str]:
    env = os.environ.copy()
    for key in (
        "CONTROLBOT_ORCHESTRATOR_CRON_ENABLED",
        "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH",
        "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH",
        "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS",
    ):
        env.pop(key, None)
    return env


def canonical_evidence(observed_at: int) -> dict:
    return {
        "work": [
            {
                "id": "work:controlbot-661",
                "authority": "github_project_snapshot",
                "state": "pending",
                "source_ref": "github:pl0n3r/ControlBot#661",
                "observed_at": observed_at,
                "freshness": "current",
                "data": {
                    "repository_ref": "pl0n3r/ControlBot",
                    "issue_ref": "github:pl0n3r/ControlBot#661",
                    "status": "reserved",
                    "progress_percent": 50,
                    "progress_evidence": "github:pl0n3r/ControlBot#661",
                },
            }
        ]
    }


class FactoryOrchestratorSnapshotCronTests(unittest.TestCase):
    def _run_refresh(
        self,
        snapshot: Path,
        *,
        evidence: dict | None = None,
        now: int | None = None,
        max_bytes: int = 2_000_000,
        io_php: str = "[]",
        collector_php: str | None = None,
    ) -> dict:
        payload = evidence or canonical_evidence(int(time.time()))
        collector = collector_php or "static fn (): array => $evidence"
        code = f"""
require $argv[1];
$evidence = json_decode($argv[2], true, 64, JSON_THROW_ON_ERROR);
$collector = {collector};
$io = {io_php};
try {{
    $result = \\ControlBot\\Business\\FactoryOrchestratorSnapshotRefresh::refresh(
        $collector,
        $argv[3],
        (int) $argv[4],
        (int) $argv[5],
        $io
    );
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}} catch (\\ControlBot\\Business\\FactoryOrchestratorSnapshotRefreshFailure $exception) {{
    echo json_encode(['ok' => false, 'code' => $exception->failureCode()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}}
"""
        result = subprocess.run(
            [
                "php",
                "-r",
                code,
                str(REFRESH),
                json.dumps(payload, separators=(",", ":")),
                str(snapshot),
                str(now if now is not None else int(time.time())),
                str(max_bytes),
            ],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=30,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stderr)
        return json.loads(result.stdout)

    def test_cron_wrapper_is_disabled_without_explicit_server_configuration_and_uses_no_request_path_io(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            snapshot = Path(directory) / "orchestrator-live.json"
            env = base_environment()
            env["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"] = str(
                Path(directory) / "missing-evidence.json"
            )
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = str(snapshot)

            result = subprocess.run(
                ["php", str(SCRIPT)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                timeout=30,
                check=False,
            )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            {"executed": False, "state": "disabled"},
            json.loads(result.stdout),
        )
        self.assertFalse(snapshot.exists())

        entrypoint = (ROOT / "public/index.php").read_text()
        wrapper = SCRIPT.read_text()
        self.assertNotIn("FactoryOrchestratorSnapshotCron", entrypoint)
        self.assertNotIn("orchestrator-snapshot-cron.php", entrypoint)
        self.assertNotIn("curl_", wrapper)
        self.assertNotIn("api.github.com", wrapper)
        self.assertNotIn("Authorization", wrapper)

        source = ROOT / "src/FactoryOrchestratorSnapshotCron.php"
        direct = subprocess.run(
            [
                "php",
                "-r",
                (
                    "require $argv[1]; "
                    "$result=\\ControlBot\\Business\\FactoryOrchestratorSnapshotCron::run("
                    "['CONTROLBOT_ORCHESTRATOR_CRON_ENABLED'=>''],"
                    "static function (): array { throw new RuntimeException('collector should not run'); },"
                    "'/tmp/controlbot-disabled-snapshot.json',1);"
                    "echo json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);"
                ),
                str(source),
            ],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=30,
            check=False,
        )
        self.assertEqual(0, direct.returncode, direct.stderr)
        self.assertEqual(
            {"executed": False, "state": "disabled"},
            json.loads(direct.stdout),
        )

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            evidence_path = root / "invalid-evidence.json"
            snapshot_path = root / "orchestrator-live.json"
            evidence_path.write_text("{invalid-json")

            enabled_env = base_environment()
            enabled_env["CONTROLBOT_ORCHESTRATOR_CRON_ENABLED"] = "1"
            enabled_env["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"] = str(evidence_path)
            enabled_env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = str(snapshot_path)

            failed = subprocess.run(
                ["php", str(SCRIPT)],
                cwd=ROOT,
                env=enabled_env,
                text=True,
                capture_output=True,
                timeout=30,
                check=False,
            )

        self.assertEqual(70, failed.returncode)
        self.assertEqual("", failed.stdout)
        self.assertEqual("orchestrator-snapshot-cron: evidence_invalid\n", failed.stderr)
        self.assertFalse(snapshot_path.exists())

    def test_runbook_keeps_token_cron_hosting_and_live_activation_outside_repository_values(self) -> None:
        runbook = RUNBOOK.read_text()
        wrapper = SCRIPT.read_text()

        self.assertIn("fuera del repositorio", runbook)
        self.assertIn("apagado por defecto", runbook)
        self.assertIn("ControlBot #625", runbook)
        self.assertIn("La línea es instalable tal cual", runbook)
        self.assertIn("*/5 * * * *", runbook)
        self.assertIn(
            'CONTROLBOT_GITHUB_READ_TOKEN_FILE="$HOME/.controlbot/github-read-token"',
            runbook,
        )
        self.assertNotIn("CONTROLBOT_GITHUB_READ_TOKEN=", runbook)
        self.assertNotRegex(runbook, r"(?:ghp_|github_pat_)[A-Za-z0-9_]+")
        self.assertNotRegex(runbook, r"(?m)^\s*[^#\n]*\b(?:DOMAIN|DEPLOY_ENABLED)\s*=\s*\S+")
        self.assertNotRegex(runbook, r"(?m)^\s*\d+\s+\d+\s+\S+\s+\S+\s+\S+\s+")
        self.assertNotIn("CONTROLBOT_GITHUB_TOKEN", wrapper)
        self.assertNotIn("GITHUB_TOKEN", wrapper)

    def test_enabled_wrapper_uses_injected_file_and_refreshes_without_network(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            evidence_path = root / "evidence.json"
            snapshot_path = root / "orchestrator-live.json"
            observed_at = int(time.time())
            evidence_path.write_text(json.dumps(canonical_evidence(observed_at)))

            env = base_environment()
            env["CONTROLBOT_ORCHESTRATOR_CRON_ENABLED"] = "1"
            env["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"] = str(evidence_path)
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = str(snapshot_path)

            result = subprocess.run(
                ["php", str(SCRIPT)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                timeout=30,
                check=False,
            )

            self.assertEqual(0, result.returncode, result.stderr)
            response = json.loads(result.stdout)
            snapshot = json.loads(snapshot_path.read_text())

        self.assertTrue(response["executed"])
        self.assertEqual("refreshed", response["state"])
        self.assertTrue(response["written"])
        self.assertEqual(snapshot["fingerprint"], response["fingerprint"])
        self.assertEqual("reserved", snapshot["sections"]["work"][0]["data"]["status"])

    def test_enabled_wrapper_fails_closed_for_missing_injected_evidence(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            env = base_environment()
            env["CONTROLBOT_ORCHESTRATOR_CRON_ENABLED"] = "1"
            env["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"] = str(root / "missing.json")
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = str(root / "snapshot.json")

            result = subprocess.run(
                ["php", str(SCRIPT)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
                timeout=30,
                check=False,
            )

        self.assertEqual(70, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertEqual("orchestrator-snapshot-cron: evidence_invalid\n", result.stderr)

    def test_each_failure_cause_prints_an_allowlisted_code_without_paths_or_secrets(self) -> None:
        allowed = {
            "clock_invalid",
            "snapshot_target_invalid",
            "snapshot_directory_unwritable",
            "evidence_invalid",
            "snapshot_build_failed",
            "snapshot_size_invalid",
            "temp_write_failed",
            "atomic_rename_failed",
            "internal_error",
        }
        observed: set[str] = set()

        with tempfile.TemporaryDirectory(prefix="controlbot-secret-path-") as directory:
            root = Path(directory)
            snapshot = root / "snapshot.json"
            cases = [
                self._run_refresh(snapshot, now=0),
                self._run_refresh(Path("relative.json")),
                self._run_refresh(root / "missing" / "snapshot.json"),
                self._run_refresh(
                    snapshot,
                    collector_php="static function (): array { throw new RuntimeException('secret=/private/token path=/srv/private'); }",
                ),
                self._run_refresh(snapshot, evidence={"work": "invalid"}),
                self._run_refresh(snapshot, max_bytes=10),
                self._run_refresh(
                    snapshot,
                    io_php="['tempnam' => static fn (string $path, string $prefix) => false]",
                ),
                self._run_refresh(
                    snapshot,
                    io_php=(
                        "['rename' => static fn (string $from, string $to): bool => false, "
                        "'write_target' => static fn (string $path, string $data) => false]"
                    ),
                ),
                self._run_refresh(snapshot, io_php="['tempnam' => 'not-callable']"),
            ]

            for result in cases:
                self.assertFalse(result["ok"], result)
                code = result["code"]
                self.assertIn(code, allowed)
                observed.add(code)
                rendered = json.dumps(result)
                self.assertNotIn(str(root), rendered)
                self.assertNotIn("secret", rendered.lower())
                self.assertNotIn("private/token", rendered)

        self.assertTrue(
            {
                "clock_invalid",
                "snapshot_target_invalid",
                "snapshot_directory_unwritable",
                "evidence_invalid",
                "snapshot_build_failed",
                "snapshot_size_invalid",
                "temp_write_failed",
                "atomic_rename_failed",
                "internal_error",
            }.issubset(observed),
            observed,
        )

    def test_unwritable_or_missing_directory_is_classified_and_keeps_previous_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            missing = self._run_refresh(root / "missing" / "snapshot.json")
            self.assertEqual(
                {"ok": False, "code": "snapshot_directory_unwritable"},
                missing,
            )

            snapshot = root / "orchestrator-live.json"
            previous = '{"previous":true}\n'
            snapshot.write_text(previous)
            original_mode = root.stat().st_mode & 0o777
            try:
                root.chmod(0o500)
                if os.access(root, os.W_OK):
                    self.skipTest("runtime user can still write to chmod 0500 directory")
                failed = self._run_refresh(snapshot)
            finally:
                root.chmod(original_mode)

            self.assertEqual(
                {"ok": False, "code": "snapshot_directory_unwritable"},
                failed,
            )
            self.assertEqual(previous, snapshot.read_text())

    def test_atomic_write_survives_chmod_or_rename_variations_on_shared_hosting(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)

            chmod_snapshot = root / "chmod.json"
            chmod_result = self._run_refresh(
                chmod_snapshot,
                io_php="['chmod' => static fn (string $path, int $mode): bool => false]",
            )
            self.assertTrue(chmod_result["ok"], chmod_result)
            self.assertTrue(chmod_snapshot.is_file())
            self.assertEqual(0o600, chmod_snapshot.stat().st_mode & 0o777)

            rename_snapshot = root / "rename.json"
            rename_result = self._run_refresh(
                rename_snapshot,
                io_php="['rename' => static fn (string $from, string $to): bool => false]",
            )
            self.assertTrue(rename_result["ok"], rename_result)
            self.assertTrue(rename_snapshot.is_file())
            decoded = json.loads(rename_snapshot.read_text())
            self.assertIn("fingerprint", decoded)
            self.assertEqual(
                rename_result["result"]["fingerprint"],
                decoded["fingerprint"],
            )


if __name__ == "__main__":
    unittest.main()

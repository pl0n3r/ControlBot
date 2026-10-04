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
RUNBOOK = ROOT / "docs/runbooks/orchestrator-snapshot-cron.md"


def base_environment() -> dict[str, str]:
    env = os.environ.copy()
    for key in (
        "CONTROLBOT_ORCHESTRATOR_CRON_ENABLED",
        "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH",
        "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH",
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

    def test_runbook_keeps_token_cron_hosting_and_live_activation_outside_repository_values(self) -> None:
        runbook = RUNBOOK.read_text()
        wrapper = SCRIPT.read_text()

        self.assertIn("fuera del repositorio", runbook)
        self.assertIn("apagado por defecto", runbook)
        self.assertIn("ControlBot #625", runbook)
        self.assertIn("no contiene una expresión de cron instalable", runbook)
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

        self.assertEqual(64, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertIn("invalid server configuration", result.stderr)


if __name__ == "__main__":
    unittest.main()

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
    def refresh_case(
        self,
        snapshot: Path,
        *,
        evidence=None,
        now=None,
        max_bytes=2_000_000,
        collector="$evidence",
        io="[]",
    ) -> dict:
        code = (
            "require $argv[1];$evidence=json_decode($argv[2],true);"
            f"$collector=static function() use ($evidence): array {{return {collector};}};"
            f"$io={io};try{{$r=\\ControlBot\\Business\\FactoryOrchestratorSnapshotRefresh::refresh("
            "$collector,$argv[3],(int)$argv[4],(int)$argv[5],$io);echo json_encode(['ok'=>1,'r'=>$r]);}"
            "catch(\\ControlBot\\Business\\FactoryOrchestratorSnapshotRefreshFailure $e){"
            "echo json_encode(['ok'=>0,'code'=>$e->failureCode()]);}"
        )
        result = subprocess.run(
            ["php", "-r", code, str(REFRESH), json.dumps(evidence or canonical_evidence(int(time.time()))),
             str(snapshot), str(now if now is not None else int(time.time())), str(max_bytes)],
            cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
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
                ["php", str(SCRIPT)], cwd=ROOT, env=env, text=True,
                capture_output=True, timeout=30, check=False,
            )
        self.assertEqual(70, result.returncode)
        self.assertEqual("orchestrator-snapshot-cron: evidence_invalid\n", result.stderr)

    def test_diagnostics_opt_in_reports_safe_directory_metadata_without_paths(self) -> None:
        with tempfile.TemporaryDirectory(prefix="private-diagnostics-") as directory:
            root = Path(directory)
            evidence_path = root / "evidence.json"
            snapshot_path = root / "orchestrator-live.json"
            evidence_path.write_text("[]")

            env = base_environment()
            env["CONTROLBOT_ORCHESTRATOR_CRON_ENABLED"] = "1"
            env["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"] = str(evidence_path)
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = str(snapshot_path)
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS"] = "1"

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
        self.assertRegex(
            result.stderr,
            r"^orchestrator-snapshot-cron: evidence_invalid "
            r"dir_exists=1 dir_writable=1 dir_owner_match=(?:0|1|unknown) "
            r"dir_mode=[0-7]{4}\n$",
        )
        self.assertNotIn(str(root), result.stderr)
        self.assertNotIn("evidence.json", result.stderr)

    def test_direct_cron_rejects_invalid_clock_target_and_non_array_evidence(self) -> None:
        source = ROOT / "src/FactoryOrchestratorSnapshotCron.php"
        code = (
            "require $argv[1];"
            "$cases=["
            "[0,'/tmp/controlbot-clock.json',static fn()=>[]],"
            "[1,'relative.json',static fn()=>[]],"
            "[1,'/tmp/controlbot-evidence.json',static fn()=>null]"
            "];$out=[];foreach($cases as [$now,$path,$collector]){"
            "try{\\ControlBot\\Business\\FactoryOrchestratorSnapshotCron::run("
            "['CONTROLBOT_ORCHESTRATOR_CRON_ENABLED'=>'1'],$collector,$path,$now);"
            "$out[]='ok';"
            "}catch(\\ControlBot\\Business\\FactoryOrchestratorSnapshotRefreshFailure $e){"
            "$out[]=$e->failureCode();}}echo json_encode($out);"
        )
        result = subprocess.run(
            ["php", "-r", code, str(source)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=30,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            ["clock_invalid", "snapshot_target_invalid", "evidence_invalid"],
            json.loads(result.stdout),
        )

    def test_fallback_failure_restores_or_removes_partial_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            previous = root / "previous.json"
            previous.write_text("previous\n")
            failure = self.refresh_case(
                previous,
                io=(
                    "['rename'=>static fn($a,$b)=>false,"
                    "'write_target'=>static function($p,$d){"
                    "file_put_contents($p,'corrupt',LOCK_EX);return 7;}]"
                ),
            )
            self.assertEqual("atomic_rename_failed", failure["code"])
            self.assertEqual("previous\n", previous.read_text())

            fresh = root / "fresh.json"
            failure = self.refresh_case(
                fresh,
                io=(
                    "['rename'=>static fn($a,$b)=>false,"
                    "'write_target'=>static function($p,$d){"
                    "file_put_contents($p,'corrupt',LOCK_EX);return false;}]"
                ),
            )
            self.assertEqual("atomic_rename_failed", failure["code"])
            self.assertFalse(fresh.exists())

    def test_temp_write_mode_and_final_verification_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.assertEqual(
                "temp_write_failed",
                self.refresh_case(
                    root / "short-write.json",
                    io="['write_temp'=>static fn($p,$d)=>false]",
                )["code"],
            )
            self.assertEqual(
                "temp_write_failed",
                self.refresh_case(
                    root / "unsafe-mode.json",
                    io=(
                        "['chmod'=>static function($p,$m){"
                        "chmod($p,0666);return false;}]"
                    ),
                )["code"],
            )
            self.assertEqual(
                "atomic_rename_failed",
                self.refresh_case(
                    root / "bad-final-size.json",
                    io=(
                        "['rename'=>static function($a,$b){"
                        "file_put_contents($b,'x');unlink($a);return true;}]"
                    ),
                )["code"],
            )

    def test_each_failure_cause_prints_an_allowlisted_code_without_paths_or_secrets(self) -> None:
        with tempfile.TemporaryDirectory(prefix="private-secret-") as directory:
            root = Path(directory); snapshot = root / "snapshot.json"
            cases = [
                self.refresh_case(snapshot, now=0),
                self.refresh_case(Path("relative.json")),
                self.refresh_case(root / "missing" / "snapshot.json"),
                self.refresh_case(snapshot, collector="throw new RuntimeException('secret /private/path')"),
                self.refresh_case(snapshot, evidence={"work": "invalid"}),
                self.refresh_case(snapshot, max_bytes=10),
                self.refresh_case(snapshot, io="['tempnam'=>static fn($d,$p)=>false]"),
                self.refresh_case(snapshot, io="['rename'=>static fn($a,$b)=>false,'write_target'=>static fn($p,$d)=>false]"),
                self.refresh_case(snapshot, io="['tempnam'=>'not-callable']"),
            ]
            codes = {case["code"] for case in cases if not case["ok"]}
            self.assertEqual({"clock_invalid", "snapshot_target_invalid", "snapshot_directory_unwritable",
                              "evidence_invalid", "snapshot_build_failed", "snapshot_size_invalid",
                              "temp_write_failed", "atomic_rename_failed", "internal_error"}, codes)
            self.assertNotIn(str(root), json.dumps(cases)); self.assertNotIn("secret", json.dumps(cases).lower())
        wrapper = SCRIPT.read_text()
        self.assertIn("failureCode()", wrapper); self.assertNotIn("getMessage()", wrapper)

    def test_unwritable_or_missing_directory_is_classified_and_keeps_previous_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.assertEqual("snapshot_directory_unwritable", self.refresh_case(root / "missing/x.json")["code"])
            snapshot = root / "snapshot.json"; snapshot.write_text("previous\n")
            mode = root.stat().st_mode & 0o777
            try:
                root.chmod(0o500)
                if os.access(root, os.W_OK): self.skipTest("chmod cannot make temp directory unwritable")
                self.assertEqual("snapshot_directory_unwritable", self.refresh_case(snapshot)["code"])
            finally:
                root.chmod(mode)
            self.assertEqual("previous\n", snapshot.read_text())

    def test_atomic_write_survives_chmod_or_rename_variations_on_shared_hosting(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            chmod_snapshot = root / "chmod.json"
            self.assertTrue(self.refresh_case(chmod_snapshot, io="['chmod'=>static fn($p,$m)=>false]")["ok"])
            self.assertEqual(0o600, chmod_snapshot.stat().st_mode & 0o777)
            rename_snapshot = root / "rename.json"
            result = self.refresh_case(rename_snapshot, io="['rename'=>static fn($a,$b)=>false]")
            self.assertTrue(result["ok"]); self.assertEqual(result["r"]["fingerprint"], json.loads(rename_snapshot.read_text())["fingerprint"])


    def test_cron_classifies_invalid_clock_and_target_before_refresh(self) -> None:
        source = ROOT / "src/FactoryOrchestratorSnapshotCron.php"

        def invoke(snapshot: str, now: int) -> str:
            code = (
                "require $argv[1];"
                "try{\\ControlBot\\Business\\FactoryOrchestratorSnapshotCron::run("
                "['CONTROLBOT_ORCHESTRATOR_CRON_ENABLED'=>'1'],"
                "static fn():array=>[],$argv[2],(int)$argv[3]);echo 'ok';}"
                "catch(\\ControlBot\\Business\\FactoryOrchestratorSnapshotRefreshFailure $e){"
                "echo $e->failureCode();}"
            )
            result = subprocess.run(
                ["php", "-r", code, str(source), snapshot, str(now)],
                cwd=ROOT, text=True, capture_output=True, timeout=30, check=False,
            )
            self.assertEqual(0, result.returncode, result.stderr)
            return result.stdout

        self.assertEqual("clock_invalid", invoke("/tmp/controlbot-clock.json", 0))
        self.assertEqual("snapshot_target_invalid", invoke("relative.json", 1))

    def test_diagnostics_are_opt_in_bounded_and_never_expose_paths(self) -> None:
        with tempfile.TemporaryDirectory(prefix="private-secret-") as directory:
            root = Path(directory)
            evidence_path = root / "invalid-evidence.json"
            evidence_path.write_text("{broken-json")
            snapshot_path = root / "snapshot.json"

            env = base_environment()
            env["CONTROLBOT_ORCHESTRATOR_CRON_ENABLED"] = "1"
            env["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"] = str(evidence_path)
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = str(snapshot_path)
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS"] = "1"

            result = subprocess.run(
                ["php", str(SCRIPT)], cwd=ROOT, env=env, text=True,
                capture_output=True, timeout=30, check=False,
            )
            self.assertEqual(70, result.returncode)
            self.assertRegex(
                result.stderr,
                r"^orchestrator-snapshot-cron: evidence_invalid "
                r"dir_exists=1 dir_writable=[01] "
                r"dir_owner_match=(?:0|1|unknown) dir_mode=(?:[0-7]{4}|unknown)\n$",
            )
            self.assertNotIn(str(root), result.stderr)
            self.assertNotIn("broken-json", result.stderr)

            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = str(root / "missing" / "snapshot.json")
            missing = subprocess.run(
                ["php", str(SCRIPT)], cwd=ROOT, env=env, text=True,
                capture_output=True, timeout=30, check=False,
            )
            self.assertEqual(70, missing.returncode)
            self.assertIn(
                "dir_exists=0 dir_writable=0 dir_owner_match=unknown dir_mode=unknown",
                missing.stderr,
            )
            self.assertNotIn(str(root), missing.stderr)

    def test_wrapper_rejects_invalid_evidence_shapes_and_relative_target(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            evidence_path = root / "evidence.json"
            snapshot_path = root / "snapshot.json"

            env = base_environment()
            env["CONTROLBOT_ORCHESTRATOR_CRON_ENABLED"] = "1"
            env["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"] = str(evidence_path)
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = str(snapshot_path)

            evidence_path.write_text("[]")
            listed = subprocess.run(
                ["php", str(SCRIPT)], cwd=ROOT, env=env, text=True,
                capture_output=True, timeout=30, check=False,
            )
            self.assertEqual(70, listed.returncode)
            self.assertEqual("orchestrator-snapshot-cron: evidence_invalid\n", listed.stderr)

            evidence_path.write_text(" ")
            tiny = subprocess.run(
                ["php", str(SCRIPT)], cwd=ROOT, env=env, text=True,
                capture_output=True, timeout=30, check=False,
            )
            self.assertEqual(70, tiny.returncode)
            self.assertEqual("orchestrator-snapshot-cron: evidence_invalid\n", tiny.stderr)

            evidence_path.write_text(json.dumps(canonical_evidence(int(time.time()))))
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"] = "relative.json"
            relative = subprocess.run(
                ["php", str(SCRIPT)], cwd=ROOT, env=env, text=True,
                capture_output=True, timeout=30, check=False,
            )
            self.assertEqual(70, relative.returncode)
            self.assertEqual("orchestrator-snapshot-cron: snapshot_target_invalid\n", relative.stderr)

    def test_refresh_io_failures_cleanup_and_restore_previous_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            snapshot = root / "snapshot.json"
            snapshot.write_text("previous\n")

            short_write = self.refresh_case(
                snapshot,
                io="['write_temp'=>static fn($p,$d)=>0]",
            )
            self.assertEqual("temp_write_failed", short_write["code"])
            self.assertEqual("previous\n", snapshot.read_text())
            self.assertEqual([], list(root.glob(".orchestrator-live-*")))

            unsafe_mode = self.refresh_case(
                snapshot,
                io=(
                    "['tempnam'=>static function($d,$p){"
                    "$f=$d.'/unsafe.tmp';file_put_contents($f,'');chmod($f,0666);return $f;},"
                    "'chmod'=>static fn($p,$m)=>false]"
                ),
            )
            self.assertEqual("temp_write_failed", unsafe_mode["code"])
            self.assertFalse((root / "unsafe.tmp").exists())
            self.assertEqual("previous\n", snapshot.read_text())

            rollback = self.refresh_case(
                snapshot,
                io=(
                    "['rename'=>static fn($a,$b)=>false,"
                    "'write_target'=>static fn($p,$d)=>file_put_contents($p,'broken',LOCK_EX)]"
                ),
            )
            self.assertEqual("atomic_rename_failed", rollback["code"])
            self.assertEqual("previous\n", snapshot.read_text())

            absent = root / "absent.json"
            no_previous = self.refresh_case(
                absent,
                io=(
                    "['rename'=>static fn($a,$b)=>false,"
                    "'write_target'=>static fn($p,$d)=>file_put_contents($p,'broken',LOCK_EX)]"
                ),
            )
            self.assertEqual("atomic_rename_failed", no_previous["code"])
            self.assertFalse(absent.exists())

            internal = self.refresh_case(
                snapshot,
                io="['tempnam'=>static function($d,$p){throw new Error('secret path');}]",
            )
            self.assertEqual("internal_error", internal["code"])
            self.assertNotIn("secret", json.dumps(internal).lower())

    def test_refresh_rejects_unsafe_targets_and_invalid_limits(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.assertEqual(
                "snapshot_target_invalid",
                self.refresh_case(root, now=int(time.time()))["code"],
            )
            self.assertEqual(
                "snapshot_target_invalid",
                self.refresh_case(root / "limit.json", max_bytes=2_000_001)["code"],
            )

            real = root / "real.json"
            real.write_text("previous\n")
            linked = root / "linked.json"
            try:
                linked.symlink_to(real)
            except (OSError, NotImplementedError):
                self.skipTest("symlinks unavailable")
            self.assertEqual(
                "snapshot_target_invalid",
                self.refresh_case(linked)["code"],
            )


if __name__ == "__main__":
    unittest.main()

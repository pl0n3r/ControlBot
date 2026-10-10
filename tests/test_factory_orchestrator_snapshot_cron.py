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
        "CONTROLBOT_ORCHESTRATOR_COLLECTOR_RESULT",
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
    def refresh_case(self, snapshot: Path, *, evidence=None, now=None, max_bytes=2_000_000, collector="$evidence", io="[]") -> dict:
        code = (
            "require $argv[1];$evidence=json_decode($argv[2],true);"
            f"$collector=static function() use ($evidence) {{return {collector};}};$io={io};"
            "try{$r=\\ControlBot\\Business\\FactoryOrchestratorSnapshotRefresh::refresh("
            "$collector,$argv[3],(int)$argv[4],(int)$argv[5],$io);echo json_encode(['ok'=>1,'r'=>$r]);}"
            "catch(\\ControlBot\\Business\\FactoryOrchestratorSnapshotRefreshFailure $e){"
            "echo json_encode(['ok'=>0,'code'=>$e->failureCode()]);}"
        )
        with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as driver:
            driver.write("<?php\n" + code)
            driver_path = Path(driver.name)
        try:
            result = subprocess.run(
                ["php",str(driver_path),str(REFRESH),json.dumps(evidence or canonical_evidence(int(time.time()))),
                 str(snapshot),str(now if now is not None else int(time.time())),str(max_bytes)],
                cwd=ROOT,text=True,capture_output=True,timeout=30,check=False,
            )
        finally:
            driver_path.unlink(missing_ok=True)
        self.assertEqual(0,result.returncode,result.stderr)
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
            stale_payload = json.loads(snapshot_path.read_text())

        self.assertEqual(70, failed.returncode)
        self.assertEqual("stale", json.loads(failed.stdout)["state"])
        self.assertEqual("orchestrator-snapshot-cron: stale_evidence_invalid\n", failed.stderr)
        self.assertEqual(stale_payload["collector_failure"]["reason"], "evidence_invalid")

    def test_explicit_failed_collector_cannot_reuse_old_evidence_as_success(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            evidence, snapshot = root / "evidence.json", root / "snapshot.json"
            evidence.write_text(json.dumps(canonical_evidence(int(time.time()))))
            env = base_environment() | {
                "CONTROLBOT_ORCHESTRATOR_CRON_ENABLED": "1",
                "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH": str(evidence),
                "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH": str(snapshot),
                "CONTROLBOT_ORCHESTRATOR_COLLECTOR_RESULT": "failed",
            }
            run = subprocess.run(["php", str(SCRIPT)], cwd=ROOT, env=env,
                                 text=True, capture_output=True, timeout=30)
            payload = json.loads(snapshot.read_text())
        self.assertEqual((run.returncode, json.loads(run.stdout)["state"]), (70, "stale"))
        self.assertEqual(payload["collector_failure"]["reason"], "collector_failed")
        self.assertEqual(payload["sections"]["work"][0]["freshness"], "unknown")
        self.assertNotIn("Bearer", run.stderr)

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
            stale_payload = json.loads((root / "snapshot.json").read_text())

        self.assertEqual(70, result.returncode)
        self.assertEqual(json.loads(result.stdout)["state"], "stale")
        self.assertEqual(stale_payload["collector_failure"]["reason"], "evidence_invalid")
        self.assertEqual("orchestrator-snapshot-cron: stale_evidence_invalid\n", result.stderr)


    def test_each_failure_cause_prints_an_allowlisted_code_without_paths_or_secrets(self) -> None:
        with tempfile.TemporaryDirectory(prefix="private-secret-") as directory:
            root=Path(directory); snapshot=root/"snapshot.json"
            cases=[
                self.refresh_case(snapshot,now=0), self.refresh_case(Path("relative.json")),
                self.refresh_case(root/"missing"/"x.json"),
                self.refresh_case(snapshot,collector="throw new RuntimeException('secret /private/path')"),
                self.refresh_case(snapshot,collector="'not-array'"),
                self.refresh_case(snapshot,evidence={"work":"invalid"}), self.refresh_case(snapshot,max_bytes=10),
                self.refresh_case(snapshot,io="['tempnam'=>static fn($d,$p)=>false]"), self.refresh_case(snapshot,io="['write_temp'=>static fn($p,$d)=>false]"), self.refresh_case(snapshot,io="['tempnam'=>static function($d,$p){throw new Error('boom');}]"),
                self.refresh_case(snapshot,io="['rename'=>static fn($a,$b)=>false,'write_target'=>static fn($p,$d)=>false]"), self.refresh_case(root/"fresh.json",io="['rename'=>static fn($a,$b)=>false,'write_target'=>static function($p,$d){file_put_contents($p,'x');return false;}]"),
                self.refresh_case(snapshot,io="['tempnam'=>'not-callable']"), self.refresh_case(snapshot,io="['tempnam'=>static function($d,$p){$f=$d.'/unsafe.tmp';file_put_contents($f,'');chmod($f,0666);return $f;},'chmod'=>static fn($p,$m)=>false]"),
            ]
            self.assertEqual(
                {"clock_invalid","snapshot_target_invalid","snapshot_directory_unwritable","evidence_invalid",
                 "snapshot_build_failed","snapshot_size_invalid","temp_write_failed","atomic_rename_failed","internal_error"},
                {case["code"] for case in cases if not case["ok"]},
            )
            evidence=root/"evidence.json"; evidence.write_text("[]")
            env=base_environment()|{"CONTROLBOT_ORCHESTRATOR_CRON_ENABLED":"1",
                "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH":str(evidence),
                "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH":str(snapshot),
                "CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS":"1"}
            cli=subprocess.run(["php",str(SCRIPT)],cwd=ROOT,env=env,text=True,capture_output=True,timeout=30,check=False)
            self.assertRegex(cli.stderr,r"^orchestrator-snapshot-cron: stale_evidence_invalid dir_exists=1 dir_writable=1 dir_owner_match=(?:0|1|unknown) dir_mode=[0-7]{4}\n$")
            direct=subprocess.run(["php","-r","require $argv[1];$o=[(new \\ControlBot\\Business\\FactoryOrchestratorSnapshotRefreshFailure('secret'))->failureCode()];foreach([[0,'/tmp/x'],[1,'relative']] as [$n,$p]){try{\\ControlBot\\Business\\FactoryOrchestratorSnapshotCron::run(['CONTROLBOT_ORCHESTRATOR_CRON_ENABLED'=>'1'],static fn()=>[],$p,$n);$o[]='ok';}catch(\\ControlBot\\Business\\FactoryOrchestratorSnapshotRefreshFailure $e){$o[]=$e->failureCode();}}echo json_encode($o);",str(ROOT/"src/FactoryOrchestratorSnapshotCron.php")],cwd=ROOT,text=True,capture_output=True,timeout=30,check=False)
            self.assertEqual(["internal_error","clock_invalid","snapshot_target_invalid"],json.loads(direct.stdout))
            env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"]=str(root/"missing"/"snapshot.json"); missing=subprocess.run(["php",str(SCRIPT)],cwd=ROOT,env=env,text=True,capture_output=True,timeout=30,check=False)
            self.assertIn("dir_exists=0 dir_writable=0 dir_owner_match=unknown dir_mode=unknown",missing.stderr)
            self.assertNotIn(str(root),json.dumps(cases)+cli.stderr+missing.stderr); self.assertNotIn("secret",json.dumps(cases).lower())

    def test_unwritable_or_missing_directory_is_classified_and_keeps_previous_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            self.assertEqual("snapshot_directory_unwritable",self.refresh_case(root/"missing"/"x.json")["code"])
            snapshot=root/"snapshot.json"; snapshot.write_text("previous\n"); mode=root.stat().st_mode&0o777
            try:
                root.chmod(0o500)
                if os.access(root,os.W_OK): self.skipTest("chmod cannot make directory unwritable")
                self.assertEqual("snapshot_directory_unwritable",self.refresh_case(snapshot)["code"])
            finally:
                root.chmod(mode)
            self.assertEqual("previous\n",snapshot.read_text())

    def test_atomic_write_survives_chmod_or_rename_variations_on_shared_hosting(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); chmod_snapshot=root/"chmod.json"
            self.assertTrue(self.refresh_case(chmod_snapshot,io="['chmod'=>static fn($p,$m)=>false]")["ok"])
            rename_snapshot=root/"rename.json"
            result=self.refresh_case(rename_snapshot,io="['rename'=>static fn($a,$b)=>false]")
            self.assertTrue(result["ok"]); self.assertEqual(result["r"]["fingerprint"],json.loads(rename_snapshot.read_text())["fingerprint"])
            previous=root/"previous.json"; previous.write_text("previous\n")
            failed=self.refresh_case(previous,io="['rename'=>static fn($a,$b)=>false,'write_target'=>static fn($p,$d)=>false]")
            self.assertEqual("atomic_rename_failed",failed["code"]); self.assertEqual("previous\n",previous.read_text())


    def test_refresh_fallback_and_target_edges_are_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); snapshot=root/"snapshot.json"; snapshot.write_text("previous\n")
            cases=[
                ("internal_error",self.refresh_case(snapshot,io="['rename'=>static fn($a,$b)=>false,'write_target'=>'not-callable']")),
                ("temp_write_failed",self.refresh_case(snapshot,io="['write_temp'=>static function($p,$d){file_put_contents($p,'x');return strlen($d);}]")),
                ("snapshot_target_invalid",self.refresh_case(root/"small.json",max_bytes=1)),
                ("snapshot_target_invalid",self.refresh_case(root/"large.json",max_bytes=2_000_001)),
                ("snapshot_target_invalid",self.refresh_case(root)),
            ]
            self.assertTrue(all(result["code"]==expected for expected,result in cases)); self.assertEqual("previous\n",snapshot.read_text())
            self.assertEqual([],list(root.glob(".orchestrator-live-*")))
            absent=root/"absent.json"; partial=self.refresh_case(absent,io="['rename'=>static fn($a,$b)=>false,'write_target'=>static function($p,$d){file_put_contents($p,'broken');return false;}]")
            self.assertEqual("atomic_rename_failed",partial["code"]); self.assertFalse(absent.exists())
            mismatch=root/"mismatch.json"; verified=self.refresh_case(mismatch,io="['rename'=>static function($from,$to){file_put_contents($to,'x');@unlink($from);return true;}]")
            self.assertEqual("atomic_rename_failed",verified["code"]); self.assertEqual("x",mismatch.read_text())
            real=root/"real.json"; real.write_text("previous\n"); linked=root/"linked.json"
            try: linked.symlink_to(real)
            except (OSError,NotImplementedError): self.skipTest("symlinks unavailable")
            self.assertEqual("snapshot_target_invalid",self.refresh_case(linked)["code"]); linked.unlink()
            race=root/"race.json"; raced=self.refresh_case(race,io="['rename'=>static function($from,$to){@unlink($to);symlink($from,$to);return false;}]")
            self.assertEqual("atomic_rename_failed",raced["code"]); self.assertTrue(race.is_symlink()); race.unlink()

    def test_wrapper_edges_and_cron_io_stay_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); snapshot=root/"snapshot.json"; env=base_environment()|{"CONTROLBOT_ORCHESTRATOR_CRON_ENABLED":"1","CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH":str(snapshot)}
            def cli(path: Path, target: str|None=None):
                env["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"]=str(path); env["CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH"]=target or str(snapshot)
                return subprocess.run(["php",str(SCRIPT)],cwd=ROOT,env=env,text=True,capture_output=True,timeout=30,check=False)
            tiny=root/"tiny.json"; tiny.write_text(" "); self.assertEqual("orchestrator-snapshot-cron: stale_evidence_invalid\n",cli(tiny).stderr)
            oversized=root/"oversized.json"; oversized.write_text('{"payload":"'+("x"*2_000_000)+'"}'); self.assertEqual("orchestrator-snapshot-cron: stale_evidence_invalid\n",cli(oversized).stderr)
            evidence=root/"evidence.json"; evidence.write_text(json.dumps(canonical_evidence(int(time.time())))); link=root/"evidence-link.json"
            try: link.symlink_to(evidence)
            except (OSError,NotImplementedError): self.skipTest("symlinks unavailable")
            self.assertEqual("orchestrator-snapshot-cron: stale_evidence_invalid\n",cli(link).stderr); link.unlink()
            self.assertEqual("orchestrator-snapshot-cron: snapshot_target_invalid\n",cli(evidence,"relative.json").stderr)
            source=ROOT/"src/FactoryOrchestratorSnapshotCron.php"; code=("require $argv[1];$e=json_decode($argv[2],true);$r=\\ControlBot\\Business\\FactoryOrchestratorSnapshotCron::run(['CONTROLBOT_ORCHESTRATOR_CRON_ENABLED'=>'1'],static fn()=> $e,$argv[3],(int)$argv[4],['rename'=>static fn($a,$b)=>false]);echo json_encode($r);")
            run=subprocess.run(["php","-r",code,str(source),json.dumps(canonical_evidence(int(time.time()))),str(snapshot),str(int(time.time()))],cwd=ROOT,text=True,capture_output=True,timeout=30,check=False)
            self.assertEqual(0,run.returncode,run.stderr); payload=json.loads(run.stdout); self.assertTrue(payload["executed"] and payload["written"]); self.assertEqual("refreshed",payload["state"])


if __name__ == "__main__":
    unittest.main()

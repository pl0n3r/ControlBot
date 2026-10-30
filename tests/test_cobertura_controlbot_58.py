"""ControlBot #1241: offline evidence for orchestrator entrypoint contracts.

Runs production PHP with synthetic in-memory/local fixtures. Never introspects
coverage-matrix state, treats a runbook as executable authority, or uses live I/O.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = ROOT / "tests" / "factory_orchestrator_web_entrypoint_scenarios.php"

# This probe calls the checked-in PHP classes directly. PHP's local temporary
# directory is the only writable source; no site, browser, database or socket.
PATH_PROBE = r"""
require_once 'src/FactoryLiveSnapshot.php';
require_once 'src/FactoryOrchestratorWebEntrypoint.php';
use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryOrchestratorWebEntrypoint;

$now = 1800000000;
$dir = sys_get_temp_dir() . '/cb-1241-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('tempdir unavailable');
$valid = $dir . '/valid.json';
$linked = $dir . '/linked.json';
$view = FactoryLiveSnapshot::build(['work'=>[[
  'id'=>'work:controlbot-656',
  'authority'=>'github_project_snapshot',
  'state'=>'pending',
  'source_ref'=>'github:pl0n3r/ControlBot#656',
  'observed_at'=>$now-5,
  'freshness'=>'current',
  'data'=>[
    'repository_ref'=>'pl0n3r/ControlBot',
    'issue_ref'=>'github:pl0n3r/ControlBot#656',
    'status'=>'reserved',
    'progress_percent'=>25,
    'progress_evidence'=>'github:pl0n3r/ControlBot#656'
  ]
]]], $now);
file_put_contents($valid, json_encode($view, JSON_THROW_ON_ERROR));
if (!symlink($valid, $linked)) throw new RuntimeException('symlink unavailable');
$server = [
  'REQUEST_METHOD'=>'GET',
  'REQUEST_URI'=>'/api/orchestrator-live',
  'REMOTE_USER'=>'synthetic-owner',
  'DOCUMENT_ROOT'=>getcwd() . '/public',
];
$base = ['CONTROLBOT_OWNER_LOGIN'=>'synthetic-owner'];
$sources = [
  'valid'=>$valid,
  'relative'=>'relative.json',
  'traversal'=>$dir . '/../other.json',
  'inside_repo'=>getcwd() . '/var/missing.json',
  'inside_docroot'=>getcwd() . '/public/missing.json',
  'symlink'=>$linked,
  'nul'=>"/tmp/bad\0name.json",
  'missing'=>$dir . '/absent.json',
];
$results = [];
try {
  foreach ($sources as $name=>$source) {
    $response = FactoryOrchestratorWebEntrypoint::handle(
      $server, $base + ['CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH'=>$source],
      $now
    );
    $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    $results[$name] = [
      'status'=>$response['status'],
      'activity'=>$payload['snapshot']['central']['activity_state'] ?? null,
      'fronts'=>$payload['snapshot']['fronts'] ?? null,
      'fingerprint'=>$payload['snapshot']['fingerprint'] ?? null,
      'read_only'=>$payload['read_only'] ?? null,
      'cache_status'=>$payload['cache_status'] ?? null,
      'cache_control'=>$response['headers']['Cache-Control'] ?? null,
    ];
  }
} finally {
  foreach ($sources as $source) {
    $cache = sys_get_temp_dir() . '/controlbot-orchestrator-live-' .
             hash('sha256', $source) . '.json';
    if (is_file($cache)) unlink($cache);
  }
  unlink($linked);
  unlink($valid);
  rmdir($dir);
}
echo json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
"""


def _php(*args: str) -> dict:
    binary = shutil.which("php")
    if not binary:
        raise AssertionError("UNKNOWN: PHP CLI not available")
    # Prevent accidental inheritance of credentials; retain sanctioned
    # coverage bootstrap variables so Sonar sees the actual PHP code exercised.
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    for key in (
        "CONTROLBOT_REAL_PHP",
        "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
        "CONTROLBOT_PHP_COVERAGE_DIR",
        "CONTROLBOT_REPO_ROOT",
        "XDEBUG_MODE",
    ):
        if key in os.environ:
            env[key] = os.environ[key]
    with tempfile.TemporaryDirectory(prefix="cb-1241-home-") as home:
        env["HOME"] = home
        result = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
             "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,"
             "pfsockopen,stream_socket_client,shell_exec,system,exec,"
             "passthru,proc_open,popen",
             *args],
            cwd=ROOT, env=env, text=True, capture_output=True,
            timeout=60, check=False,
        )
    if result.returncode:
        raise AssertionError(
            f"UNKNOWN: PHP scenario failed ({result.returncode}): "
            f"{result.stderr[-450:]}"
        )
    try:
        parsed = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: invalid PHP JSON output") from exc
    if not isinstance(parsed, dict):
        raise AssertionError("UNKNOWN: PHP did not return an object")
    return parsed


@lru_cache(maxsize=6)
def scenario(name: str) -> dict:
    if name not in {"owner", "json", "auth", "unknown", "paths"}:
        raise AssertionError("UNKNOWN: unsupported test case")
    if name == "paths":
        return _php("-r", PATH_PROBE)
    if not SCENARIOS.is_file():
        raise AssertionError("UNKNOWN: original PHP fixture unavailable")
    return _php(str(SCENARIOS), name)


class CoberturaControlbot58Tests(unittest.TestCase):
    """AC-01..04; B/C claims are recorded on the PR, not mocked as live."""

    def test_contrato_principal(self) -> None:
        # CB-0963: the inner runtime is GET-only, read-only; it requires
        # owner identity. The public bootstrap additionally checks real
        # owner session state and does NOT trust raw REMOTE_USER.
        owner = scenario("owner")
        response = scenario("json")
        self.assertEqual(owner["status"], 200)
        self.assertIn("Orquestador en vivo", owner["body"])
        self.assertEqual(response["status"], 200)
        self.assertEqual(response["headers"]["Cache-Control"], "no-store")
        payload = json.loads(response["body"])
        self.assertIs(payload["read_only"], True)
        self.assertEqual(payload["snapshot"]["fronts"][0]["status"], "reserved")
        self.assertEqual(
            payload["snapshot"]["fronts"][0]["repository_ref"],
            "pl0n3r/ControlBot",
        )

        # CB-0965/0967: positive private absolute path vs various rejected
        # configurations; local cache key is isolated by the source path.
        paths = scenario("paths")
        good = paths["valid"]
        self.assertEqual(good["status"], 200)
        self.assertIs(good["read_only"], True)
        self.assertEqual(good["activity"], "UNKNOWN")  # No work_inventory in fixture
        self.assertEqual(good["fronts"][0]["status"], "reserved")
        self.assertIn(good["cache_status"], {"refreshed", "fresh"})
        self.assertEqual(good["cache_control"], "no-store")
        for key in ("relative", "traversal", "inside_repo",
                    "inside_docroot", "symlink", "nul", "missing"):
            with self.subTest(source=key):
                item = paths[key]
                self.assertEqual(item["status"], 200)
                self.assertIs(item["read_only"], True)
                self.assertEqual(item["activity"], "UNKNOWN")
                self.assertEqual(item["fronts"], [])
                self.assertNotEqual(item["fingerprint"], good["fingerprint"])

        # CB-0966/0973: actual localSnapshot rejects incomplete and
        # aged evidence; absent evidence never becomes synthetic HEALTH.
        for case, row in scenario("unknown").items():
            with self.subTest(snapshot=case):
                self.assertEqual(row["status"], 200)
                projection = json.loads(row["body"])
                self.assertIs(projection["read_only"], True)
                self.assertEqual(
                    projection["snapshot"]["central"]["activity_state"],
                    "UNKNOWN",
                )
                self.assertEqual(projection["snapshot"]["fronts"], [])
                self.assertEqual(
                    projection["snapshot"]["owner_decisions"], [],
                )

    def test_falla_cerrado(self) -> None:
        # CB-0963/0971: no owner, wrong owner or no server-side config
        # cannot disclose the projection. Actual public HTTP protection
        # requires independent Hostinger evidence (B).
        guards = scenario("auth")
        self.assertEqual(guards["missing"]["status"], 503)
        self.assertEqual(guards["wrong"]["status"], 403)
        self.assertEqual(guards["config"]["status"], 503)
        for row in guards.values():
            with self.subTest(status=row["status"]):
                self.assertEqual(row["headers"]["Cache-Control"], "no-store")
                self.assertNotIn("reserved", row["body"].lower())
                self.assertNotIn("fingerprint", row["body"].lower())
        for name in ("relative", "traversal", "inside_repo",
                     "inside_docroot", "symlink", "nul"):
            self.assertEqual(scenario("paths")[name]["activity"], "UNKNOWN")

    def test_sin_datos_reales(self) -> None:
        # All visible evidence comes from the checked-in synthetic PHP
        # fixture or a temporary file; PHP network primitives are disabled.
        rows = scenario("paths")
        self.assertEqual(len(rows), 8)
        for row in rows.values():
            self.assertIs(row["read_only"], True)
            self.assertEqual(row["cache_control"], "no-store")
            self.assertNotIn("password", json.dumps(row).lower())
            self.assertNotIn("authorization", json.dumps(row).lower())
        payload = json.loads(scenario("json")["body"])
        self.assertIs(payload["snapshot"]["read_only"], True)
        self.assertEqual(payload["snapshot"]["fronts"][0]["progress_percent"], 25)

    def test_gate_validar_presente(self) -> None:
        # GitHub's required Validar check is validated against exact PR
        # HEAD outside this test; Python must not fabricate a green check.
        guards = scenario("auth")
        self.assertEqual(guards["missing"]["status"], 503)
        self.assertEqual(guards["wrong"]["status"], 403)
        self.assertEqual(scenario("paths")["valid"]["fronts"][0]["status"], "reserved")


if __name__ == "__main__":
    unittest.main()

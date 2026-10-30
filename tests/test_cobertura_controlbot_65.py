#!/usr/bin/env python3
"""#1248: offline PHP behavior for ControlBot CB-1073..1089.

The PHP owner-login gate and staff scenario fixtures are executed, not mocked.
B/C rows requiring live maintenance or documenting policy are NOT marked covered.
Never read the coverage matrix or modify actual infrastructure.
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
STAFF_CASES = {
    "summary", "search", "invalid", "invite", "mutations",
    "ambiguous", "authority", "audit",
}

# Synthetic-only HTTP calls to the shipping PHP owner gate. The private directory
# and generated Argon2id verifier exist only for the duration of this PHP process.
OWNER_PHP = r"""<?php
declare(strict_types=1);
require 'src/OwnerLoginGate.php';
use ControlBot\Security\OwnerLoginGate;

$now = 1800000000;
$hash = password_hash('synthetic-password-only', PASSWORD_ARGON2ID,
    ['memory_cost' => 4096, 'time_cost' => 1, 'threads' => 1]);
$dir = getenv('CONTROLBOT_TEST_LIMIT_DIR');
$config = ['owner_login' => 'fixture-owner', 'password_hash' => $hash,
    'rate_limit_dir' => $dir];
$get = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/login',
    'REMOTE_ADDR' => '198.51.100.11'];
$post = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login',
    'REMOTE_ADDR' => '198.51.100.11'];
$session = [];
OwnerLoginGate::handle($get, [], $session, $config, $now);
$csrf = $session['_cb_login_csrf'];
$credentials = ['csrf' => $csrf, 'username' => 'fixture-owner',
    'password' => 'synthetic-password-only'];
$attacker = $post;
$attacker['REMOTE_ADDR'] = '198.51.100.99';
$attackerStatuses = [];
for ($i = 0; $i < 5; $i++) {
    $r = OwnerLoginGate::handle($attacker,
        ['password' => 'invalid-synthetic'] + $credentials,
        $session, $config, $now);
    $attackerStatuses[] = $r['status'];
}
$blocked = OwnerLoginGate::handle($attacker, $credentials, $session, $config, $now);
$owner = OwnerLoginGate::handle($post, $credentials, $session, $config, $now);

$nat = $post;
$nat['REMOTE_ADDR'] = '192.0.2.44';
$natSession = [];
OwnerLoginGate::handle($get, [], $natSession, $config, $now);
$natCreds = ['csrf' => $natSession['_cb_login_csrf'],
    'username' => 'any-identity', 'password' => 'invalid-synthetic'];
for ($i = 0; $i < 3; $i++) {
    OwnerLoginGate::handle($nat, $natCreds, $natSession, $config, $now);
}
$natCreds['username'] = 'another-identity';
$natBlocked = OwnerLoginGate::handle($nat, $natCreds, $natSession, $config, $now);

$distributed = [];
$distributedCreds = $credentials;
$distributedCreds['csrf'] = $session['_cb_login_csrf'];
foreach (['192.0.2.11', '192.0.2.12'] as $addr) {
    $remote = $post;
    $remote['REMOTE_ADDR'] = $addr;
    $distributed[] = OwnerLoginGate::handle($remote,
        ['password' => 'invalid-synthetic'] + $distributedCreds,
        $session, $config, $now)['status'];
}

$key = static function (string $ip) use ($hash, $dir): string {
    return $dir . '/cb-owner-' .
        hash('sha256', 'ip:' . hash_hmac('sha256', $ip, $hash)) . '.json';
};
$expected = [
    $key('198.51.100.99'), $key('198.51.100.11'),
    $key('192.0.2.44'), $key('192.0.2.11'), $key('192.0.2.12'),
];
$files = glob($dir . '/cb-owner-*.json');
sort($files);
$sortedExpected = $expected;
sort($sortedExpected);
$states = array_map(static fn(string $p): mixed =>
    json_decode(file_get_contents($p), true), $expected);

$recovery = [];
OwnerLoginGate::handle($get, [], $recovery, $config, $now + 901);
$recover = OwnerLoginGate::handle($attacker,
    ['csrf' => $recovery['_cb_login_csrf'], 'username' => 'fixture-owner',
     'password' => 'synthetic-password-only'],
    $recovery, $config, $now + 901);

$invalid = [];
OwnerLoginGate::handle($get, [], $invalid, $config, $now);
$badCsrf = OwnerLoginGate::handle($post,
    ['csrf' => 'not-a-valid-token'] + $credentials, $invalid, $config, $now);
$badIp = $post;
unset($badIp['REMOTE_ADDR']);
$invalidIp = OwnerLoginGate::handle($badIp,
    ['csrf' => $invalid['_cb_login_csrf']] + $credentials,
    $invalid, $config, $now);
$badConfig = $config;
unset($badConfig['password_hash']);
$missingHash = OwnerLoginGate::handle($get, [], $invalid, $badConfig, $now);
$badMethod = $get;
$badMethod['REQUEST_METHOD'] = 'DELETE';
$method = OwnerLoginGate::handle($badMethod, [], $invalid, $config, $now);

$symlinkIp = '203.0.113.55';
$symlinkMade = symlink($dir . '/nonexistent-target', $key($symlinkIp));
$symlinkPost = $post;
$symlinkPost['REMOTE_ADDR'] = $symlinkIp;
$symlinkStatus = OwnerLoginGate::handle($symlinkPost,
    ['csrf' => $invalid['_cb_login_csrf']] + $credentials,
    $invalid, $config, $now)['status'];

echo json_encode([
    'names_hmac' => $files === $sortedExpected &&
        count($files) === 5 && count(array_unique($files)) === 5,
    'state_only_ip' => count($states) === 5 && array_reduce($states,
        static fn(bool $ok, mixed $s): bool => $ok &&
            is_array($s) && array_keys($s) ===
                ['start', 'count', 'blocked_until'] &&
            is_int($s['start']) && is_int($s['count']) &&
            is_int($s['blocked_until']), true),
    'attacker_statuses' => $attackerStatuses,
    'attacker_limited' => $blocked['status'] === 429 &&
        (int) $blocked['headers']['Retry-After'] > 0,
    'other_ip_authenticated' => $owner['status'] === 303 &&
        $owner['headers']['Location'] === '/overview',
    'nat_limited' => $natBlocked['status'] === 429,
    'distributed_independent' => $distributed === [401, 401],
    'expired_window_recovery' => $recover['status'] === 303,
    'bad_csrf' => $badCsrf['status'],
    'missing_ip' => $invalidIp['status'],
    'missing_hash' => $missingHash['status'],
    'unsupported_method' => $method['status'],
    'symlink_rejected' => $symlinkMade && $symlinkStatus === 503,
], JSON_THROW_ON_ERROR), PHP_EOL;
"""


@lru_cache(maxsize=16)
def scenario(name: str) -> dict:
    if name != "owner" and name not in STAFF_CASES:
        raise AssertionError("UNKNOWN: invalid PHP scenario selector")
    executable = shutil.which("php")
    if executable is None:
        raise AssertionError("UNKNOWN: PHP CLI required for real behavior")
    with tempfile.TemporaryDirectory(prefix="controlbot-1248-") as private:
        env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "LANG": "C",
            "HOME": private,
            "TMPDIR": private,
            "CONTROLBOT_TEST_LIMIT_DIR": private,
        }
        for key in (
            "CONTROLBOT_REAL_PHP", "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP",
            "CONTROLBOT_PHP_COVERAGE_DIR", "CONTROLBOT_REPO_ROOT",
            "XDEBUG_MODE",
        ):
            if key in os.environ:
                env[key] = os.environ[key]
        script = ROOT / "tests" / "staff_control_plane_scenarios.php"
        if name == "owner":
            script = Path(private) / "owner-synthetic.php"
            script.write_text(OWNER_PHP, encoding="utf-8")
        elif not script.is_file():
            raise AssertionError("UNKNOWN: original staff PHP scenario missing")
        process = subprocess.run(
            [executable, "-d", "allow_url_fopen=0", "-d", "enable_dl=0",
             "-d", "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,shell_exec,system,exec,passthru,proc_open,popen",
             str(script), *([] if name == "owner" else [name])],
            cwd=ROOT, env=env, capture_output=True, text=True,
            check=False, timeout=60,
        )
    if process.returncode != 0 or process.stderr.strip():
        raise AssertionError(
            f"UNKNOWN: PHP scenario {name} failed ({process.returncode}): "
            + process.stderr[-900:]
        )
    try:
        result = json.loads(process.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP scenario did not return JSON") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: PHP scenario did not return object")
    return result


class CoberturaControlbot65Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: executable PHP evidence for eight behavior rows."""
        owner = scenario("owner")
        summary, search = scenario("summary"), scenario("search")
        invite, mutations = scenario("invite"), scenario("mutations")
        ambiguous, audit = scenario("ambiguous"), scenario("audit")
        records = search["valid"]["records"]
        checks = {
            "CB-1073": owner["names_hmac"] and owner["state_only_ip"],
            "CB-1074": (
                owner["attacker_limited"] and owner["other_ip_authenticated"]
                and owner["attacker_statuses"][:3] == [401, 401, 401]
            ),
            "CB-1075": (
                owner["nat_limited"] and owner["distributed_independent"]
            ),
            "CB-1084": (
                summary["valid"]["population"] == "staff_only"
                and summary["customer_boundary"] is True
                and search["valid"]["population"] == "staff_only"
                and scenario("invalid")["cross_project"] is True
            ),
            "CB-1085": (
                (summary["valid"]["active_staff"],
                 summary["valid"]["suspended_staff"],
                 summary["valid"]["failed_logins_summary"]) == (2, 1, 3)
                and summary["valid"]["freshness"] == "fresh"
                and search["valid"]["directory_persisted"] is False
                and [row["staff_id"] for row in records]
                    == ["staff-alpha", "staff-owner"]
                and records[0]["masked_email"] == "a***@example.com"
                and "email" not in records[0]
                and search["raw_email_leaked"] is False
            ),
            "CB-1086": (
                search["valid"]["directory_persisted"] is False
                and "mutations" not in summary["valid"]
                and "action" not in search["valid"]
                and invite["mfa_rejected"] is True
            ),
            "CB-1088": (
                scenario("authority")["rejected"] is True
                and invite["ok"]["status"] == "applied"
                and invite["mfa_rejected"] is True
                and invite["role_rejected"] is True
            ),
            "CB-1089": (
                mutations["payload_keys_distinct"] is True
                and all(
                    row["status"] == "applied"
                    and row["mutations"] == 1
                    and row["scope"] == "project:controlbot"
                    and row["idempotency_key"] == row["expected"]
                    for row in mutations["rows"]
                )
                and ambiguous["resolved"]["status"] == "applied"
                and ambiguous["mutations"] == 1
                and ambiguous["lookups"] == 2
                and ambiguous["unknown"]["status"] == "unknown_outcome"
                and ambiguous["unknown_mutations"] == 1
                and len(audit["entries"]) == 2
                and audit["result"]["audit"]["product_audit_ref"]
                    .startswith("product:audit/")
            ),
        }
        self.assertEqual(set(checks), {
            "CB-1073", "CB-1074", "CB-1075", "CB-1084", "CB-1085",
            "CB-1086", "CB-1088", "CB-1089",
        })
        for row, passed in sorted(checks.items()):
            with self.subTest(row=row):
                self.assertTrue(passed, f"{row}: shipping PHP behavior differs")

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid credentials, paths, ownership and scope fail closed."""
        owner = scenario("owner")
        self.assertEqual(owner["bad_csrf"], 403)
        self.assertEqual(owner["missing_ip"], 503)
        self.assertEqual(owner["missing_hash"], 503)
        self.assertEqual(owner["unsupported_method"], 405)
        self.assertIs(owner["symlink_rejected"], True)
        self.assertIs(owner["attacker_limited"], True)
        self.assertIs(owner["expired_window_recovery"], True)
        self.assertIs(scenario("authority")["rejected"], True)
        for name, rejected in scenario("invalid").items():
            with self.subTest(staff_boundary=name):
                self.assertIs(rejected, True)
        invite = scenario("invite")
        self.assertIs(invite["mfa_rejected"], True)
        self.assertIs(invite["role_rejected"], True)
        unknown = scenario("ambiguous")
        self.assertEqual(unknown["unknown"]["status"], "unknown_outcome")
        self.assertEqual(unknown["unknown_mutations"], 1)

    def test_sin_datos_reales(self) -> None:
        """AC-03: use fictional fixtures and verify redacted outputs."""
        payload = scenario("search")["valid"]
        self.assertIs(payload["directory_persisted"], False)
        encoded = json.dumps(payload, sort_keys=True)
        self.assertNotIn("ada@example.com", encoded)
        self.assertNotIn("owner@example.org", encoded)
        for record in payload["records"]:
            self.assertNotIn("email", record)
            self.assertIn("*", record["masked_email"])
        invite = scenario("invite")
        for forbidden in ("password", "secret", "activation_token", "cookie"):
            with self.subTest(term=forbidden):
                self.assertNotIn(forbidden, invite["serialized"].lower())
        self.assertIs(scenario("audit")["secret_free"], True)
        inventory = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(inventory["phase"], "construccion")
        self.assertEqual(
            inventory["d063_attestation"],
            {"nothing_live": True, "no_real_customer_data": True},
        )
        credentials = next(
            row for row in inventory["treatments"]
            if row["id"] == "owner_login_credentials"
        )
        self.assertEqual(credentials["fields"], ["password_hash"])
        staff = {
            row["id"]: row for row in inventory["treatments"]
            if row["id"] in ("staff_directory_identity", "staff_directory_contact")
        }
        self.assertEqual(set(staff),
                         {"staff_directory_identity", "staff_directory_contact"})
        for row in staff.values():
            self.assertEqual(row["retention"], "request_lifetime_only")


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Factory coverage #1184: real offline behavior, never a live attestation."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
OFFLINE = frozenset({"CB-1146", "CB-1150"})
EXTERNAL = frozenset({"CB-1145", "CB-1147", "CB-1148", "CB-1149", "CB-1151"})

# Invokes the actual PHP bridge implementation with a paired synthetic profile.
BRIDGE = r"""
require 'src/AutoFactoryBridgeEndpoint.php';
require 'src/OwnerLoginGate.php';
$now = 1800000000;
$identity = ['instanceId'=>'77777777-7777-4777-8777-777777777777',
    'profileAlias'=>'fixture-profile', 'expiresAt'=>$now+300, 'revoked'=>false];
$verify = function($key) use (&$identity) {
    return $key === 'fixture-key' ? $identity : null;
};
$store = static fn($kind,$instance,$data) => true;
$clock = static fn() => $now;
$disabled = \ControlBot\AutoFactory\AutoFactoryBridgeEndpoint::offline(
    $verify,$store,$clock,false);
$enabled = \ControlBot\AutoFactory\AutoFactoryBridgeEndpoint::offline(
    $verify,$store,$clock,true);
$body = ['version'=>1,'kind'=>'heartbeat','profileAlias'=>'fixture-profile',
    'accountAlias'=>'fixture-account',
    'tabs'=>[['tabId'=>1,'enabled'=>true,'state'=>'waiting']],
    'accountState'=>['state'=>'ready','resetAt'=>null],'lastEvent'=>null];
$call = static fn($gate,$key,$payload) =>
    $gate->handle('POST','/v1/bridge/heartbeat',[],$payload,$key)['status'];
$good = $call($enabled,'fixture-key',$body);
$off = $call($disabled,'fixture-key',$body);
$missing = $call($enabled,null,$body);
$unknown = $call($enabled,'bad-key',$body);
$foreign = $body; $foreign['profileAlias'] = 'foreign-profile';
$mismatch = $call($enabled,'fixture-key',$foreign);
$identity['revoked']=true;
$revoked = $call($enabled,'fixture-key',$body);
$identity['revoked']=false; $identity['expiresAt']=$now;
$expired = $call($enabled,'fixture-key',$body);
$s = ['_cb_owner_web'=>true,'_cb_owner_last_seen'=>$now];
$valid = \ControlBot\Security\OwnerLoginGate::isAuthenticated($s,$now+899);
$stale = \ControlBot\Security\OwnerLoginGate::isAuthenticated($s,$now+900);
$unconfigured = \ControlBot\Security\OwnerLoginGate::requireOwner(
    ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/overview'],$s,[],$now);
echo json_encode(['good'=>$good,'off'=>$off,'missing'=>$missing,
    'unknown'=>$unknown,'foreign'=>$mismatch,'revoked'=>$revoked,
    'expired'=>$expired,'session_valid'=>$valid,'session_stale'=>$stale,
    'unconfigured'=>$unconfigured['status']],JSON_THROW_ON_ERROR);
"""


def run_php(code: str) -> dict:
    process = subprocess.run(
        ["php", "-r", code], cwd=ROOT, text=True,
        capture_output=True, timeout=20, check=False,
    )
    if process.returncode != 0:
        raise AssertionError("Synthetic PHP scenario failed: " + process.stderr)
    return json.loads(process.stdout)


def owner(name: str) -> dict:
    if name not in {"totp", "token", "csrf", "elevate", "wrong-owner"}:
        raise ValueError("Unknown synthetic scenario")
    process = subprocess.run(
        ["php", str(ROOT / "tests/owner_session_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if process.returncode != 0:
        raise AssertionError("Owner scenario failed: " + process.stderr)
    return json.loads(process.stdout)


def deploy(*, backup: bool = True, real: bool = False) -> tuple[int, dict]:
    data = {
        "kind": "synthetic", "phase": "construccion", "transport": "none",
        "candidate_sha": "a" * 40, "expected_candidate_sha": "a" * 40,
        "artifact_sha256": "c" * 64, "baseline_sha": "b" * 40,
        "expected_baseline_sha": "b" * 40, "baseline_verified": True,
        "artifact_verified": True, "backup_verified": backup,
        "rollback_verified": True,
    }
    process = subprocess.run(
        [sys.executable, str(ROOT / "ops/factory/deploy"),
         "--real" if real else "--offline-dry-run"],
        input=json.dumps(data), cwd=ROOT, text=True,
        capture_output=True, timeout=15, check=False,
    )
    if not process.stdout:
        raise AssertionError("Deploy preflight produced no output: " + process.stderr)
    return process.returncode, json.loads(process.stdout)


class CoberturaControlbot01Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(OFFLINE | EXTERNAL, {
            f"CB-{n}" for n in range(1145, 1152)})
        self.assertFalse(OFFLINE & EXTERNAL)
        # CB-1146: calls actual OwnerSession/TOTP and bridge classes.
        totp = owner("totp")
        self.assertEqual(totp["login"], "pl0n3r")
        self.assertIsInstance(totp["reauthenticated_at"], int)
        sealed = owner("token")
        self.assertIs(sealed["roundtrip_ok"], True)
        self.assertIs(sealed["sealed_is_plaintext"], False)
        self.assertIs(sealed["sealed_contains_plaintext"], False)
        bridge = run_php(BRIDGE)
        self.assertEqual(bridge["good"], 202)
        self.assertIs(bridge["session_valid"], True)
        # CB-1150: real preflight CLI can only attest SYNTHETIC_ONLY.
        code, report = deploy()
        self.assertEqual(code, 0)
        self.assertEqual(report["status"], "SYNTHETIC_ONLY")
        self.assertEqual(report["readiness"], "UNKNOWN")
        self.assertIs(report["deploy_executed"], False)
        self.assertIs(report["production_ready"], False)

    def test_falla_cerrado(self) -> None:
        self.assertIn("CSRF inválido", owner("csrf")["error"])
        self.assertIn("Sesión del dueño inválida", owner("elevate")["error"])
        wrong = owner("wrong-owner")
        self.assertTrue(wrong["session_empty"])
        self.assertIn("no corresponde al dueño", wrong["error"])
        self.assertFalse(owner("totp")["invalid_has_reauth"])
        bridge = run_php(BRIDGE)
        self.assertIs(bridge["session_stale"], False)
        for key, status in {
            "off": 503, "missing": 401, "unknown": 401,
            "foreign": 400, "revoked": 401, "expired": 401,
            "unconfigured": 503,
        }.items():
            with self.subTest(case=key):
                self.assertEqual(bridge[key], status)
        for options, reason in (({"backup": False}, "backup_unverified"),
                                ({"real": True}, "mode_not_authorized")):
            with self.subTest(options=options):
                code, report = deploy(**options)
                self.assertEqual(code, 2)
                self.assertEqual(report["reason"], reason)
                self.assertEqual(report["status"], "REJECTED")
                self.assertIs(report["deploy_executed"], False)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(OFFLINE | EXTERNAL, {
            f"CB-{n}" for n in range(1145, 1152)})
        self.assertIn("fixture-key", BRIDGE)
        self.assertIn("fixture-profile", BRIDGE)
        for forbidden in ("curl_exec(", "file_get_contents('http",
                          "socket_create(", "new PDO(", "ghp_"):
            self.assertNotIn(forbidden, BRIDGE)

    def test_validar_exigido(self) -> None:
        # AC-04 cannot refer to Validar as an acceptance check: circularity.
        # GitHub CI and branch policy still require the real exact-HEAD gate.
        workflow = (ROOT / ".github/workflows/ci.yml").read_text("utf-8")
        self.assertIn("  validar:", workflow)
        self.assertIn("    name: Validar", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertIn("phase: construccion", workflow)
        self.assertIn("head.sha || github.sha", workflow)


if __name__ == "__main__":
    unittest.main()

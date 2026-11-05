#!/usr/bin/env python3
"""Issue #1452: owner-only global pause, CSRF, audited CAS and synthetic 30s SLA."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

PHP = r"""
foreach (['src/Approvals.php', 'src/OwnerSession.php', 'src/PauseControl.php', 'src/PauseTransitionAudit.php', 'src/SchedulerCore.php', 'src/Routing/OwnerAuthenticatedRouter.php', 'src/ControlBotWebEntrypoint.php'] as $file) require $file;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;
use ControlBot\Routing\OwnerAuthenticatedRouter;
use ControlBot\Web\ControlBotWebEntrypoint;
use ControlBot\Runtime\PauseControl;
$now = 14000;
$auth = new OwnerSessionService('pl0n3r', new TokenVault( base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));
$session = [];
$auth->establishTrustedOAuthSession($session, 'pl0n3r', 'synthetic-oauth-fixture');
$auth->reauthenticateTotp($session, Totp::code('JBSWY3DPEHPK3PXP', $now), 'JBSWY3DPEHPK3PXP', $now);
$csrf = $auth->csrfToken($session);
$pause = null; $audit = []; $revision = 0;
$work = ['work-running']; $commits = 0;
$backend = static function (string $operation, array $vars, array $request, int $at)
    use (&$pause, &$audit, &$revision, &$work, &$commits): array {
    $snapshot = ['revision' => $revision, 'pause' => $pause, 'audit' => $audit, 'active_work' => $work, 'actor' => 'pl0n3r'];
    if ($operation === 'pause.global.snapshot') return $snapshot;
    if ($operation !== 'pause.global.commit') throw new RuntimeException('Unknown adapter call');
    $expected = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    if (($request['expected_revision'] ?? null) !== $revision
        || ($request['expected_sha256'] ?? null) !== $expected
        || ($request['unchanged_active_work'] ?? null) !== $work) {
        throw new RuntimeException('Synthetic CAS stale');
    }
    // Test adapter: atomic commit of state AND append-only audit; work unchanged.
    $pause = $request['pause']; $audit = $request['audit']; $revision++; $commits++;
    return ['committed' => true, 'revision' => $revision, 'pause' => $pause, 'audit' => $audit, 'active_work' => $work, 'new_assignments' => 0, 'scheduler_observed_at' => $at + 29];
};
$server = static function (string $method, string $path, array $extra = []): array {
    return array_replace([ 'REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_USER' => 'forged', 'HTTP_X_REQUEST_ID' => str_repeat('a', 32), 'HTTP_X_CORRELATION_ID' => str_repeat('b', 32), ], $extra);
};
$api = static function (string $method, string $path, array $headers = [], ?string $raw = null, ?array $userSession = null, ?callable $adapter = null, int $at = 14000)
    use ($auth, $session, $server, $backend): array {
    return OwnerAuthenticatedRouter::dispatch( $auth, $userSession ?? $session, $server($method, $path, $headers), $at, $adapter ?? $backend, null, $raw);
};
$post = static function (array $payload, ?string $token = null, array $extra = [])
    use ($api, $csrf): array {
    return $api('POST', '/api/v1/pausa', array_replace([ 'HTTP_X_CSRF_TOKEN' => $token ?? $csrf, 'CONTENT_TYPE' => 'application/json', ], $extra), json_encode($payload, JSON_THROW_ON_ERROR));
};
$pausePayload = ['action' => 'pause', 'confirmation' => 'PAUSAR TODOS', 'reason' => 'Pausa sintetica operativa'];
$resumePayload = ['action' => 'resume', 'confirmation' => 'REANUDAR TODOS'];
$parse = static fn(array $response): array => json_decode( $response['body'], true, 16, JSON_THROW_ON_ERROR);
if (($argv[1] ?? '') === 'principal') {
    $before = $api('GET', '/api/v1/pausa');
    $page = ControlBotWebEntrypoint::handleAuthenticated( $server('GET', '/pausa'), ['CONTROLBOT_OWNER_LOGIN' => 'pl0n3r'], $now, $session, $auth, null, null, null, $backend);
    $paused = $post($pausePayload);
    $at29 = PauseControl::effective([ 'version' => 1, 'session_id' => 'synthetic-session', 'account_id' => 'synthetic-account', 'project_id' => 'synthetic-project', ], [$pause], true);
    $whilePaused = $api('GET', '/api/v1/pausa');
    $resumed = $api('POST', '/api/v1/pausa', [ 'HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json', ], json_encode($resumePayload, JSON_THROW_ON_ERROR), $session, $backend, $now + 1);
    $after = $api('GET', '/api/v1/pausa');
    echo json_encode([ 'statuses' => [$before['status'], $page['status'], $paused['status'], $whilePaused['status'], $resumed['status'], $after['status']], 'states' => [$parse($before)['state'], $parse($paused)['state'], $parse($whilePaused)['state'], $parse($resumed)['state'], $parse($after)['state']], 'revisions' => [$parse($before)['revision'], $parse($paused)['revision'], $parse($resumed)['revision']], 'audit_count' => count($audit), 'audit_states' => array_column($audit, 'after_state'), 'audit_source' => array_column($audit, 'source'), 'audit_actor' => array_column($audit, 'actor'), 'blocked_at_29' => $at29['blocked'], 'running_work' => $work, 'commits' => $commits, 'page_html' => str_contains($page['body'], '<h1>Pausar todos</h1>')
            && str_contains($page['body'], '"/api/v1/pausa"')
            && str_contains($page['body'], '"X-CSRF-Token"'), 'page_no_store' => ($page['headers']['Cache-Control'] ?? '') === 'no-store', 'page_csp' => str_contains($page['headers']['Content-Security-Policy'] ?? '', 'script-src'), 'api_no_store' => ($paused['headers']['Cache-Control'] ?? '') === 'no-store', ], JSON_THROW_ON_ERROR); exit;
}
if (($argv[1] ?? '') === 'security') {
    $anonymous = OwnerAuthenticatedRouter::dispatch( $auth, [], $server('GET', '/api/v1/pausa'), $now, $backend);
    $anonymousPage = ControlBotWebEntrypoint::handleAuthenticated( $server('GET', '/pausa'), ['CONTROLBOT_OWNER_LOGIN' => 'pl0n3r'], $now, [], $auth);
    $anonymousPost = OwnerAuthenticatedRouter::dispatch( $auth, [], $server('POST', '/api/v1/pausa', [ 'HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json']), $now, $backend, null, json_encode($pausePayload));
    $stale = $api('GET', '/api/v1/pausa', [], null, $session, $backend, $now + 301);
    $missingCsrf = $api('POST', '/api/v1/pausa', ['CONTENT_TYPE' => 'application/json'], json_encode($pausePayload));
    $badCsrf = $post($pausePayload, 'wrong');
    $noConfirm = $post(array_replace($pausePayload, ['confirmation' => 'yes']));
    $extraField = $post($pausePayload + ['owner' => 'attacker']);
    $unsafeReason = $post(array_replace($pausePayload, ['reason' => 'password=not-allowed']));
    $unknown = $post(['action' => 'unsupported', 'confirmation' => 'PAUSAR TODOS']);
    $earlyResume = $post($resumePayload);
    $badContent = $post($pausePayload, null, ['CONTENT_TYPE' => 'text/plain']);
    $badJson = $api('POST', '/api/v1/pausa', ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], '{"action":');
    $wrongMethod = $api('PUT', '/api/v1/pausa');
    $noBackend = OwnerAuthenticatedRouter::dispatch( $auth, $session, $server('GET', '/api/v1/pausa'), $now);
    $brokenBackend = $api('GET', '/api/v1/pausa', [], null, $session, static fn(): array => ['revision' => 0]);
    $localOwner = ControlBotWebEntrypoint::handleLocalOwner( $server('GET', '/pausa'), ['CONTROLBOT_OWNER_LOGIN' => 'pl0n3r'], $now);
    $wrongConfig = ControlBotWebEntrypoint::handleAuthenticated( $server('GET', '/pausa'), ['CONTROLBOT_OWNER_LOGIN' => 'attacker'], $now, $session, $auth);
    $statuses = [ 'anonymous' => $anonymous['status'], 'anonymous_page' => $anonymousPage['status'], 'anonymous_post' => $anonymousPost['status'], 'stale' => $stale['status'], 'csrf_missing' => $missingCsrf['status'], 'csrf_wrong' => $badCsrf['status'], 'confirmation' => $noConfirm['status'], 'extra' => $extraField['status'], 'secret' => $unsafeReason['status'], 'action' => $unknown['status'], 'resume_without_pause' => $earlyResume['status'], 'content_type' => $badContent['status'], 'bad_json' => $badJson['status'], 'method' => $wrongMethod['status'], 'no_backend' => $noBackend['status'], 'malformed_adapter' => $brokenBackend['status'], 'wrong_owner' => $wrongConfig['status'], ];
    echo json_encode([ 'statuses' => $statuses, 'commits' => $commits, 'work' => $work, 'audit_count' => count($audit), 'pause' => $pause, 'local_never_shows_page' => !str_contains( $localOwner['body'], '<h1>Pausar todos</h1>'), 'denials_no_store' => ($anonymous['headers']['Cache-Control'] ?? '') === 'no-store', ], JSON_THROW_ON_ERROR); exit;
}
throw new RuntimeException('Unknown fixture case');"""
def scenario(case: str) -> dict:
    executable = shutil.which("php")
    if executable is None:
        raise AssertionError("PHP runtime missing")
    result = subprocess.run(
        [executable, "-r", PHP, case], cwd=ROOT,
        capture_output=True, text=True, timeout=45, check=False,
    )
    if result.returncode != 0:
        raise AssertionError("Synthetic pause scenario failed: " + result.stderr[-1800:])
    return json.loads(result.stdout)
class PauseTests(unittest.TestCase):
    def test_criterio_principal(self):
        data = scenario("principal")
        self.assertEqual([200] * 6, data["statuses"])
        self.assertEqual(["active", "paused", "paused", "active", "active"],
                         data["states"])
        self.assertEqual([0, 1, 2], data["revisions"])
        self.assertEqual(2, data["audit_count"])
        self.assertEqual(["active", "released"], data["audit_states"])
        self.assertEqual(["owner", "owner"], data["audit_source"])
        self.assertEqual(["pl0n3r", "pl0n3r"], data["audit_actor"])
        self.assertTrue(data["blocked_at_29"])
        self.assertEqual(["work-running"], data["running_work"])
        self.assertEqual(2, data["commits"])
        self.assertTrue(data["page_html"])
        self.assertTrue(data["page_no_store"])
        self.assertTrue(data["page_csp"])
        self.assertTrue(data["api_no_store"])
    def test_criterio_seguridad_y_bordes(self):
        data = scenario("security")
        expected = {
            "anonymous": 403, "anonymous_page": 403, "anonymous_post": 403,
            "stale": 403, "csrf_missing": 403, "csrf_wrong": 403,
            "confirmation": 400, "extra": 400, "secret": 400,
            "action": 400, "resume_without_pause": 400, "content_type": 400,
            "bad_json": 400, "method": 405,
            "no_backend": 503, "malformed_adapter": 503, "wrong_owner": 403,
        }
        self.assertEqual(expected, data["statuses"])
        self.assertEqual(0, data["commits"])
        self.assertEqual(0, data["audit_count"])
        self.assertEqual(["work-running"], data["work"])
        self.assertIsNone(data["pause"])
        self.assertTrue(data["local_never_shows_page"])
        self.assertTrue(data["denials_no_store"])
if __name__ == "__main__":
    unittest.main()

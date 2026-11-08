#!/usr/bin/env python3
"""#1448: owner-only web intake contract and preview-only materialization."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

PHP = r"""
declare(strict_types=1);
$root = getcwd() . '/src/';
foreach ([
    'Approvals', 'OwnerSession', 'GitHub', 'ApprovalEndpoint',
    'VentureIdentity', 'VentureAccessSource', 'DecisionRights',
    'DecisionRuntime', 'VerifiedAccessContext', 'ExternalApiContract',
    'ExternalApiAccess', 'ExternalApiSession', 'ExternalApiSessionSource',
    'VerifiedExternalSessionContext', 'ExternalApiRequestGate',
    'ExternalApiReadProjection', 'ExternalApiOwnerDecisionRead',
    'ExternalApiMobileAudit', 'ExternalApiDirectedWorkOrigin',
] as $class) {
    require_once $root . $class . '.php';
}
require_once $root . 'Routing/OwnerAuthenticatedRouter.php';

$now = 14000;
$auth = new \ControlBot\Security\OwnerSessionService(
    'pl0n3r',
    new \ControlBot\Security\TokenVault(
        base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))
    )
);
$session = [];
$auth->establishTrustedOAuthSession($session, 'pl0n3r', 'offline-fixture-token');
$auth->reauthenticateTotp(
    $session,
    \ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP', $now),
    'JBSWY3DPEHPK3PXP',
    $now
);
$records = [];
$operations = [];
$backend = static function (
    string $operation, array $vars, array $payload, int $time
) use (&$records, &$operations): array {
    $operations[] = $operation;
    switch ($operation) {
        case 'intake.candidates':
            return ['projects' => [[
                'project_ref' => 'controlbot:project/controlbot',
                'title' => 'ControlBot',
                'aliases' => ['control center'],
            ]]];
        case 'intake.record':
            $id = $payload['intake_id'];
            $records[$id] = [
                'draft' => $payload['draft'],
                'candidates' => $payload['candidates'],
            ];
            return ['stored' => true, 'intake_id' => $id];
        case 'intake.list':
            return ['entries' => array_map(
                static fn (string $id): array => ['intake_id' => $id],
                array_keys($records)
            )];
        case 'intake.read':
            if (!isset($records[$vars['intake_id']])) {
                throw new RuntimeException('No record from trusted store.');
            }
            return $records[$vars['intake_id']];
        default:
            throw new RuntimeException('Unexpected external operation.');
    }
};
$dispatch = static function (
    string $method,
    string $path,
    ?string $raw = null,
    array $headers = [],
    ?array $active = null,
    ?callable $source = null
) use ($auth, $session, $backend, $now): array {
    $server = array_merge([
        'REQUEST_URI' => $path,
        'REQUEST_METHOD' => $method,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_CSRF_TOKEN' => $auth->csrfToken($session),
    ], $headers);
    return \ControlBot\Routing\OwnerAuthenticatedRouter::dispatch(
        $auth, $active ?? $session, $server, $now, $source ?? $backend,
        null, $raw
    );
};
$decode = static fn (array $r): array => json_decode(
    $r['body'], true, 16, JSON_THROW_ON_ERROR
);
$idea = "Problem: Orders are tracked manually\nUser: operators\n"
    . "Objective: centralize intake; preview project decisions\n"
    . "ControlBot requirements initiative";
$payload = json_encode(['content' => $idea], JSON_THROW_ON_ERROR);
$initial = $dispatch('GET', '/api/v1/intake');
$created = $dispatch('POST', '/api/v1/intake', $payload);
$id = $decode($created)['intake_id'] ?? 'invalid';
$index = $dispatch('GET', '/api/v1/intake');
$decision = $dispatch('GET', '/api/v1/intake/' . $id . '/decision');
$existing = $dispatch(
    'POST', '/api/v1/intake/' . $id . '/decision',
    json_encode(['project_mode' => 'existing',
        'project_ref' => 'controlbot:project/controlbot'], JSON_THROW_ON_ERROR)
);
$new = $dispatch(
    'POST', '/api/v1/intake/' . $id . '/decision',
    json_encode(['project_mode' => 'new',
        'project_ref' => null], JSON_THROW_ON_ERROR)
);

if (($argv[1] ?? null) === 'principal') {
    $out = [
        'statuses' => array_map(
            static fn ($r): int => $r['status'],
            [$initial, $created, $index, $decision, $existing, $new]
        ),
        'created' => $decode($created),
        'initial' => $decode($initial),
        'index' => $decode($index),
        'decision' => $decode($decision),
        'existing' => $decode($existing),
        'new' => $decode($new),
        'stored' => count($records),
        'operations' => $operations,
        'no_store' => array_reduce(
            [$initial, $created, $index, $decision, $existing, $new],
            static fn ($value, $r): bool => $value &&
                ($r['headers']['Cache-Control'] ?? null) === 'no-store',
            true
        ),
    ];
} elseif (($argv[1] ?? null) === 'security') {
    $before = count($records);
    $bad = [
        'anonymous' => $dispatch(
            'POST', '/api/v1/intake', $payload, [], []
        ),
        'csrf' => $dispatch(
            'POST', '/api/v1/intake', $payload,
            ['HTTP_X_CSRF_TOKEN' => 'invalid']
        ),
        'invalid_json' => $dispatch(
            'POST', '/api/v1/intake', '{"'
        ),
        'unknown_field' => $dispatch(
            'POST', '/api/v1/intake',
            '{"content":"Idea","create_repo":true}'
        ),
        'empty' => $dispatch(
            'POST', '/api/v1/intake', '{"content":""}'
        ),
        'forged_source' => $dispatch(
            'POST', '/api/v1/intake',
            '{"content":"Idea","source_ref":"attacker"}'
        ),
        'bad_id' => $dispatch(
            'GET', '/api/v1/intake/invalid-id/decision'
        ),
        'missing_id' => $dispatch(
            'GET', '/api/v1/intake/idea-' . str_repeat('f', 40) . '/decision'
        ),
        'wrong_method' => $dispatch('DELETE', '/api/v1/intake'),
        'wrong_existing' => $dispatch(
            'POST', '/api/v1/intake/' . $id . '/decision',
            '{"project_mode":"existing","project_ref":"controlbot:project/other"}'
        ),
        'wrong_new' => $dispatch(
            'POST', '/api/v1/intake/' . $id . '/decision',
            '{"project_mode":"new","project_ref":"controlbot:project/controlbot"}'
        ),
        'bad_choice' => $dispatch(
            'POST', '/api/v1/intake/' . $id . '/decision',
            '{"project_mode":"execute","project_ref":null}'
        ),
        'no_backend' => \ControlBot\Routing\OwnerAuthenticatedRouter::dispatch(
            $auth, $session,
            ['REQUEST_URI' => '/api/v1/intake', 'REQUEST_METHOD' => 'GET'],
            $now
        ),
    ];
    $secret = $dispatch(
        'POST', '/api/v1/intake',
        json_encode(['content' => "Problem: migrate\n"
            . "User: operators\npassword=hunter2"], JSON_THROW_ON_ERROR)
    );
    $lastRecord = end($records);
    $out = [
        'statuses' => array_map(static fn ($r): int => $r['status'], $bad),
        'no_extra_records' => count($records) === $before + 1,
        'secret_redacted' => $secret['status'] === 200
            && !str_contains($secret['body'], 'hunter2')
            && !str_contains(json_encode($lastRecord, JSON_THROW_ON_ERROR), 'hunter2')
            && str_contains($lastRecord['draft']['intent'], '[REDACTED]'),
        'no_external_operations' => count(array_diff($operations, [
            'intake.candidates', 'intake.record', 'intake.list', 'intake.read',
        ])) === 0,
        'denials_no_store' => array_reduce(
            array_values($bad),
            static fn ($carry, $r): bool => $carry &&
                ($r['headers']['Cache-Control'] ?? null) === 'no-store',
            true
        ),
    ];
} elseif (($argv[1] ?? null) === 'source_errors') {
    $invalidIndex = static fn (): array => ['entries' => [], 'extra' => true];
    $invalidCandidates = static fn (): array => ['projects' => [], 'extra' => true];
    $missingReceipt = static function (
        string $operation, array $vars, array $payload, int $time
    ) use ($backend): array {
        if ($operation === 'intake.record') {
            return ['intake_id' => $payload['intake_id']];
        }
        return $backend($operation, $vars, $payload, $time);
    };
    $out = [
        'index' => $dispatch('GET', '/api/v1/intake', null, [], null, $invalidIndex)['status'],
        'candidates' => $dispatch('POST', '/api/v1/intake', $payload, [], null, $invalidCandidates)['status'],
        'receipt' => $dispatch('POST', '/api/v1/intake', $payload, [], null, $missingReceipt)['status'],
    ];
} else {
    throw new RuntimeException('Invalid fixture choice.');
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
"""


def run_fixture(name: str) -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP runtime unavailable")
    result = subprocess.run(
        [php, "-r", PHP, name], cwd=ROOT, capture_output=True,
        text=True, timeout=45, check=False,
    )
    if result.returncode:
        raise AssertionError("PHP intake fixture failed: " + result.stderr[-1200:])
    return json.loads(result.stdout)



def run_html_fixture(method: str, csrf: str) -> dict:
    """Exercise the owner-only HTML renderer without exposing an HTTP route."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP runtime unavailable")
    script = r"""
require 'src/Routing/Routes/IntakeRoutes.php';
$result = controlbot_intake_html_page($argv[2], ['REQUEST_METHOD' => $argv[1]]);
echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
"""
    result = subprocess.run(
        [php, "-r", script, method, csrf], cwd=ROOT, capture_output=True,
        text=True, timeout=45, check=False,
    )
    if result.returncode:
        raise AssertionError("PHP intake HTML fixture failed: " + result.stderr[-1200:])
    return json.loads(result.stdout)



PHP_WEB = r"""
declare(strict_types=1);
foreach (['Approvals.php', 'OwnerSession.php', 'ControlBotWebEntrypoint.php'] as $module) {
    require_once getcwd() . '/src/' . $module;
}
$now = 14000;
$env = ['CONTROLBOT_OWNER_LOGIN' => 'pl0n3r'];
$auth = new \ControlBot\Security\OwnerSessionService('pl0n3r',
    new \ControlBot\Security\TokenVault(
        base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))
    ));
$owner = [];
$auth->establishTrustedOAuthSession($owner, 'pl0n3r', 'synthetic-token');
$auth->reauthenticateTotp($owner,
    \ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP', $now),
    'JBSWY3DPEHPK3PXP', $now);
$oauthOnly = [];
$auth->establishTrustedOAuthSession($oauthOnly, 'pl0n3r', 'synthetic-token');
$server = static fn (string $method): array => [
    'REQUEST_URI' => '/intake', 'REQUEST_METHOD' => $method,
    'REMOTE_USER' => 'pl0n3r', 'HTTP_AUTHORIZATION' => 'Bearer forged',
];
$call = static fn (string $method, array $session, int $time = 14000): array =>
    \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated(
        $server($method), $env, $time, $session, $auth,
    );
$ownerGet = $call('GET', $owner);
$rows = [
    'owner_get' => $ownerGet,
    'owner_post' => $call('POST', $owner),
    'anonymous' => $call('GET', []),
    'oauth_only' => $call('GET', $oauthOnly),
    'expired' => $call('GET', $owner, $now + 301),
    'public' => \ControlBot\Web\ControlBotWebEntrypoint::handle(
        $server('GET'), $env, $now),
    'local_password' => \ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner(
        $server('GET'), $env, $now),
];
echo json_encode([
    'statuses' => array_map(static fn (array $row): int => $row['status'], $rows),
    'html_owner' => str_contains($ownerGet['body'], 'id="idea-form"')
        && str_contains($ownerGet['body'], 'id="decide"'),
    'csrf_nonce' => str_contains($ownerGet['headers']['Content-Security-Policy'] ?? '',
        "script-src 'nonce-"),
    'safe_headers' => array_reduce($rows,
        static fn (bool $ok, array $row): bool => $ok
            && ($row['headers']['Cache-Control'] ?? null) === 'no-store'
            && !str_contains($row['body'], 'synthetic-token'), true),
    'plain_rejection' => !str_contains($rows['anonymous']['body'], '<html')
        && !str_contains($rows['local_password']['body'], '<html'),
], JSON_THROW_ON_ERROR), "\n";
"""


def run_web_entrypoint_fixture() -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP runtime unavailable")
    result = subprocess.run(
        [php, "-r", PHP_WEB], cwd=ROOT,
        capture_output=True, text=True, timeout=45, check=False,
    )
    if result.returncode:
        raise AssertionError("PHP owner-route fixture failed: " + result.stderr[-1200:])
    return json.loads(result.stdout)


class IntakeTests(unittest.TestCase):
    def test_criterio_principal(self):
        routed = run_web_entrypoint_fixture()
        self.assertEqual(200, routed["statuses"]["owner_get"])
        self.assertTrue(routed["html_owner"] and routed["csrf_nonce"])
        html = run_html_fixture("GET", "synthetic-csrf-token")
        self.assertEqual(200, html["status"])
        self.assertEqual("text/html; charset=utf-8", html["headers"]["Content-Type"])
        self.assertEqual("no-store", html["headers"]["Cache-Control"])
        self.assertEqual("DENY", html["headers"]["X-Frame-Options"])
        self.assertIn("script-src 'nonce-", html["headers"]["Content-Security-Policy"])
        self.assertIn("connect-src 'self'", html["headers"]["Content-Security-Policy"])
        self.assertIn('id="idea-form"', html["body"])
        self.assertIn('id="decide"', html["body"])
        self.assertIn('"/api/v1/intake"', html["body"])
        self.assertIn('project_mode:projectMode', html["body"])
        self.assertIn("data.committed !== false", html["body"])
        self.assertIn("data.repositories_created !== 0", html["body"])
        self.assertNotIn("window.location =", html["body"])
        self.assertNotIn("innerHTML", html["body"])
        r = run_fixture("principal")
        self.assertEqual([200] * 6, r["statuses"])
        self.assertTrue(r["no_store"])
        self.assertEqual(1, r["stored"])
        self.assertEqual([], r["initial"]["entries"])
        self.assertEqual(["content"], r["index"]["form"]["fields"])
        self.assertEqual(1, len(r["index"]["entries"]))
        self.assertEqual("pending_owner_decision", r["created"]["status"])
        self.assertEqual(r["created"]["intake_id"], r["index"]["entries"][0]["intake_id"])
        self.assertEqual(r["created"]["proposal_ref"], r["decision"]["proposal_ref"])
        self.assertFalse(r["decision"]["execution"])
        self.assertEqual("matched", r["decision"]["decision"]["impacts"]["project_match"]["status"])
        self.assertEqual("link_proposal", r["existing"]["project"]["operation"])
        self.assertEqual("propose_create", r["new"]["project"]["operation"])
        for mode in ("existing", "new"):
            self.assertEqual("preview_only", r[mode]["status"])
            self.assertFalse(r[mode]["committed"])
            self.assertFalse(r[mode]["execution"])
            self.assertEqual(0, r[mode]["repositories_created"])
            self.assertEqual(0, r[mode]["issues_created"])
        self.assertFalse(any("github" in operation or "materialize" in operation
                             for operation in r["operations"]))

    def test_criterio_seguridad_y_bordes(self):
        routed = run_web_entrypoint_fixture()
        self.assertEqual({
            "owner_get": 200, "owner_post": 405, "anonymous": 403,
            "oauth_only": 403, "expired": 403, "public": 403,
            "local_password": 403,
        }, routed["statuses"])
        self.assertTrue(routed["safe_headers"] and routed["plain_rejection"])
        invalid_method = run_html_fixture("POST", "synthetic-csrf-token")
        self.assertEqual(405, invalid_method["status"])
        self.assertNotIn("<html", invalid_method["body"])
        missing_csrf = run_html_fixture("GET", "")
        self.assertEqual(503, missing_csrf["status"])
        self.assertNotIn("<html", missing_csrf["body"])
        injection = '<script>alert("owner")</script>'
        escaped = run_html_fixture("GET", injection)
        self.assertEqual(200, escaped["status"])
        self.assertNotIn(injection, escaped["body"])
        self.assertIn("\\u003Cscript\\u003E", escaped["body"])
        self.assertIn("frame-ancestors 'none'", escaped["headers"]["Content-Security-Policy"])
        self.assertIn("form-action 'none'", escaped["headers"]["Content-Security-Policy"])
        r = run_fixture("security")
        self.assertEqual({
            "anonymous": 403, "csrf": 403, "invalid_json": 400,
            "unknown_field": 400, "empty": 400, "forged_source": 400,
            "bad_id": 400, "missing_id": 503, "wrong_method": 405,
            "wrong_existing": 400, "wrong_new": 400, "bad_choice": 400,
            "no_backend": 503,
        }, r["statuses"])
        self.assertTrue(r["no_extra_records"])
        self.assertTrue(r["secret_redacted"])
        self.assertTrue(r["no_external_operations"])
        self.assertTrue(r["denials_no_store"])
        self.assertEqual({"index": 503, "candidates": 503, "receipt": 503},
                         run_fixture("source_errors"))
        html_script = run_html_fixture("GET", "synthetic-csrf-token")["body"]
        for guard in ("new TextEncoder().encode(content).length > 8000",
                      "++selectionEpoch", "++previewEpoch",
                      "epoch !== previewEpoch", "selectedId !== id",
                      "data.intake_id !== id"):
            self.assertIn(guard, html_script)


if __name__ == "__main__":
    unittest.main()

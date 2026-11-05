#!/usr/bin/env python3
"""#1450: owner-only, source-bound scheduler preview without assignments or I/O."""
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
require_once $root . 'Approvals.php';
require_once $root . 'OwnerSession.php';
require_once $root . 'Routing/OwnerAuthenticatedRouter.php';
require_once $root . 'ControlBotWebEntrypoint.php';

$now = 14000;
$auth = new \ControlBot\Security\OwnerSessionService(
    'pl0n3r',
    new \ControlBot\Security\TokenVault(base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)))
);
$session = [];
$auth->establishTrustedOAuthSession($session, 'pl0n3r', 'fixture-server-token');
$auth->reauthenticateTotp(
    $session,
    \ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP', $now),
    'JBSWY3DPEHPK3PXP',
    $now
);
$make = static fn(string $verb, string $uri): array => [
    'REQUEST_METHOD' => $verb, 'REQUEST_URI' => $uri,
    'REMOTE_USER' => 'forged', 'HTTP_AUTHORIZATION' => 'Bearer-forged',
];
$work = static fn(string $id, string $priority, array $dependencies, string $state = 'queued',
    ?string $reservation = null): array => [
    'version' => 1, 'work_item_id' => $id, 'project_id' => 'project-controlbot',
    'source_ref' => 'pl0n3r/ControlBot#1450', 'type' => 'feature',
    'priority' => $priority, 'state' => $state, 'dependency_ids' => $dependencies,
    'required_capabilities' => ['php'], 'generation' => 1, 'attempt' => 1,
    'reservation_id' => $reservation, 'assigned_session_id' => null,
];
$context = static fn(array $dependencies = [], array $reservations = []): array => [
    'dependency_states' => $dependencies, 'approval_state' => 'approved',
    'freeze_state' => 'clear', 'reservations' => $reservations,
    'capacity' => [
        'account_id' => 'account_main', 'eligible' => true,
        'free_capacity' => 1, 'session_ids' => [],
    ],
    'expected_generation' => 1, 'expected_owner_session_id' => null,
];
$reservation = [
    'reservation_id' => 'reservation-hold', 'owner_session_id' => 'session-owner',
    'generation' => 1, 'active' => true,
];
$rows = [
    ['work_item' => $work('work-critical', 'critical', []), 'context' => $context()],
    ['work_item' => $work('work-medium', 'medium', ['dep-stuck']),
     'context' => $context(['dep-stuck' => 'open'])],
    ['work_item' => $work('work-reserved', 'high', [], 'reserved', 'reservation-hold'),
     'context' => $context([], [$reservation])],
];
$baseline = serialize($rows);
$calls = [];
$backend = static function (string $operation, array $vars, array $request, int $at)
    use (&$calls, $rows): array {
    $calls[] = [$operation, $vars, $request, $at];
    if ($operation !== 'scheduler.plan.read') throw new RuntimeException('No mutations permitted.');
    return ['version' => 1, 'work_items' => $rows];
};
$router = \ControlBot\Routing\OwnerAuthenticatedRouter::class;
$request = $make('GET', '/api/v1/scheduler');
$scenario = $argv[1] ?? '';
if ($scenario === 'principal') {
    $response = $router::dispatch($auth, $session, $request, $now, $backend);
    $direct = $router::dispatch($auth, $session, $request, $now, $backend);
    $entrypoint = \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated(
        $request, ['CONTROLBOT_OWNER_LOGIN' => 'pl0n3r'], $now, $session, $auth,
        null, null, null, $backend
    );
    $out = [
        'status' => $response['status'], 'entrypoint_status' => $entrypoint['status'],
        'headers' => $response['headers'],
        'plan' => json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR),
        'deterministic' => $response['body'] === $direct['body']
            && $response['body'] === $entrypoint['body'],
        'requests' => $calls, 'snapshot_unchanged' => serialize($rows) === $baseline,
    ];
} elseif ($scenario === 'security') {
    $bad = $rows;
    $bad[1]['context']['dependency_states'] = [];
    $broken = static fn(string $operation, array $vars, array $body, int $at): array =>
        ['version' => 1, 'work_items' => $bad];
    $duplicate = static fn(string $operation, array $vars, array $body, int $at): array =>
        ['version' => 1, 'work_items' => [$rows[0], $rows[0]]];
    $empty = static fn(string $operation, array $vars, array $body, int $at): array =>
        ['version' => 1, 'work_items' => []];
    $case = [
        'anonymous' => $router::dispatch($auth, [], $request, $now, $backend),
        'stale' => $router::dispatch($auth, $session, $request, $now + 301, $backend),
        'missing_backend' => $router::dispatch($auth, $session, $request, $now),
        'wrong_method' => $router::dispatch($auth, $session, $make('PUT', '/api/v1/scheduler'), $now, $backend),
        'post_without_csrf' => $router::dispatch(
            $auth, $session, $make('POST', '/api/v1/scheduler'), $now, $backend
        ),
        'encoded_path' => $router::dispatch(
            $auth, $session, $make('GET', '/api/v1/scheduler%2f'), $now, $backend
        ),
        'invalid_source' => $router::dispatch($auth, $session, $request, $now, $broken),
        'duplicate' => $router::dispatch($auth, $session, $request, $now, $duplicate),
        'empty' => $router::dispatch($auth, $session, $request, $now, $empty),
    ];
    $entrypoint = \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated(
        $request, ['CONTROLBOT_OWNER_LOGIN' => 'pl0n3r'], $now, [], $auth,
        null, null, null, $backend
    );
    $local = \ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner(
        $request, ['CONTROLBOT_OWNER_LOGIN' => 'pl0n3r'], $now
    );
    $bodies = implode('', array_map(static fn(array $r): string => $r['body'], $case));
    $out = [
        'statuses' => array_map(static fn(array $r): int => $r['status'], $case),
        'entrypoint_denied' => $entrypoint['status'],
        'local_denied' => $local['status'],
        'empty_plan' => json_decode($case['empty']['body'], true, 16, JSON_THROW_ON_ERROR),
        'no_backend_calls' => $calls === [],
        'snapshot_unchanged' => serialize($rows) === $baseline,
        'no_store' => array_reduce(
            $case, static fn(bool $value, array $r): bool =>
                $value && ($r['headers']['Cache-Control'] ?? null) === 'no-store', true
        ),
        'no_sensitive_data' => !str_contains($bodies, 'fixture-server-token')
            && !str_contains($bodies, 'JBSWY3DPEHPK3PXP'),
    ];
} else {
    throw new RuntimeException('Unknown fixture scenario.');
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
"""


def scenario(name: str) -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP CLI unavailable")
    run = subprocess.run(
        [php, "-r", PHP, name],
        cwd=ROOT,
        capture_output=True,
        text=True,
        timeout=45,
        check=False,
    )
    if run.returncode:
        raise AssertionError("Scheduler fixture failed: " + run.stderr[-1500:])
    return json.loads(run.stdout)


class SchedulerTests(unittest.TestCase):
    def test_criterio_principal(self):
        result = scenario("principal")
        self.assertEqual(200, result["status"])
        self.assertEqual(200, result["entrypoint_status"])
        self.assertEqual("no-store", result["headers"]["Cache-Control"])
        self.assertTrue(result["deterministic"])
        self.assertTrue(result["snapshot_unchanged"])
        self.assertEqual(
            [["scheduler.plan.read", [], [], 14000]] * 3, result["requests"]
        )
        plan = result["plan"]
        self.assertEqual("factory-dispatcher-v2", plan["policy_ref"])
        self.assertEqual("read_only", plan["mode"])
        self.assertFalse(plan["assignment_committed"])
        self.assertEqual((2, 1), (plan["ready_count"], plan["blocked_count"]))
        self.assertEqual(64, len(plan["selection_request_fingerprint"]))
        items = {item["work_item_id"]: item for item in plan["work_items"]}
        self.assertEqual({"work-critical", "work-medium", "work-reserved"}, set(items))
        self.assertEqual("critical", items["work-critical"]["priority"])
        self.assertTrue(items["work-critical"]["readiness"]["ready"])
        self.assertEqual(["dep-stuck"], items["work-medium"]["dependency_ids"])
        self.assertFalse(items["work-medium"]["readiness"]["ready"])
        self.assertIn("open_dependencies", items["work-medium"]["readiness"]["reasons"])
        self.assertTrue(items["work-reserved"]["reservation"]["held"])
        self.assertEqual(1, items["work-reserved"]["reservation"]["generation"])

    def test_criterio_seguridad_y_bordes(self):
        result = scenario("security")
        self.assertEqual(
            {
                "anonymous": 403, "stale": 403, "missing_backend": 503,
                "wrong_method": 405, "post_without_csrf": 404,
                "encoded_path": 400, "invalid_source": 503,
                "duplicate": 503, "empty": 200,
            },
            result["statuses"],
        )
        self.assertEqual(403, result["entrypoint_denied"])
        self.assertEqual(403, result["local_denied"])
        self.assertTrue(result["no_backend_calls"])
        self.assertTrue(result["snapshot_unchanged"])
        self.assertTrue(result["no_store"])
        self.assertTrue(result["no_sensitive_data"])
        self.assertEqual([], result["empty_plan"]["work_items"])
        self.assertEqual(0, result["empty_plan"]["ready_count"])
        self.assertFalse(result["empty_plan"]["assignment_committed"])


if __name__ == "__main__":
    unittest.main()

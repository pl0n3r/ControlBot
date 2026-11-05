<?php
declare(strict_types=1);

use ControlBot\Runtime\PauseControl;
use ControlBot\Runtime\PauseTransitionAudit;

require_once __DIR__ . '/../../PauseControl.php';
require_once __DIR__ . '/../../PauseTransitionAudit.php';

/**
 * OwnerAuthenticatedRouter checks owner OAuth/TOTP and POST CSRF before calling
 * these handlers. The backend is injected by the server, never by HTTP input.
 */
$read = static function (callable $backend, int $now): array {
    $raw = $backend('pause.global.snapshot', [], [], $now);
    if (!is_array($raw) || array_is_list($raw)
        || array_keys($raw) !== ['revision', 'pause', 'audit', 'active_work', 'actor']
        || !is_int($raw['revision']) || $raw['revision'] < 0
        || !is_string($raw['actor']) || $raw['actor'] === ''
        || !is_array($raw['audit']) || !array_is_list($raw['audit'])
        || !is_array($raw['active_work']) || !array_is_list($raw['active_work'])
        || count($raw['active_work']) > 64) {
        throw new RuntimeException('Pause snapshot unavailable.');
    }
    $seen = [];
    foreach ($raw['active_work'] as $work) {
        if (!is_string($work) || preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $work) !== 1
            || isset($seen[$work])) {
            throw new RuntimeException('Active work snapshot invalid.');
        }
        $seen[$work] = true;
    }
    if ($raw['pause'] !== null) {
        if (!is_array($raw['pause'])) throw new RuntimeException('Pause state unavailable.');
        $raw['pause'] = PauseControl::state($raw['pause']);
        if ($raw['pause']['scope_type'] !== 'global'
            || $raw['pause']['scope_id'] !== 'global'
            || $raw['pause']['source'] !== 'owner'
            || $raw['pause']['state'] === 'unknown') {
            throw new RuntimeException('Global pause state invalid.');
        }
    }
    if ($raw['audit'] !== []) {
        $last = $raw['audit'][count($raw['audit']) - 1];
        $raw['audit'] = PauseTransitionAudit::append($raw['audit'], $last);
        if ($raw['pause'] !== null && ($last['pause_id'] ?? null) !== $raw['pause']['pause_id']) {
            throw new RuntimeException('Pause/audit revision inconsistent.');
        }
        if ($raw['pause'] !== null && ($last['after_state'] ?? null) !== $raw['pause']['state']) {
            throw new RuntimeException('Pause/audit state inconsistent.');
        }
    } elseif ($raw['pause'] !== null) {
        throw new RuntimeException('Unaudited pause state.');
    }
    return $raw;
};

$describe = static function (array $snapshot): array {
    $pause = $snapshot['pause'];
    return [
        'version' => 1,
        'scope' => 'global',
        'state' => $pause !== null && $pause['state'] === 'active' ? 'paused' : 'active',
        'pause_id' => $pause['pause_id'] ?? null,
        'audit_events' => count($snapshot['audit']),
        'revision' => $snapshot['revision'],
    ];
};

return [
    ['method' => 'GET', 'path' => '/api/v1/pausa',
        'handler' => static function (callable $backend, array $vars, array $body, int $now)
            use ($read, $describe): array {
            return $describe($read($backend, $now));
        },
    ],
    ['method' => 'POST', 'path' => '/api/v1/pausa',
        'handler' => static function (callable $backend, array $vars, array $body, int $now)
            use ($read, $describe): array {
            $action = $body['action'] ?? null;
            if (!is_string($action) || !in_array($action, ['pause', 'resume'], true)) {
                throw new InvalidArgumentException('Unsupported pause action.');
            }
            $fields = array_keys($body);
            sort($fields, SORT_STRING);
            $expected = $action === 'pause'
                ? ['action', 'confirmation', 'reason']
                : ['action', 'confirmation'];
            sort($expected, SORT_STRING);
            if ($fields !== $expected
                || $body['confirmation'] !== ($action === 'pause' ? 'PAUSAR TODOS' : 'REANUDAR TODOS')) {
                throw new InvalidArgumentException('Explicit confirmation required.');
            }
            $snapshot = $read($backend, $now);
            $before = $snapshot['pause'];
            if ($action === 'pause') {
                if ($before !== null && $before['state'] !== 'released') {
                    throw new InvalidArgumentException('Already paused.');
                }
                $after = PauseControl::state([
                    'version' => 1, 'pause_id' => 'pause-global-' . implode('x', str_split(bin2hex(random_bytes(12)), 6)),
                    'scope_type' => 'global', 'scope_id' => 'global',
                    'state' => 'active', 'reason' => $body['reason'], 'source' => 'owner',
                    'created_at' => $now, 'activated_at' => $now, 'released_at' => null,
                    'preemptibility' => 'immediate', 'safe_point_at' => null,
                    'policy_version' => null, 'incident_id' => null, 'evidence_ref' => null,
                ]);
                $event = PauseTransitionAudit::event(null, $after, $snapshot['actor'], $now);
                $guard = PauseControl::effective(
                    ['version' => 1, 'session_id' => 'owner-session', 'account_id' => 'owner-account',
                     'project_id' => 'owner-project'], [$after], true,
                );
                if (!$guard['blocked']) throw new RuntimeException('Global freeze not effective.');
            } else {
                if ($before === null || $before['state'] !== 'active') {
                    throw new InvalidArgumentException('No active pause to release.');
                }
                $after = PauseControl::release($before, $now);
                $event = PauseTransitionAudit::event($before, $after, $snapshot['actor'], $now);
            }
            $audit = PauseTransitionAudit::append($snapshot['audit'], $event);
            $payload = [
                'action' => $action,
                'expected_revision' => $snapshot['revision'],
                'expected_sha256' => hash('sha256', json_encode(
                    $snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )),
                'pause' => $after,
                'audit' => $audit,
                'unchanged_active_work' => $snapshot['active_work'],
            ];
            // The trusted adapter MUST atomically CAS-persist pause + audit,
            // and prove visibility at the scheduler before the 30-second SLA.
            $result = $backend('pause.global.commit', [], $payload, $now);
            if (!is_array($result) || ($result['committed'] ?? null) !== true
                || ($result['revision'] ?? null) !== $snapshot['revision'] + 1
                || ($result['pause'] ?? null) !== $after
                || ($result['audit'] ?? null) !== $audit
                || ($result['active_work'] ?? null) !== $snapshot['active_work']
                || ($result['new_assignments'] ?? null) !== 0
                || !is_int($result['scheduler_observed_at'] ?? null)
                || $result['scheduler_observed_at'] < $now
                || $result['scheduler_observed_at'] >= $now + 30) {
                throw new RuntimeException('Pause CAS/scheduler evidence missing.');
            }
            return $describe([
                'pause' => $after, 'audit' => $audit,
                'revision' => $result['revision'],
            ]);
        },
    ],
];

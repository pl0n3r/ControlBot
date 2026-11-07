<?php
declare(strict_types=1);

use ControlBot\Runtime\AgentRuntime;
use ControlBot\Runtime\PresenceAdapter;

require_once __DIR__ . '/../../AgentRuntime.php';
require_once __DIR__ . '/../../PresenceAdapter.php';

/** Server-bound, read-only projection. Never return raw credentials or cookies. */
$project = static function (callable $backend, array $vars, array $request, int $now): array {
    try {
    $data = $backend('agents.presence', $vars, $request, $now);
    $keys = ['accounts', 'agents', 'rows', 'capacity_observations'];
    if (!is_array($data) || array_is_list($data)
        || array_diff(array_keys($data), $keys) !== []
        || array_diff($keys, array_keys($data)) !== []) {
        throw new RuntimeException('Trusted presence source unavailable.');
    }
    foreach (['accounts', 'agents', 'rows'] as $key) {
        if (!is_array($data[$key]) || !array_is_list($data[$key]))
            throw new RuntimeException('Presence collection unavailable.');
    }
    if (!is_array($data['capacity_observations']) || count($data['agents']) > 64)
        throw new RuntimeException('Presence capacity source invalid.');

    $agents = [];
    foreach ($data['agents'] as $raw) {
        $agent = AgentRuntime::agent($raw);
        if (isset($agents[$agent['agent_id']])) throw new RuntimeException('Duplicate agent.');
        $agents[$agent['agent_id']] = [
            'id' => $agent['agent_id'], 'role' => $agent['role'],
            'presence' => 'UNKNOWN', 'session_count' => 0,
        ];
    }
    $accounts = [];
    foreach ($data['accounts'] as $raw) {
        $account = AgentRuntime::account($raw);
        if (isset($accounts[$account['account_id']])) throw new RuntimeException('Duplicate account.');
        $accounts[$account['account_id']] = [
            'id' => $account['account_id'], 'alias' => $account['account_alias'],
            'status' => strtoupper($account['status']), 'capacity' => 'UNKNOWN',
        ];
    }
    if ($accounts === [] && $data['rows'] !== [])
        throw new RuntimeException('Presence without account authority.');
    if ($accounts === [] && $data['capacity_observations'] !== [])
        throw new RuntimeException('Unexpected capacity observation.');

    $snapshot = $accounts === [] ? null : PresenceAdapter::snapshot(
        $data['accounts'], $data['rows'], $now, 90, 300, $data['capacity_observations'],
    );
    $sessions = [];
    foreach ($snapshot['sessions'] ?? [] as $session) {
        $id = $session['agent_id'];
        if (!isset($agents[$id])) throw new RuntimeException('Unregistered agent in presence.');
        $freshness = strtoupper($session['freshness']);
        $agents[$id]['session_count']++;
        if ($agents[$id]['session_count'] === 1) $agents[$id]['presence'] = $freshness;
        elseif ($agents[$id]['presence'] === 'UNKNOWN' || $freshness === 'UNKNOWN')
            $agents[$id]['presence'] = 'UNKNOWN';
        elseif ($freshness === 'HEALTHY') $agents[$id]['presence'] = 'HEALTHY';
        elseif ($agents[$id]['presence'] === 'STALE' || $freshness === 'STALE')
            $agents[$id]['presence'] = 'STALE';
        $sessions[] = [
            'id' => $session['session_id'], 'agent_id' => $id,
            'account_id' => $session['account_id'],
            'status' => strtoupper($session['state']), 'presence' => $freshness,
        ];
    }
    foreach ($snapshot['accounts'] ?? [] as $account) {
        $id = $account['account_id'];
        $accounts[$id]['capacity'] = $account['observed_state'] === 'unknown'
            ? 'UNKNOWN' : strtoupper($account['observed_state']);
        $accounts[$id]['free_slots'] = $account['observed_state'] === 'unknown'
            ? null : $account['free_capacity'];
    }
    ksort($agents); ksort($accounts);
    return [
        'version' => 1, 'state' => $agents === [] && $accounts === [] ? 'UNKNOWN' : 'READY',
        'presence' => strtoupper($snapshot['presence_state'] ?? 'unknown'),
        'capacity' => strtoupper($snapshot['capacity_state'] ?? 'unknown'),
        'agents' => array_values($agents), 'accounts' => array_values($accounts),
        'sessions' => $sessions,
    ];
    } catch (InvalidArgumentException $error) {
        // Invalid server-bound observations are upstream failures, never 400 from the caller.
        throw new RuntimeException('Invalid trusted presence data.', 0, $error);
    }
};

return [
    ['method' => 'GET', 'path' => '/api/v1/agents/presence', 'handler' => $project],
];

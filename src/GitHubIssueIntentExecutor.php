<?php
declare(strict_types=1);
namespace ControlBot\GitHub;
require_once __DIR__ . '/GitHubIntentEnvelope.php';
require_once __DIR__ . '/GitHubIntentAuthorityRequirements.php';
require_once __DIR__ . '/GitHubIntentTestGrantAuthority.php';
/**
 * In-memory ISSUE simulator. A valid offline HMAC test grant is always DENY in
 * real runtime. There is NO GitHub transport or production capability here.
 */
final class GitHubIssueIntentExecutor
{
    private const TYPES = ['issue.create', 'issue.update', 'issue.close', 'issue.reserve', 'issue.release'];
    public static function simulate(
        array $rawEnvelope, array $fixture, array $fakeState,
        array $audit = [], array $ledger = []
    ): array {
        $before = ['fake_state' => $fakeState, 'audit' => $audit, 'ledger' => $ledger];
        try {
            if (!self::fields($fixture, [
                'requirements', 'approval', 'trusted', 'seen', 'revoked',
                'now', 'key', 'issue_number', 'outcome'
            ]) || !self::fields($fakeState, ['issues', 'events']) || !self::rows($fakeState['issues'], 100) || !self::rows($fakeState['events'], 100) || !self::rows($audit, 100) || !is_array($ledger) || count($ledger) > 100 || !is_int($fixture['now']) || $fixture['now'] < 1 || !is_string($fixture['key']) || strlen($fixture['key']) !== 32 || !is_int($fixture['issue_number']) || $fixture['issue_number'] < 1 || !is_array($fixture['requirements']) || !is_array($fixture['approval']) || !is_array($fixture['trusted']) || !is_array($fixture['seen']) || !is_array($fixture['revoked']) || !self::issues($fakeState['issues'])) {
                return self::out('denied', 'invalid_fixture', $before);
            }
            $intent = GitHubIntentEnvelope::envelope($rawEnvelope);
            $type = $intent['type'];
            if (!in_array($type, self::TYPES, true)) {
                return self::out('denied', 'unsupported_type', $before, $intent);
            }
            $requirements = GitHubIntentAuthorityRequirements::describe(
                $rawEnvelope, $intent['project_ref'], $intent['repository_ref']
            );
            if (($requirements['state'] ?? null) !== 'requirements' || $requirements !== $fixture['requirements']) {
                return self::out('denied', 'requirements_mismatch', $before, $intent);
            }
            $trusted = $fixture['trusted'];
            if (($trusted['mode'] ?? null) !== 'offline_test_only' || ($trusted['origin'] ?? null) !== 'server' || ($trusted['project_ref'] ?? null) !== $intent['project_ref'] || ($trusted['repository_ref'] ?? null) !== $intent['repository_ref']) {
                return self::out('denied', 'untrusted_context', $before, $intent);
            }
            $gate = GitHubIntentTestGrantAuthority::evaluate(
                $requirements, $fixture['approval'], $trusted, $fixture['seen'],
                $fixture['revoked'], $fixture['now'], $fixture['key']
            );
            if (($gate['test_grant_valid'] ?? null) !== true || ($gate['reason'] ?? null) !== 'verified_test_only' || ($gate['decision'] ?? null) !== 'deny' || ($gate['execution'] ?? null) !== false || ($gate['runtime_authorized'] ?? null) !== false || ($gate['authorized'] ?? null) !== false) {
                return self::out('denied', 'test_grant_invalid', $before, $intent);
            }
            if (!in_array($fixture['outcome'], ['success', 'failure', 'ambiguous'], true)) {
                return self::out('denied', 'invalid_fake_transport', $before, $intent);
            }
            $number = $fixture['issue_number'];
            if ($type !== 'issue.create' && $intent['params']['issue_number'] !== $number) {
                return self::out('denied', 'issue_number_mismatch', $before, $intent);
            }
            $key = $intent['idempotency_key'];
            $digest = hash('sha256', json_encode([
                $intent['project_ref'], $intent['repository_ref'], $type,
                $intent['params'], $number
            ], JSON_THROW_ON_ERROR));
            if (array_key_exists($key, $ledger)) {
                $record = $ledger[$key];
                if (!self::fieldsOrFalse($record, ['digest', 'receipt']) || $record['digest'] !== $digest || !is_array($record['receipt']) || ($record['receipt']['status'] ?? null) !== 'executed') {
                    return self::out('denied', 'idempotency_conflict', $before, $intent);
                }
                $response = self::out('simulated', 'idempotent_replay', $before, $intent);
                $response['receipt'] = $record['receipt'];
                $response['replayed'] = true;
                return $response;
            }
            // A simulated transport failure must not mutate any fake state.
            if ($fixture['outcome'] !== 'success') {
                $status = $fixture['outcome'] === 'ambiguous' ? 'ambiguous' : 'failed';
                return self::out($status, 'fake_transport_' . $status, $before, $intent, $fixture['now'], $digest);
            }
            $next = $fakeState;
            $position = null;
            foreach ($next['issues'] as $index => $issue) {
                if ($issue['number'] === $number) $position = $index;
            }
            if ($type === 'issue.create') {
                if ($position !== null) return self::out('denied', 'issue_exists', $before, $intent);
                $next['issues'][] = ['number' => $number, 'state' => 'open', 'reservation_ref' => null];
            } else {
                if ($position === null) return self::out('denied', 'issue_not_found', $before, $intent);
                if ($next['issues'][$position]['state'] !== 'open') {
                    return self::out('denied', 'issue_not_open', $before, $intent);
                }
                if ($type === 'issue.close') {
                    if ($next['issues'][$position]['reservation_ref'] !== null) {
                        return self::out('denied', 'reservation_active', $before, $intent);
                    }
                    $next['issues'][$position]['state'] = 'closed';
                } elseif ($type === 'issue.reserve') {
                    if ($next['issues'][$position]['reservation_ref'] !== null) {
                        return self::out('denied', 'reservation_active', $before, $intent);
                    }
                    $next['issues'][$position]['reservation_ref'] = $intent['params']['reservation_ref'];
                } elseif ($type === 'issue.release') {
                    if ($next['issues'][$position]['reservation_ref'] !== $intent['params']['reservation_ref']) {
                        return self::out('denied', 'reservation_mismatch', $before, $intent);
                    }
                    $next['issues'][$position]['reservation_ref'] = null;
                }
                // issue.update is an in-memory event only, never an HTTP write.
            }
            $next['events'][] = ['type' => $type, 'number' => $number, 'fake_only' => true];
            $audit[] = [
                'intent_hash' => hash('sha256', $intent['intent_id']),
                'type' => $type, 'status' => 'simulated',
                'scope' => 'repository', 'runtime_authorized' => false
            ];
            $receipt = self::receipt($intent, 'executed', $fixture['now'], $digest);
            $ledger[$key] = ['digest' => $digest, 'receipt' => $receipt];
            $response = self::out('simulated', 'offline_fake_only', [
                'fake_state' => $next, 'audit' => $audit, 'ledger' => $ledger
            ], $intent);
            $response['receipt'] = $receipt;
            return $response;
        } catch (\Throwable) {
            return self::out('denied', 'invalid_fixture', $before);
        }
    }
    private static function fields(array $value, array $names): bool
    {
        if (array_is_list($value)) return false;
        $keys = array_keys($value);
        sort($keys);
        sort($names);
        return $keys === $names;
    }
    private static function fieldsOrFalse(mixed $value, array $names): bool
    {
        return is_array($value) && self::fields($value, $names);
    }
    private static function rows(mixed $rows, int $limit): bool
    {
        return is_array($rows) && array_is_list($rows) && count($rows) <= $limit;
    }
    private static function issues(array $issues): bool
    {
        $numbers = [];
        foreach ($issues as $issue) {
            if (!self::fieldsOrFalse($issue, ['number', 'state', 'reservation_ref']) || !is_int($issue['number']) || $issue['number'] < 1 || isset($numbers[$issue['number']]) || !in_array($issue['state'], ['open', 'closed'], true) || ($issue['reservation_ref'] !== null && (!is_string($issue['reservation_ref']) || preg_match('#^controlbot:fixture/[a-z0-9._/-]{1,100}$#D', $issue['reservation_ref']) !== 1)) || ($issue['state'] === 'closed' && $issue['reservation_ref'] !== null)) {
                return false;
            }
            $numbers[$issue['number']] = true;
        }
        return true;
    }
    private static function receipt(array $intent, string $status, int $now, string $digest): array
    {
        return [
            'intent_id' => $intent['intent_id'],
            'project_id' => $intent['project_ref'],
            'repository_id' => $intent['repository_ref'],
            'type' => $intent['type'],
            'status' => $status,
            'idempotency_key' => $intent['idempotency_key'],
            'request_digest' => $digest,
            'evidence_ref' => $intent['evidence_refs'][0],
            'started_at' => $now,
            'finished_at' => $now
        ];
    }
    private static function out(
        string $status, string $reason, array $snapshot, ?array $intent = null,
        ?int $now = null, ?string $digest = null
    ): array {
        $output = [
            'decision' => 'deny', 'execution' => false, 'runtime_authorized' => false,
            'fake_effect' => ['status' => $status, 'reason' => $reason],
            'fake_state' => $snapshot['fake_state'], 'audit' => $snapshot['audit'],
            'ledger' => $snapshot['ledger'], 'replayed' => false
        ];
        if ($intent !== null && $now !== null && $digest !== null) {
            $receiptStatus = match($status) {
                'ambiguous' => 'ambiguous', 'failed' => 'failed', default => 'rejected'
            };
            $output['receipt'] = self::receipt($intent, $receiptStatus, $now, $digest);
            $output['receipt']['error_code'] = $reason;
        }
        return $output;
    }
}

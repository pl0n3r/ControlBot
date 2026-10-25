<?php
declare(strict_types=1);
namespace ControlBot\GitHub;

require_once __DIR__ . '/GitHubIntentEnvelope.php';
require_once __DIR__ . '/GitHubIntentAuthorityRequirements.php';
require_once __DIR__ . '/GitHubIntentTestGrantAuthority.php';

/** An isolated fake PR/release simulator. There is no GitHub transport or runtime grant. */
final class GitHubPullRequestIntentExecutor
{
    private const TYPES = ['pr.review', 'pr.merge', 'release.approve'];
    private const SHA = '/^[a-f0-9]{40}$/D';
    private const REQUIRED = ['Validar', 'SonarCloud Code Analysis', 'CodeQL', 'Factory policy'];

    public static function simulate(
        array $rawEnvelope, array $fixture, array $fakeState,
        array $audit = [], array $ledger = []
    ): array {
        $original = ['fake_state' => $fakeState, 'audit' => $audit, 'ledger' => $ledger];
        try {
            if (!self::fields($fixture, [
                'requirements', 'approval', 'trusted', 'seen', 'revoked',
                'now', 'key', 'pr', 'release', 'outcome'
            ]) || !self::fields($fakeState, ['reviews', 'merges', 'approvals'])
                || !self::rows($fakeState['reviews'], 100)
                || !self::rows($fakeState['merges'], 100)
                || !self::rows($fakeState['approvals'], 100)
                || !self::rows($audit, 100)
                || !is_array($ledger) || count($ledger) > 100
                || !is_int($fixture['now']) || !is_string($fixture['key'])
                || !is_array($fixture['requirements']) || !is_array($fixture['approval'])
                || !is_array($fixture['trusted']) || !is_array($fixture['seen'])
                || !is_array($fixture['revoked'])) {
                return self::result('denied', 'invalid_fixture', $original);
            }
            $intent = GitHubIntentEnvelope::envelope($rawEnvelope);
            $type = $intent['type'];
            if (!in_array($type, self::TYPES, true)) {
                return self::result('denied', 'unsupported_type', $original);
            }
            $expected = GitHubIntentAuthorityRequirements::describe(
                $rawEnvelope, $intent['project_ref'], $intent['repository_ref']
            );
            if (($expected['state'] ?? null) !== 'requirements'
                || $expected !== $fixture['requirements']) {
                return self::result('denied', 'requirements_mismatch', $original);
            }
            $trusted = $fixture['trusted'];
            if (($trusted['origin'] ?? null) !== 'server'
                || ($trusted['mode'] ?? null) !== 'offline_test_only'
                || ($trusted['project_ref'] ?? null) !== $intent['project_ref']
                || ($trusted['repository_ref'] ?? null) !== $intent['repository_ref']) {
                return self::result('denied', 'untrusted_context', $original);
            }
            $gate = GitHubIntentTestGrantAuthority::evaluate(
                $expected, $fixture['approval'], $trusted, $fixture['seen'],
                $fixture['revoked'], $fixture['now'], $fixture['key']
            );
            if (($gate['test_grant_valid'] ?? null) !== true
                || ($gate['reason'] ?? null) !== 'verified_test_only'
                || ($gate['decision'] ?? null) !== 'deny'
                || ($gate['execution'] ?? null) !== false
                || ($gate['runtime_authorized'] ?? null) !== false
                || ($gate['authorized'] ?? null) !== false
                || ($gate['grant_issued'] ?? null) !== false
                || ($gate['approval_issued'] ?? null) !== false) {
                return self::result('denied', 'test_grant_invalid', $original);
            }
            if (!in_array($fixture['outcome'], ['success', 'failure', 'ambiguous'], true)) {
                return self::result('denied', 'invalid_fake_transport', $original);
            }
            if ($type === 'release.approve') {
                if ($fixture['pr'] !== null || !self::fieldsOrFalse($fixture['release'], [
                    'release_ref', 'candidate_sha', 'ready'
                ]) || ($fixture['release']['ready'] ?? null) !== true
                    || ($fixture['release']['release_ref'] ?? null) !== $intent['params']['release_ref']
                    || ($fixture['release']['candidate_sha'] ?? null) !== $intent['params']['candidate_sha']
                    || !preg_match(self::SHA, $fixture['release']['candidate_sha'])) {
                    return self::result('denied', 'release_candidate_mismatch', $original);
                }
            } else {
                if ($fixture['release'] !== null || !self::fieldsOrFalse($fixture['pr'], [
                    'number', 'head_sha', 'mergeable', 'checks'
                ]) || ($fixture['pr']['number'] ?? null) !== $intent['params']['pr_number']
                    || !is_int($fixture['pr']['number']) || $fixture['pr']['number'] < 1
                    || !is_string($fixture['pr']['head_sha'])
                    || !preg_match(self::SHA, $fixture['pr']['head_sha'])
                    || !is_bool($fixture['pr']['mergeable'])
                    || !self::checks($fixture['pr']['checks'])) {
                    return self::result('denied', 'pr_evidence_invalid', $original);
                }
                if ($type === 'pr.merge') {
                    if ($fixture['pr']['head_sha'] !== $intent['params']['expected_head_sha']) {
                        return self::result('denied', 'expected_head_sha_mismatch', $original);
                    }
                    if ($fixture['pr']['mergeable'] !== true) {
                        return self::result('denied', 'pr_not_mergeable', $original);
                    }
                    if (!self::green($fixture['pr']['checks'])) {
                        return self::result('denied', 'checks_not_terminal_green', $original);
                    }
                }
            }
            $key = $intent['idempotency_key'];
            $fingerprint = hash('sha256', json_encode([
                $type, $intent['project_ref'], $intent['repository_ref'],
                $intent['params'], $fixture['pr'], $fixture['release'],
            ], JSON_THROW_ON_ERROR));
            if (isset($ledger[$key])) {
                $entry = $ledger[$key];
                if (!self::fieldsOrFalse($entry, ['fingerprint', 'status'])
                    || $entry['fingerprint'] !== $fingerprint
                    || $entry['status'] !== 'simulated') {
                    return self::result('denied', 'idempotency_conflict', $original);
                }
                $replay = self::result('simulated', 'idempotent_replay', $original);
                $replay['replayed'] = true;
                return $replay;
            }
            if ($fixture['outcome'] !== 'success') {
                return self::result(
                    $fixture['outcome'] === 'ambiguous' ? 'ambiguous' : 'failed',
                    'fake_transport_' . $fixture['outcome'], $original
                );
            }
            $next = $fakeState;
            if ($type === 'pr.review') {
                $next['reviews'][] = [
                    'pr_number' => $intent['params']['pr_number'],
                    'head_sha' => $fixture['pr']['head_sha'],
                    'review_ref' => $intent['params']['review_ref']
                ];
            } elseif ($type === 'pr.merge') {
                $next['merges'][] = [
                    'pr_number' => $intent['params']['pr_number'],
                    'head_sha' => $fixture['pr']['head_sha'],
                    'merge_method' => $intent['params']['merge_method']
                ];
            } else {
                $next['approvals'][] = [
                    'release_ref' => $intent['params']['release_ref'],
                    'candidate_sha' => $intent['params']['candidate_sha'],
                    'published' => false
                ];
            }
            $audit[] = [
                'intent_hash' => hash('sha256', $intent['intent_id']),
                'type' => $type, 'status' => 'simulated',
                'scope' => $expected['scope'], 'runtime_authorized' => false
            ];
            $ledger[$key] = ['fingerprint' => $fingerprint, 'status' => 'simulated'];
            return self::result('simulated', 'offline_fake_only', [
                'fake_state' => $next, 'audit' => $audit, 'ledger' => $ledger
            ]);
        } catch (\Throwable) {
            return self::result('denied', 'invalid_fixture', $original);
        }
    }

    private static function fields(array $value, array $expected): bool
    {
        if (array_is_list($value)) return false;
        $keys = array_keys($value);
        sort($keys); sort($expected);
        return $keys === $expected;
    }

    private static function fieldsOrFalse(mixed $value, array $expected): bool
    {
        return is_array($value) && self::fields($value, $expected);
    }

    private static function rows(mixed $value, int $limit): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= $limit;
    }

    private static function checks(mixed $checks): bool
    {
        if (!self::rows($checks, 30)) return false;
        $names = [];
        foreach ($checks as $check) {
            if (!self::fieldsOrFalse($check, ['name', 'status', 'conclusion'])
                || !is_string($check['name']) || strlen($check['name']) > 120
                || !preg_match('/^[A-Za-z0-9_. \/-]+$/D', $check['name'])
                || isset($names[$check['name']])
                || !in_array($check['status'], ['queued', 'in_progress', 'completed'], true)
                || !in_array($check['conclusion'], [null, 'success', 'failure', 'cancelled', 'skipped'], true)) {
                return false;
            }
            $names[$check['name']] = true;
        }
        return true;
    }

    private static function green(array $checks): bool
    {
        $successful = [];
        foreach ($checks as $check) {
            if ($check['status'] !== 'completed' || $check['conclusion'] !== 'success') return false;
            $successful[$check['name']] = true;
        }
        foreach (self::REQUIRED as $required) {
            if (!isset($successful[$required])) return false;
        }
        return true;
    }

    private static function result(string $status, string $reason, array $snapshot): array
    {
        return [
            'decision' => 'deny', 'execution' => false, 'runtime_authorized' => false,
            'fake_effect' => ['status' => $status, 'reason' => $reason],
            'fake_state' => $snapshot['fake_state'], 'audit' => $snapshot['audit'],
            'ledger' => (object) $snapshot['ledger'], 'replayed' => false,
        ];
    }
}

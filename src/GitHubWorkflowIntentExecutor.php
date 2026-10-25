<?php
declare(strict_types=1);
namespace ControlBot\GitHub;

require_once __DIR__ . '/GitHubIntentEnvelope.php';
require_once __DIR__ . '/GitHubIntentAuthorityRequirements.php';
require_once __DIR__ . '/GitHubIntentTestGrantAuthority.php';

/** Pure offline fixture simulator. NEVER a runtime GitHub adapter. */
final class GitHubWorkflowIntentExecutor
{
    private const TYPES = ['workflow.dispatch', 'project.freeze', 'project.unfreeze'];
    private const SENSITIVE = '/(?:password|secret|credential|bearer|private[_-]?key|api[_-]?key|token|cookie|otp)/i';

    public static function simulate(
        array $rawEnvelope, array $fixture, array $fakeState,
        array $audit = [], array $ledger = []
    ): array {
        $original = ['fake_state' => $fakeState, 'audit' => $audit, 'ledger' => $ledger];
        try {
            if (!self::fields($fixture, [
                'requirements', 'approval', 'trusted', 'seen', 'revoked',
                'now', 'key', 'allowed_workflows', 'allowed_refs', 'inputs', 'outcome'
            ]) || !self::fields($fakeState, ['frozen', 'dispatches'])
                || !is_bool($fakeState['frozen'])
                || !is_array($fakeState['dispatches']) || !array_is_list($fakeState['dispatches'])
                || !array_is_list($audit) || count($audit) > 100
                || !is_array($ledger) || count($ledger) > 100
                || !is_int($fixture['now']) || !is_string($fixture['key'])
                || !is_array($fixture['requirements']) || !is_array($fixture['approval'])
                || !is_array($fixture['trusted']) || !is_array($fixture['seen'])
                || !is_array($fixture['revoked'])) {
                return self::result('denied', 'invalid_fixture', $original);
            }
            $intent = GitHubIntentEnvelope::envelope($rawEnvelope);
            if (!in_array($intent['type'], self::TYPES, true)) {
                return self::result('denied', 'unsupported_type', $original);
            }
            $expected = GitHubIntentAuthorityRequirements::describe(
                $rawEnvelope, $intent['project_ref'], $intent['repository_ref']
            );
            if (($expected['state'] ?? null) !== 'requirements'
                || $fixture['requirements'] !== $expected) {
                return self::result('denied', 'requirements_mismatch', $original);
            }
            $trusted = $fixture['trusted'];
            if (($trusted['mode'] ?? null) !== 'offline_test_only'
                || ($trusted['origin'] ?? null) !== 'server'
                || ($trusted['project_ref'] ?? null) !== $intent['project_ref']
                || ($trusted['repository_ref'] ?? null) !== $intent['repository_ref']) {
                return self::result('denied', 'untrusted_context', $original);
            }
            $gate = GitHubIntentTestGrantAuthority::evaluate(
                $fixture['requirements'], $fixture['approval'], $trusted,
                $fixture['seen'], $fixture['revoked'], $fixture['now'], $fixture['key']
            );
            if (($gate['test_grant_valid'] ?? null) !== true
                || ($gate['decision'] ?? null) !== 'deny'
                || ($gate['execution'] ?? null) !== false
                || ($gate['runtime_authorized'] ?? null) !== false) {
                return self::result('denied', 'test_grant_invalid', $original);
            }
            if (!self::allowlist($fixture['allowed_workflows'], '/^[A-Za-z0-9_.-]{1,120}$/D')
                || !self::allowlist($fixture['allowed_refs'], '#^[A-Za-z0-9][A-Za-z0-9._/-]{0,119}$#D')
                || !self::inputs($fixture['inputs'])
                || !in_array($fixture['outcome'], ['success', 'failure', 'ambiguous'], true)) {
                return self::result('denied', 'invalid_fake_transport', $original);
            }
            $type = $intent['type'];
            if ($type === 'workflow.dispatch' && (
                !in_array($intent['params']['workflow_ref'], $fixture['allowed_workflows'], true)
                || !in_array($intent['params']['git_ref'], $fixture['allowed_refs'], true)
            )) {
                return self::result('denied', 'workflow_or_ref_not_allowed', $original);
            }
            if ($type === 'workflow.dispatch'
                && !str_starts_with($intent['params']['inputs_ref'], 'controlbot:fixture/')) {
                return self::result('denied', 'inputs_not_fixture', $original);
            }
            $key = $intent['idempotency_key'];
            $fingerprint = hash('sha256', json_encode([
                $intent['type'], $intent['project_ref'], $intent['repository_ref'],
                $intent['params'], $fixture['inputs']
            ], JSON_THROW_ON_ERROR));
            if (isset($ledger[$key])) {
                $entry = $ledger[$key];
                if (!is_array($entry) || ($entry['fingerprint'] ?? null) !== $fingerprint
                    || ($entry['status'] ?? null) !== 'simulated') {
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
            if ($type === 'workflow.dispatch') {
                $next['dispatches'][] = [
                    'workflow' => $intent['params']['workflow_ref'],
                    'ref' => $intent['params']['git_ref']
                ];
            } else {
                $next['frozen'] = $type === 'project.freeze';
            }
            $audit[] = [
                'intent_hash' => hash('sha256', $intent['intent_id']),
                'type' => $type, 'status' => 'simulated',
                'scope' => $expected['scope'],
                'reversible' => $type !== 'workflow.dispatch'
            ];
            $ledger[$key] = ['fingerprint' => $fingerprint, 'status' => 'simulated'];
            return self::result('simulated', 'offline_fake_only', [
                'fake_state' => $next, 'audit' => $audit, 'ledger' => $ledger
            ]);
        } catch (\Throwable) {
            return self::result('denied', 'invalid_fixture', $original);
        }
    }

    private static function fields(array $row, array $expected): bool
    {
        if (array_is_list($row)) return false;
        $a = array_keys($row);
        sort($a);
        sort($expected);
        return $a === $expected;
    }

    private static function allowlist(mixed $values, string $pattern): bool
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 20) return false;
        foreach ($values as $value) {
            if (!is_string($value) || !preg_match($pattern, $value)
                || preg_match(self::SENSITIVE, $value)) return false;
        }
        return count(array_unique($values)) === count($values);
    }

    private static function inputs(mixed $inputs): bool
    {
        if (!is_array($inputs) || count($inputs) > 8 || strlen(json_encode($inputs) ?: '') > 1024)
            return false;
        foreach ($inputs as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $name)
                || preg_match(self::SENSITIVE, $name)
                || !is_string($value) || strlen($value) > 120
                || !preg_match('#^[A-Za-z0-9 ._:/-]*$#D', $value)
                || preg_match(self::SENSITIVE, $value)) return false;
        }
        return true;
    }

    private static function result(string $status, string $reason, array $snapshot): array
    {
        return [
            'decision' => 'deny', 'execution' => false, 'runtime_authorized' => false,
            'fake_effect' => ['status' => $status, 'reason' => $reason],
            'fake_state' => $snapshot['fake_state'], 'audit' => $snapshot['audit'],
            'ledger' => $snapshot['ledger'], 'replayed' => false
        ];
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

require_once __DIR__ . '/GitHubIntentTestGrantAuthority.php';

/**
 * Isolated HMAC fixture exercise. It NEVER authorizes or executes an intent.
 * A simulated effect means only that a deterministic in-memory fixture passed.
 */
final class GitHubIntentOfflineGrantHarness
{
    public static function simulate(
        array $requirements,
        array $approval,
        array $trusted,
        array $seenNonces,
        array $revokedNonces,
        int $now,
        string $fixtureKey,
        array $fakeAction
    ): array {
        try {
            if (!self::exactFields($fakeAction, ['type', 'scope_ref', 'fixture_ref'])
                || !is_string($fakeAction['type'])
                || !is_string($fakeAction['scope_ref'])
                || !is_string($fakeAction['fixture_ref'])
                || !preg_match('#^controlbot:fixture/[a-zA-Z0-9_-]{1,64}$#D', $fakeAction['fixture_ref'])
                || $fakeAction['type'] !== ($requirements['type'] ?? null)
                || $fakeAction['scope_ref'] !== ($requirements['scope_ref'] ?? null)) {
                return self::deny('invalid_fake_action');
            }

            // The existing issuer + HMAC verifier enforce exact owner, scope,
            // approval, issuer, trusted clock, nonce and expiry. They never ALLOW.
            $gate = GitHubIntentTestGrantAuthority::evaluate(
                $requirements, $approval, $trusted, $seenNonces,
                $revokedNonces, $now, $fixtureKey
            );
            if (($gate['test_grant_valid'] ?? null) !== true
                || ($gate['reason'] ?? null) !== 'verified_test_only'
                || ($gate['decision'] ?? null) !== 'deny'
                || ($gate['runtime_authorized'] ?? null) !== false
                || ($gate['authorized'] ?? null) !== false
                || ($gate['execution'] ?? null) !== false
                || ($gate['grant_issued'] ?? null) !== false
                || ($gate['approval_issued'] ?? null) !== false) {
                return self::deny('invalid_test_grant');
            }
            $proof = $gate['fixture_proof'] ?? null;
            if (!is_array($proof) || !is_string($proof['signature'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $proof['signature'])) {
                return self::deny('invalid_proof');
            }

            // A fingerprint of a verified synthetic fixture, NOT an action,
            // workflow dispatch, GitHub request, state transition, or grant.
            $digest = hash('sha256', json_encode([
                $fakeAction['type'], $fakeAction['scope_ref'],
                $fakeAction['fixture_ref'], $proof['signature']
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::result('simulated', 'offline_fixture_only', $digest);
        } catch (\Throwable) {
            return self::deny('invalid_fixture');
        }
    }

    private static function exactFields(array $value, array $expected): bool
    {
        if (array_is_list($value)) {
            return false;
        }
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }

    private static function deny(string $reason): array
    {
        return self::result('denied', $reason, null);
    }

    private static function result(string $status, string $reason, ?string $digest): array
    {
        return [
            'decision' => 'deny',
            'reason' => $reason,
            'execution' => false,
            'runtime_authorized' => false,
            'authorized' => false,
            'grant_issued' => false,
            'approval_issued' => false,
            'approval_verified' => false,
            'test_grant_valid' => $status === 'simulated',
            'fixture_status' => $status === 'simulated' ? 'accepted_offline' : 'rejected',
            'fake_effect' => [
                'status' => $status,
                'reason' => $reason,
                'fixture_digest' => $digest,
            ],
        ];
    }
}

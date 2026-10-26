<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use Closure;
use Throwable;

require_once __DIR__ . '/GitHubIntentOfflineGrantHarness.php';

/**
 * Offline-only execution boundary for an injected in-memory FAKE sender.
 * Never creates GitHub grants, credentials, HTTP requests or runtime authority.
 * Real GitHub capability policy remains DENY independently of fixture results.
 */
final class GitHubIntentExecutionGateway
{
    private array $receipts = [];
    private array $events = [];

    /** Sender takes only ['fixture_digest' => sha256, 'mode' => 'offline_test_only']. */
    public function __construct(private readonly Closure $fakeSender) {}

    /**
     * Evaluate fresh owner approval and HMAC fixture on EVERY call, including
     * idempotent retries. No attacker-controlled client context grants rights.
     * Input is a fixture from trusted offline test code, never HTTP or a form.
     */
    public function simulate(array $fixture, string $idempotencyKey): array
    {
        $digest = null;
        $keyHash = null;
        try {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $idempotencyKey)
                || preg_match('/(?:password|secret|bearer|github_pat|gh[pousr]_)/i', $idempotencyKey)
                || !self::hasFields($fixture, [
                    'requirements', 'approval', 'trusted', 'seen', 'revoked',
                    'now', 'fixture_key', 'fake_action',
                ])
                || !is_array($fixture['requirements'])
                || !is_array($fixture['approval'])
                || !is_array($fixture['trusted'])
                || !is_array($fixture['seen'])
                || !is_array($fixture['revoked'])
                || !is_array($fixture['fake_action'])
                || !is_int($fixture['now'])
                || !is_string($fixture['fixture_key'])
                || strlen($fixture['fixture_key']) < 32) {
                return $this->record('denied', 'invalid_fixture', null, null);
            }
            $keyHash = hash('sha256', $idempotencyKey);
            $gate = GitHubIntentOfflineGrantHarness::simulate(
                $fixture['requirements'], $fixture['approval'], $fixture['trusted'],
                $fixture['seen'], $fixture['revoked'], $fixture['now'],
                $fixture['fixture_key'], $fixture['fake_action'],
            );
            // HMAC proof only authorizes this *synthetic simulation*, never a
            // runtime grant. This guard must run BEFORE reading cached results.
            if (($gate['decision'] ?? null) !== 'deny'
                || ($gate['execution'] ?? null) !== false
                || ($gate['runtime_authorized'] ?? null) !== false
                || ($gate['authorized'] ?? null) !== false
                || ($gate['grant_issued'] ?? null) !== false
                || ($gate['approval_issued'] ?? null) !== false
                || ($gate['approval_verified'] ?? null) !== false
                || ($gate['test_grant_valid'] ?? null) !== true
                || ($gate['fixture_status'] ?? null) !== 'accepted_offline'
                || ($gate['fake_effect']['status'] ?? null) !== 'simulated') {
                return $this->record('denied', 'untrusted_or_revoked_fixture', null, $keyHash);
            }
            $digest = $gate['fake_effect']['fixture_digest'] ?? null;
            if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                return $this->record('denied', 'invalid_fixture_digest', null, $keyHash);
            }
            if (isset($this->receipts[$keyHash])) {
                $previous = $this->receipts[$keyHash];
                if ($previous['fixture_digest'] !== $digest) {
                    return $this->record('denied', 'idempotency_conflict', null, $keyHash);
                }
                // No sender call and no duplicate audit event on safe retry.
                return $previous;
            }
            try {
                // The only value passed to the local fake is an opaque digest.
                $response = ($this->fakeSender)([
                    'fixture_digest' => $digest, 'mode' => 'offline_test_only',
                ]);
            } catch (Throwable) {
                $response = null;
            }
            if (!is_array($response)
                || !self::hasFields($response, ['status', 'fixture_digest'])
                || $response['status'] !== 'simulated'
                || !is_string($response['fixture_digest'])
                || !hash_equals($digest, $response['fixture_digest'])) {
                $receipt = $this->record('ambiguous', 'fake_sender_uncertain', $digest, $keyHash);
            } else {
                $receipt = $this->record('simulated', 'offline_fixture_only', $digest, $keyHash);
            }
            $this->receipts[$keyHash] = $receipt;
            return $receipt;
        } catch (Throwable) {
            return $this->record('denied', 'invalid_fixture', null, $keyHash);
        }
    }

    /** In-memory sanitized audit; neither tokens nor owner metadata are kept. */
    public function audit(): array
    {
        return $this->events;
    }

    private function record(string $status, string $reason, ?string $digest, ?string $keyHash): array
    {
        $receipt = [
            'decision' => 'deny', 'execution' => false,
            'runtime_authorized' => false, 'authorized' => false,
            'grant_issued' => false, 'approval_issued' => false,
            'approval_verified' => false,
            'fixture_status' => $status, 'reason' => $reason,
            'fixture_digest' => $digest,
        ];
        $this->events[] = [
            'fixture_status' => $status,
            'reason' => $reason,
            'idempotency_sha256' => $keyHash,
            'fixture_digest' => $digest,
        ];
        return $receipt;
    }

    private static function hasFields(array $value, array $expected): bool
    {
        if (array_is_list($value)) return false;
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use Closure;
use Throwable;

/**
 * Offline-only bridge for a server-owned session resolver and an in-memory FAKE.
 * This class is deliberately not wired to routes, real sessions or GitHub HTTP.
 * Verified fixtures NEVER authorize a runtime GitHub operation.
 */
final class GitHubOwnerSessionApprovalBridge
{
    private const MAX_WINDOW = 120;
    private const MAX_AUDIT = 64;
    private array $consumed = [];
    private array $events = [];

    /**
     * $trustedSessionSource is server-owned, NOT derived from a request body.
     * It takes an opaque TEST session ref and returns verified canonical data.
     * $fakeSender receives only a digest and a constant offline mode.
     */
    public function __construct(
        private readonly Closure $trustedSessionSource,
        private readonly Closure $fakeSender,
        private readonly string $expectedOwner = 'pl0n3r',
    ) {}

    public function simulate(array $request, int $now): array
    {
        $nonceHash = null;
        try {
            if ($now < 0 || !self::exactFields($request, ['session_ref', 'action', 'scope_ref', 'nonce'])
                || !is_string($request['session_ref'])
                || preg_match('#^fixture-session/[A-Za-z0-9_-]{8,64}$#D', $request['session_ref']) !== 1
                || !is_string($request['action'])
                || preg_match('/^[a-z][a-z0-9.]{2,63}$/D', $request['action']) !== 1
                || !is_string($request['scope_ref'])
                || preg_match('#^pl0n3r/[A-Za-z0-9_.-]{1,80}$#D', $request['scope_ref']) !== 1
                || !is_string($request['nonce'])
                || preg_match('/^[a-f0-9]{32}$/D', $request['nonce']) !== 1) {
                return $this->record('denied', 'invalid_request', null);
            }
            $nonceHash = hash('sha256', $request['nonce']);
            if (isset($this->consumed[$nonceHash])) {
                return $this->record('denied', 'replay', $nonceHash);
            }
            $record = ($this->trustedSessionSource)($request['session_ref']);
            if (!is_array($record) || !self::exactFields($record, [
                'source', 'origin', 'owner', 'owner_verified', 'server_verified',
                'session_ref', 'bound_action', 'bound_scope_ref', 'bound_nonce',
                'reauthenticated_at', 'issued_at', 'expires_at', 'revoked',
            ]) || ($record['source'] ?? null) !== 'trusted_fixture'
                || ($record['origin'] ?? null) !== 'server'
                || ($record['owner_verified'] ?? null) !== true
                || ($record['server_verified'] ?? null) !== true
                || ($record['revoked'] ?? null) !== false
                || !is_string($record['owner'])
                || !hash_equals($this->expectedOwner, $record['owner'])
                || !is_string($record['session_ref'])
                || !hash_equals($request['session_ref'], $record['session_ref'])
                || !is_string($record['bound_action'])
                || !hash_equals($request['action'], $record['bound_action'])
                || !is_string($record['bound_scope_ref'])
                || !hash_equals($request['scope_ref'], $record['bound_scope_ref'])
                || !is_string($record['bound_nonce'])
                || !hash_equals($request['nonce'], $record['bound_nonce'])
                || !is_int($record['reauthenticated_at'])
                || !is_int($record['issued_at'])
                || !is_int($record['expires_at'])
                || $record['issued_at'] < 0
                || $record['reauthenticated_at'] < $record['issued_at']
                || $record['reauthenticated_at'] > $now
                || $now - $record['reauthenticated_at'] > self::MAX_WINDOW
                || $record['issued_at'] > $now
                || $now - $record['issued_at'] > self::MAX_WINDOW
                || $record['expires_at'] < $now
                || $record['expires_at'] < $record['issued_at']
                || $record['expires_at'] - $record['issued_at'] > self::MAX_WINDOW) {
                return $this->record('denied', 'session_not_verified', $nonceHash);
            }
            // Consume the nonce BEFORE the fake callback (including uncertain failures).
            $this->consumed[$nonceHash] = true;
            $digest = hash('sha256', json_encode([
                $request['action'], $request['scope_ref'], $request['nonce'],
                $record['issued_at'], $record['expires_at'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            try {
                $response = ($this->fakeSender)([
                    'fixture_digest' => $digest,
                    'mode' => 'offline_test_only',
                ]);
            } catch (Throwable) {
                $response = null;
            }
            if (!is_array($response)
                || !self::exactFields($response, ['status', 'fixture_digest'])
                || $response['status'] !== 'simulated'
                || !is_string($response['fixture_digest'])
                || !hash_equals($digest, $response['fixture_digest'])) {
                return $this->record('ambiguous', 'fake_sender_uncertain', $nonceHash);
            }
            return $this->record('simulated', 'offline_fixture_only', $nonceHash);
        } catch (Throwable) {
            return $this->record('denied', 'invalid_fixture', $nonceHash);
        }
    }

    public function audit(): array
    {
        return $this->events;
    }

    private function record(string $status, string $reason, ?string $nonceHash): array
    {
        $receipt = [
            'decision' => 'deny', 'execution' => false,
            'runtime_authorized' => false, 'authorized' => false,
            'grant_issued' => false, 'approval_issued' => false,
            'approval_verified' => false,
            'fixture_status' => $status, 'reason' => $reason,
        ];
        $this->events[] = [
            'fixture_status' => $status,
            'reason' => $reason,
            'nonce_sha256' => $nonceHash,
        ];
        if (count($this->events) > self::MAX_AUDIT) {
            array_shift($this->events);
        }
        return $receipt;
    }

    private static function exactFields(array $actual, array $expected): bool
    {
        if (array_is_list($actual)) return false;
        $keys = array_keys($actual);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }
}

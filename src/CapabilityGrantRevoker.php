<?php
declare(strict_types=1);
namespace ControlBot\Production;

use InvalidArgumentException;
use Throwable;

/**
 * Fail-closed, in-process lifecycle guard for an injected fake executor.
 *
 * This does NOT persist grant state between PHP requests. Before any real
 * transport is connected, an authoritative durable revocation ledger is
 * required. No credentials, network client, or effects are provided here.
 */
final class CapabilityGrantRevoker
{
    /** @var array<string, CapabilityGrant> */
    private array $revoked = [];
    /** @var array<string, true> */
    private array $inFlight = [];
    /** @var array<string, string> Key -> grant ID reserved before invoking fake executor. */
    private array $inFlightKeys = [];
    /** @var array<string, array{grant_id:string,record_digest:string,scope_digest:string,result:array}> */
    private array $byIdempotencyKey = [];
    /** Read-only authorization path: never trust an old immutable grant after consumption. */
    public function authorize(CapabilityGrant $grant, array $scope, int $now, array $restrictions = []): array
    {
        $id = $grant->safeRecord()['grant_id'];
        if (isset($this->inFlight[$id])) {
            return ['authorized' => false, 'reason' => 'grant_in_flight'];
        }
        if (isset($this->revoked[$id])) {
            return ['authorized' => false, 'reason' => 'grant_consumed'];
        }
        $key = $grant->safeRecord()['idempotency_key'];
        if (isset($this->inFlightKeys[$key]) || isset($this->byIdempotencyKey[$key])) {
            return ['authorized' => false, 'reason' => 'idempotency_collision'];
        }
        return $grant->authorize($scope, $now, $restrictions);
    }
    /**
     * Execute only a caller-injected fake under effective policy restrictions, and revoke
     * on success, ambiguity and failure. The callback's response/exception is NEVER
     * included in the return value or the audit record.
     *
     * @return array{status:string,reason:string,error_code?:string,grant:CapabilityGrant,audit:array}
     */
    public function execute(CapabilityGrant $grant, array $scope, int $now, callable $fakeExecutor, array $restrictions = []): array
    {
        // Prevent invalid/unbounded timestamps from causing failure in finally.
        if ($now < 1 || $now > 253402300799) {
            throw new InvalidArgumentException('now inválido.');
        }
        $record = $grant->safeRecord();
        $id = $record['grant_id'];
        $key = $record['idempotency_key'];
        $recordDigest = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
        $stableScope = $scope;
        ksort($stableScope);
        $scopeDigest = hash('sha256', json_encode([$stableScope, $restrictions], JSON_THROW_ON_ERROR));
        if (isset($this->inFlight[$id])) {
            return $this->denied($grant, 'grant_in_flight', $now);
        }
        // Lock the idempotency key as well as the grant ID while a fake callback
        // is running. A different grant must not re-enter on the same key.
        if (isset($this->inFlightKeys[$key])) {
            return $this->denied($grant, 'idempotency_collision', $now);
        }
        if (isset($this->byIdempotencyKey[$key])) {
            $entry = $this->byIdempotencyKey[$key];
            if ($entry['grant_id'] !== $id || !isset($this->revoked[$id])
                || !hash_equals($entry['record_digest'], $recordDigest)
                || !hash_equals($entry['scope_digest'], $scopeDigest)) {
                return $this->denied($grant, 'idempotency_collision', $now);
            }
            // Same key/grant/scope: replay the previous receipt, never the effect.
            return $entry['result'];
        }
        if (isset($this->revoked[$id])) {
            return $this->denied($this->revoked[$id], 'grant_consumed', $now);
        }
        $authorization = $this->authorize($grant, $scope, $now, $restrictions);
        if ($authorization['authorized'] !== true) {
            return $this->denied($grant, $authorization['reason'], $now);
        }

        $this->inFlight[$id] = true;
        $this->inFlightKeys[$key] = $id;
        $status = 'failed';
        $reason = 'fake_execution_failed';
        try {
            // No transport is instantiated here. Callback cannot raise authority:
            // the existing CapabilityGrant::authorize policy gate has run first.
            $ack = $fakeExecutor();
            if ($ack === true) {
                $status = 'executed';
                $reason = 'executed';
            } else {
                $status = 'ambiguous';
                $reason = 'fake_execution_unconfirmed';
            }
        } catch (Throwable $_ignored) {
            // Never include exception class/message/stack or callback payload.
        } finally {
            $revoked = $grant->revoke(gmdate('Y-m-d\TH:i:s\Z', $now));
            $this->revoked[$id] = $revoked;
            unset($this->inFlight[$id], $this->inFlightKeys[$key]);
        }
        $result = [
            'status' => $status,
            'reason' => $reason,
            'grant' => $this->revoked[$id],
            'audit' => [
                'event' => 'grant_consumed',
                'status' => $status,
                'at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            ],
        ];
        if ($status === 'failed') {
            $result['error_code'] = 'fake_execution_failed';
        }
        if ($status === 'ambiguous') {
            $result['error_code'] = 'fake_execution_unconfirmed';
        }
        $this->byIdempotencyKey[$key] = [
            'grant_id' => $id, 'record_digest' => $recordDigest,
            'scope_digest' => $scopeDigest, 'result' => $result,
        ];
        return $result;
    }
    private function denied(CapabilityGrant $grant, string $reason, int $now): array
    {
        // Reject unknown reason text instead of propagating untrusted data.
        $allowed = [
            'grant_in_flight', 'grant_consumed', 'idempotency_collision',
            'grant_revoked', 'grant_expired', 'grant_not_active', 'policy_denied',
            'backup_receipt_required', 'owner_approval_required',
        ];
        if (!in_array($reason, $allowed, true) && !str_ends_with($reason, '_mismatch')) {
            $reason = 'authorization_denied';
        }
        return [
            'status' => 'rejected',
            'reason' => $reason,
            'grant' => $grant,
            'audit' => ['event' => 'grant_rejected', 'status' => 'rejected', 'at' => gmdate('Y-m-d\TH:i:s\Z', $now)],
        ];
    }
}

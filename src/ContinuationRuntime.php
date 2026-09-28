<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;

final class ContinuationRuntime
{
    /** @var array<string,array{action:OwnerAction,token:ContinuationToken}> */
    private array $pending = [];

    /** @var array<string,array<string,mixed>> */
    private array $terminal = [];

    public function interrupt(array $actionRecord, ?array $tokenRecord, int $now): array
    {
        $action = OwnerAction::fromRecord($actionRecord);
        $safeAction = $action->safeRecord();
        $policy = CapabilityPolicy::classify($safeAction['capability']);

        if (!$policy['known'] || $policy['decision'] === 'forbidden') {
            throw new RuntimeException('Capability no autorizable.');
        }
        if ($policy['requires_owner_approval'] !== true) {
            return [
                'interrupted' => false,
                'decision' => $policy['decision'],
                'owner_action' => null,
                'continuation' => null,
            ];
        }
        if ($tokenRecord === null) {
            throw new InvalidArgumentException('Continuation requerida para owner_required.');
        }

        $token = ContinuationToken::fromRecord($tokenRecord);
        $safeToken = $token->safeRecord();
        if ($safeToken['blocked_on'] !== 'owner_approval') {
            throw new InvalidArgumentException('Continuation owner_required debe bloquear en owner_approval.');
        }
        $this->assertBoundScope($safeAction, $safeToken);
        $token->assertCurrent([
            'source_sha' => $safeToken['source_sha'],
            'expected_action' => $safeToken['expected_action'],
            'plan_digest' => $safeToken['plan_digest'],
            'risk_digest' => $safeToken['risk_digest'],
        ], $now);

        $id = $safeToken['continuation_id'];
        if (isset($this->terminal[$id])) {
            return $this->terminal[$id];
        }
        if (isset($this->pending[$id])) {
            $existing = $this->pending[$id];
            if ($existing['action']->safeRecord() !== $safeAction
                || $existing['token']->safeRecord() !== $safeToken) {
                throw new RuntimeException('Continuation id reutilizada con otro scope.');
            }
        } else {
            $this->pending[$id] = ['action' => $action, 'token' => $token];
        }

        return [
            'interrupted' => true,
            'decision' => 'owner_required',
            'owner_action' => $safeAction,
            'continuation' => $safeToken,
        ];
    }

    public function ownerSurface(string $continuationId): array
    {
        if (isset($this->terminal[$continuationId])) {
            return [
                'owner_action' => $this->terminal[$continuationId]['owner_action'],
                'continuation' => $this->terminal[$continuationId]['continuation'],
            ];
        }
        $pending = $this->pending[$continuationId] ?? null;
        if ($pending === null) {
            throw new RuntimeException('Continuation desconocida.');
        }
        return [
            'owner_action' => $pending['action']->safeRecord(),
            'continuation' => $pending['token']->safeRecord(),
        ];
    }

    public function approve(
        string $continuationId,
        array $current,
        string $ownerApprovalId,
        string $grantId,
        string $idempotencyKey,
        string $subject,
        string $issuedAt,
        string $expiresAt,
        int $now,
        ?string $backupReceiptId = null,
    ): array {
        if (isset($this->terminal[$continuationId])) {
            return $this->terminal[$continuationId];
        }
        $pending = $this->pending[$continuationId] ?? null;
        if ($pending === null) {
            throw new RuntimeException('Continuation desconocida.');
        }

        $action = $pending['action']->safeRecord();
        $token = $pending['token'];
        $this->assertCurrentScope($action, $token->safeRecord(), $current, $now);

        $policy = CapabilityPolicy::classify($action['capability']);
        if ($policy['decision'] !== 'owner_required' || $policy['requires_owner_approval'] !== true) {
            throw new RuntimeException('Capability dejó de requerir autorización del dueño.');
        }

        $tokenClosed = $token->close($issuedAt, true);
        $tokenRecord = $tokenClosed->safeRecord();
        $grant = CapabilityGrant::issue([
            'version' => 1,
            'grant_id' => $grantId,
            'capability' => $action['capability'],
            'project' => $action['project'],
            'environment' => $action['environment'],
            'resource' => $action['resource'],
            'operation' => $action['operation'],
            'issue' => $tokenRecord['issue'],
            'run_id' => $tokenRecord['run_id'],
            'subject' => $subject,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'backup_receipt_id' => $backupReceiptId,
            'owner_approval_id' => $ownerApprovalId,
            'idempotency_key' => $idempotencyKey,
            'revoked_at' => null,
        ]);

        $result = [
            'interrupted' => false,
            'status' => 'approved',
            'execution' => 'resumed',
            'owner_action' => $action,
            'continuation' => $tokenRecord,
            'grant' => $grant->safeRecord(),
        ];
        $this->terminal[$continuationId] = $result;
        unset($this->pending[$continuationId]);
        return $result;
    }

    public function reject(
        string $continuationId,
        array $current,
        string $closedAt,
        int $now,
    ): array {
        if (isset($this->terminal[$continuationId])) {
            return $this->terminal[$continuationId];
        }
        $pending = $this->pending[$continuationId] ?? null;
        if ($pending === null) {
            throw new RuntimeException('Continuation desconocida.');
        }

        $action = $pending['action']->safeRecord();
        $token = $pending['token'];
        $this->assertCurrentScope($action, $token->safeRecord(), $current, $now);
        $closed = $token->close($closedAt, false)->safeRecord();

        $result = [
            'interrupted' => false,
            'status' => 'rejected',
            'execution' => 'not-performed',
            'owner_action' => $action,
            'continuation' => $closed,
            'grant' => null,
        ];
        $this->terminal[$continuationId] = $result;
        unset($this->pending[$continuationId]);
        return $result;
    }

    private function assertBoundScope(array $action, array $token): void
    {
        foreach (['project', 'environment', 'issue'] as $field) {
            if ($action[$field] !== $token[$field]) {
                throw new InvalidArgumentException('OwnerAction/Continuation scope mismatch: ' . $field);
            }
        }
        if ($action['operation'] !== $token['expected_action']) {
            throw new InvalidArgumentException('OwnerAction/Continuation action mismatch.');
        }
    }

    private function assertCurrentScope(array $action, array $token, array $current, int $now): void
    {
        $expected = [
            'capability', 'project', 'environment', 'resource', 'operation', 'issue',
            'run_id', 'source_sha', 'plan_digest', 'risk_digest',
        ];
        $keys = array_keys($current);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Current scope inválido.');
        }

        foreach (['capability', 'project', 'environment', 'resource', 'operation'] as $field) {
            if (!is_string($current[$field]) || $current[$field] !== $action[$field]) {
                throw new RuntimeException('Autorización invalidada por drift: ' . $field);
            }
        }
        foreach (['issue', 'run_id'] as $field) {
            if (!is_string($current[$field]) || $current[$field] !== $token[$field]) {
                throw new RuntimeException('Autorización invalidada por drift: ' . $field);
            }
        }

        $token->assertCurrent([
            'source_sha' => $current['source_sha'],
            'expected_action' => $current['operation'],
            'plan_digest' => $current['plan_digest'],
            'risk_digest' => $current['risk_digest'],
        ], $now);
    }
}

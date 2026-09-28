<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class IdentityCenter
{
    private const OPERATIONS = [
        'invite', 'create', 'suspend', 'reactivate', 'grant_scope', 'revoke_scope',
        'change_role', 'change_capability', 'request_reauth', 'request_reset', 'set_mfa_required',
    ];
    private const REQUEST_KINDS = ['invite', 'reauth', 'reset'];
    private const MFA_STATES = ['unknown', 'required', 'satisfied'];

    public static function normalizeCommand(array $raw, int $now): array
    {
        self::now($now);
        self::rejectSensitive($raw);
        $raw += ['expires_at' => null];
        self::fields($raw, [
            'version', 'command_id', 'idempotency_key', 'operation', 'actor_identity_id',
            'reason_code', 'scope', 'expires_at', 'payload',
        ], 'IdentityCommand');
        if (($raw['version'] ?? null) !== 1) throw new InvalidArgumentException('IdentityCommand version invalid.');

        $operation = self::enum($raw['operation'], self::OPERATIONS, 'operation');
        $expires = self::nullableTimestamp($raw['expires_at'], 'expires_at');
        if ($expires !== null && $expires <= $now) throw new InvalidArgumentException('expires_at invalid.');

        return [
            'version' => 1,
            'command_id' => self::key($raw['command_id'], 'command_id'),
            'idempotency_key' => self::key($raw['idempotency_key'], 'idempotency_key'),
            'operation' => $operation,
            'actor_identity_id' => self::id($raw['actor_identity_id'], 'actor_identity_id'),
            'reason_code' => self::reason($raw['reason_code']),
            'scope' => self::scope($raw['scope']),
            'expires_at' => $expires,
            'payload' => self::payload($operation, $raw['payload'], $now),
        ];
    }

    public static function execute(
        array $state,
        array $command,
        array $verifiedContext,
        array $decisionGrant,
        int $now,
    ): array {
        $state = self::state($state, $now);
        $command = self::normalizeCommand($command, $now);

        foreach ($state['audit'] as $event) {
            if ($event['idempotency_key'] !== $command['idempotency_key']) continue;
            if ($event['command_id'] !== $command['command_id']) {
                throw new InvalidArgumentException('idempotency collision.');
            }
            return ['status' => 'already_applied', 'state' => $state, 'audit_event' => $event];
        }

        $targetId = self::targetIdentityId($state, $command);
        $action = [
            'capability' => self::capabilityFor($command['operation']),
            'required_authority_level' => self::requiredAuthority($command),
            'budget_amount' => null,
        ];
        $decision = DecisionRights::evaluate($verifiedContext, $decisionGrant, $action, $now);
        if ($decision['decision'] !== 'allow') {
            $event = self::audit($command, $targetId, $decision['decision'], $now);
            $state['audit'][] = $event;
            return ['status' => $decision['decision'], 'state' => $state, 'audit_event' => $event];
        }

        $state = self::apply($state, $command, $targetId, $now);
        $event = self::audit($command, $targetId, 'applied', $now);
        $state['audit'][] = $event;
        return ['status' => 'applied', 'state' => $state, 'audit_event' => $event];
    }

    private static function apply(array $state, array $command, string $targetId, int $now): array
    {
        $op = $command['operation'];
        if ($op === 'invite') {
            return self::appendRequest($state, $command, $targetId, 'invite', $now);
        }
        if ($op === 'create') {
            if ($state['identity'] !== null) throw new InvalidArgumentException('identity already exists.');
            $identity = $command['payload']['identity'];
            if ($identity['observed_at'] > $now) throw new InvalidArgumentException('identity observed_at invalid.');
            $state['identity'] = $identity;
            return $state;
        }

        $identity = self::requireIdentity($state);
        if ($op === 'suspend' || $op === 'reactivate') {
            $identity['state'] = $op === 'suspend' ? 'suspended' : 'active';
            $state['identity'] = VentureIdentity::normalizeIdentity($identity);
            return $state;
        }
        if ($op === 'request_reauth' || $op === 'request_reset') {
            return self::appendRequest($state, $command, $targetId, $op === 'request_reauth' ? 'reauth' : 'reset', $now);
        }
        if ($op === 'set_mfa_required') {
            $state['mfa'] = $command['payload'];
            return $state;
        }
        if ($op === 'grant_scope') {
            $grant = $command['payload']['grant'];
            if ($grant['identity_id'] !== $identity['identity_id'] || $grant['scope'] !== $command['scope']) {
                throw new InvalidArgumentException('grant attribution mismatch.');
            }
            foreach ($state['grants'] as $row) {
                if ($row['grant_id'] === $grant['grant_id']) throw new InvalidArgumentException('grant duplicated.');
            }
            $state['grants'][] = $grant;
            usort($state['grants'], static fn(array $a, array $b): int => $a['grant_id'] <=> $b['grant_id']);
            return $state;
        }

        $grantId = $command['payload']['grant_id'];
        $found = false;
        $next = [];
        foreach ($state['grants'] as $grant) {
            if ($grant['grant_id'] !== $grantId) {
                $next[] = $grant;
                continue;
            }
            if ($grant['identity_id'] !== $identity['identity_id'] || $grant['scope'] !== $command['scope']) {
                throw new InvalidArgumentException('grant attribution mismatch.');
            }
            $found = true;
            if ($op === 'revoke_scope') continue;
            if ($op === 'change_role') $grant['role'] = $command['payload']['role'];
            if ($op === 'change_capability') $grant['capability'] = $command['payload']['capability'];
            $next[] = VentureIdentity::normalizeGrant($grant, $now);
        }
        if (!$found) throw new InvalidArgumentException('grant not found.');
        $state['grants'] = $next;
        return $state;
    }

    private static function payload(string $operation, mixed $payload, int $now): array
    {
        if (!is_array($payload)) throw new InvalidArgumentException('payload invalid.');
        if ($operation === 'invite') {
            self::fields($payload, ['identity_id', 'kind', 'display_name', 'source_ref'], 'invite payload');
            $identity = VentureIdentity::normalizeIdentity([
                'version' => 1, 'identity_id' => $payload['identity_id'], 'kind' => $payload['kind'],
                'display_name' => $payload['display_name'], 'state' => 'active',
                'source_ref' => $payload['source_ref'], 'observed_at' => $now,
            ]);
            return ['candidate' => $identity];
        }
        if ($operation === 'create') {
            self::fields($payload, ['identity'], 'create payload');
            if (!is_array($payload['identity'])) throw new InvalidArgumentException('identity invalid.');
            return ['identity' => VentureIdentity::normalizeIdentity($payload['identity'])];
        }
        if ($operation === 'grant_scope') {
            self::fields($payload, ['grant'], 'grant payload');
            if (!is_array($payload['grant'])) throw new InvalidArgumentException('grant invalid.');
            return ['grant' => VentureIdentity::normalizeGrant($payload['grant'], $now)];
        }
        if (in_array($operation, ['revoke_scope', 'change_role', 'change_capability'], true)) {
            $expected = $operation === 'revoke_scope' ? ['grant_id'] :
                ($operation === 'change_role' ? ['grant_id', 'role'] : ['grant_id', 'capability']);
            self::fields($payload, $expected, $operation . ' payload');
            $out = ['grant_id' => self::id($payload['grant_id'], 'grant_id')];
            if ($operation === 'change_role') $out['role'] = self::key($payload['role'], 'role');
            if ($operation === 'change_capability') $out['capability'] = self::capability($payload['capability']);
            return $out;
        }
        if ($operation === 'set_mfa_required') {
            self::fields($payload, ['required', 'status'], 'mfa payload');
            if (!is_bool($payload['required'])) throw new InvalidArgumentException('mfa.required invalid.');
            $status = self::enum($payload['status'], self::MFA_STATES, 'mfa.status');
            if (!$payload['required'] && $status === 'required') throw new InvalidArgumentException('mfa metadata invalid.');
            return ['required' => $payload['required'], 'status' => $status];
        }
        self::fields($payload, [], $operation . ' payload');
        return [];
    }

    private static function state(array $raw, int $now): array
    {
        self::rejectSensitive($raw);
        self::fields($raw, ['identity', 'grants', 'requests', 'audit', 'mfa'], 'IdentityState');
        $identity = $raw['identity'] === null ? null :
            (is_array($raw['identity']) ? VentureIdentity::normalizeIdentity($raw['identity']) : throw new InvalidArgumentException('identity invalid.'));
        if (!is_array($raw['grants']) || !array_is_list($raw['grants']) || count($raw['grants']) > 200) {
            throw new InvalidArgumentException('grants invalid.');
        }
        $grants = [];
        $seen = [];
        foreach ($raw['grants'] as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('grant invalid.');
            $grant = VentureIdentity::normalizeGrant($row, $now);
            if (isset($seen[$grant['grant_id']])) throw new InvalidArgumentException('grant duplicated.');
            $seen[$grant['grant_id']] = true;
            $grants[] = $grant;
        }
        usort($grants, static fn(array $a, array $b): int => $a['grant_id'] <=> $b['grant_id']);
        self::requestList($raw['requests']);
        self::auditList($raw['audit']);
        self::fields($raw['mfa'], ['required', 'status'], 'mfa');
        if (!is_bool($raw['mfa']['required'])) throw new InvalidArgumentException('mfa.required invalid.');
        $mfa = ['required' => $raw['mfa']['required'], 'status' => self::enum($raw['mfa']['status'], self::MFA_STATES, 'mfa.status')];
        return ['identity' => $identity, 'grants' => $grants, 'requests' => $raw['requests'], 'audit' => $raw['audit'], 'mfa' => $mfa];
    }

    private static function appendRequest(array $state, array $command, string $targetId, string $kind, int $now): array
    {
        if ($command['expires_at'] === null) throw new InvalidArgumentException('request expiry required.');
        foreach ($state['requests'] as $row) {
            if ($row['request_id'] === $command['command_id']) throw new InvalidArgumentException('request duplicated.');
        }
        if (count($state['requests']) >= 100) throw new InvalidArgumentException('request capacity exceeded.');
        $state['requests'][] = [
            'request_id' => $command['command_id'], 'kind' => $kind, 'identity_id' => $targetId,
            'scope' => $command['scope'], 'requested_at' => $now, 'expires_at' => $command['expires_at'],
        ];
        return $state;
    }

    private static function requestList(mixed $rows): void
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) throw new InvalidArgumentException('requests invalid.');
        $seen = [];
        foreach ($rows as $row) {
            self::fields($row, ['request_id', 'kind', 'identity_id', 'scope', 'requested_at', 'expires_at'], 'request');
            $id = self::key($row['request_id'], 'request_id');
            if (isset($seen[$id])) throw new InvalidArgumentException('request duplicated.');
            $seen[$id] = true;
            self::enum($row['kind'], self::REQUEST_KINDS, 'request.kind');
            self::id($row['identity_id'], 'request.identity_id');
            self::scope($row['scope']);
            self::timestamp($row['requested_at'], 'request.requested_at');
            self::timestamp($row['expires_at'], 'request.expires_at');
        }
    }

    private static function auditList(mixed $rows): void
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 500) throw new InvalidArgumentException('audit invalid.');
        foreach ($rows as $row) {
            self::fields($row, [
                'event_id', 'command_id', 'idempotency_key', 'operation', 'actor_identity_id',
                'target_identity_id', 'scope', 'outcome', 'reason_code', 'occurred_at', 'expires_at',
            ], 'audit event');
            self::key($row['event_id'], 'event_id'); self::key($row['command_id'], 'command_id');
            self::key($row['idempotency_key'], 'idempotency_key'); self::enum($row['operation'], self::OPERATIONS, 'operation');
            self::id($row['actor_identity_id'], 'actor_identity_id'); self::id($row['target_identity_id'], 'target_identity_id');
            self::scope($row['scope']); self::reason($row['reason_code']); self::timestamp($row['occurred_at'], 'occurred_at');
            self::nullableTimestamp($row['expires_at'], 'expires_at');
        }
    }

    private static function audit(array $command, string $targetId, string $outcome, int $now): array
    {
        return [
            'event_id' => 'audit-' . $command['command_id'], 'command_id' => $command['command_id'],
            'idempotency_key' => $command['idempotency_key'], 'operation' => $command['operation'],
            'actor_identity_id' => $command['actor_identity_id'], 'target_identity_id' => $targetId,
            'scope' => $command['scope'], 'outcome' => $outcome, 'reason_code' => $command['reason_code'],
            'occurred_at' => $now, 'expires_at' => $command['expires_at'],
        ];
    }

    private static function targetIdentityId(array $state, array $command): string
    {
        if ($command['operation'] === 'invite') return $command['payload']['candidate']['identity_id'];
        if ($command['operation'] === 'create') return $command['payload']['identity']['identity_id'];
        return self::requireIdentity($state)['identity_id'];
    }

    private static function requireIdentity(array $state): array
    {
        if ($state['identity'] === null) throw new InvalidArgumentException('identity required.');
        return $state['identity'];
    }

    private static function capabilityFor(string $operation): string
    {
        return match ($operation) {
            'invite', 'create' => 'identity.create',
            'suspend', 'reactivate' => 'identity.lifecycle',
            'grant_scope', 'revoke_scope', 'change_role', 'change_capability' => 'identity.access.manage',
            'request_reauth', 'request_reset' => 'identity.auth.request',
            'set_mfa_required' => 'identity.security.manage',
        };
    }

    private static function requiredAuthority(array $command): string
    {
        if ($command['operation'] === 'grant_scope') return $command['payload']['grant']['authority_level'];
        return in_array($command['operation'], ['request_reauth', 'request_reset'], true)
            ? 'L1_OPERATOR' : 'L2_VENTURE_ADMIN';
    }

    private static function rejectSensitive(mixed $value): void
    {
        if (!is_array($value)) return;
        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match('/password|hash|token|cookie|otp|recovery|secret|credential|session/i', $key) === 1) {
                throw new InvalidArgumentException('sensitive field rejected.');
            }
            self::rejectSensitive($nested);
        }
    }

    private static function capability(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1 || strlen($value) > 120) {
            throw new InvalidArgumentException('capability invalid.');
        }
        return $value;
    }

    private static function scope(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
    }

    private static function reason(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('reason_code invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) throw new InvalidArgumentException($label . ' invalid.');
        return $value;
    }

    private static function key(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $value) !== 1) throw new InvalidArgumentException($label . ' invalid.');
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new InvalidArgumentException($label . ' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) throw new InvalidArgumentException($label . ' invalid.');
        return $value;
    }

    private static function nullableTimestamp(mixed $value, string $label): ?int
    {
        return $value === null ? null : self::timestamp($value, $label);
    }

    private static function now(int $value): void
    {
        if ($value < 1) throw new InvalidArgumentException('now invalid.');
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row)) throw new InvalidArgumentException($label . ' invalid.');
        $actual = array_keys($row); sort($actual); sort($expected);
        if ($actual !== $expected) throw new InvalidArgumentException($label . ' fields invalid.');
    }
}

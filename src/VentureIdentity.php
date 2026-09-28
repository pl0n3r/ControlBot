<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class VentureIdentity
{
    private const IDENTITY_KINDS = ['human', 'agent', 'service'];
    private const IDENTITY_STATES = ['active', 'suspended', 'disabled'];
    private const VENTURE_STATES = ['active', 'paused', 'archived'];
    private const AUTHORITY_LEVELS = [
        'L0_AI_AUTONOMOUS',
        'L1_OPERATOR',
        'L2_VENTURE_ADMIN',
        'L3_GROUP_INSTITUTION',
        'L4_OWNER',
    ];
    private const ROLES = [
        'owner',
        'portfolio_admin',
        'venture_admin',
        'product_lead',
        'operator',
        'contributor',
        'finance',
        'sales',
        'support',
        'viewer',
    ];
    private const EVENT_ACTIONS = ['grant', 'revoke'];

    public static function normalizeGroup(array $raw): array
    {
        self::fields($raw, ['version', 'group_id', 'title', 'owner_identity_id', 'venture_ids'], 'Group');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Group version invalid.');
        }

        return [
            'version' => 1,
            'group_id' => self::id($raw['group_id'], 'group_id'),
            'title' => self::text($raw['title'], 'group.title', 120),
            'owner_identity_id' => self::id($raw['owner_identity_id'], 'owner_identity_id'),
            'venture_ids' => self::idList($raw['venture_ids'], 'venture_ids', 100),
        ];
    }

    public static function normalizeVenture(array $raw): array
    {
        self::fields($raw, [
            'version', 'venture_id', 'group_id', 'slug', 'title', 'strategy_role',
            'state', 'responsible_identity_id', 'project_refs',
        ], 'Venture');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Venture version invalid.');
        }

        return [
            'version' => 1,
            'venture_id' => self::id($raw['venture_id'], 'venture_id'),
            'group_id' => self::id($raw['group_id'], 'group_id'),
            'slug' => self::id($raw['slug'], 'venture.slug'),
            'title' => self::text($raw['title'], 'venture.title', 120),
            'strategy_role' => self::id($raw['strategy_role'], 'venture.strategy_role'),
            'state' => self::enum($raw['state'], self::VENTURE_STATES, 'venture.state'),
            'responsible_identity_id' => self::id($raw['responsible_identity_id'], 'responsible_identity_id'),
            'project_refs' => self::projectRefs($raw['project_refs']),
        ];
    }

    public static function normalizeIdentity(array $raw): array
    {
        self::fields($raw, [
            'version', 'identity_id', 'kind', 'display_name', 'state', 'source_ref', 'observed_at',
        ], 'Identity');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Identity version invalid.');
        }

        return [
            'version' => 1,
            'identity_id' => self::id($raw['identity_id'], 'identity_id'),
            'kind' => self::enum($raw['kind'], self::IDENTITY_KINDS, 'identity.kind'),
            'display_name' => self::text($raw['display_name'], 'identity.display_name', 120),
            'state' => self::enum($raw['state'], self::IDENTITY_STATES, 'identity.state'),
            'source_ref' => self::controlRef($raw['source_ref'], 'identity.source_ref'),
            'observed_at' => self::timestamp($raw['observed_at'], 'identity.observed_at'),
        ];
    }

    public static function normalizeGrant(array $raw, int $now): array
    {
        self::now($now);
        $raw += ['budget_limit' => null, 'expires_at' => null];
        self::fields($raw, [
            'version', 'grant_id', 'identity_id', 'role', 'capability', 'scope',
            'authority_level', 'policy_ref', 'budget_limit', 'granted_at', 'expires_at',
        ], 'AccessGrant');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('AccessGrant version invalid.');
        }

        $grantedAt = self::timestamp($raw['granted_at'], 'grant.granted_at');
        if ($grantedAt > $now) {
            throw new InvalidArgumentException('grant.granted_at invalid.');
        }
        $expiresAt = self::nullableTimestamp($raw['expires_at'], 'grant.expires_at');
        if ($expiresAt !== null && ($expiresAt <= $grantedAt || $expiresAt <= $now)) {
            throw new InvalidArgumentException('grant expired or invalid.');
        }

        return [
            'version' => 1,
            'grant_id' => self::id($raw['grant_id'], 'grant_id'),
            'identity_id' => self::id($raw['identity_id'], 'grant.identity_id'),
            'role' => self::enum($raw['role'], self::ROLES, 'grant.role'),
            'capability' => self::capability($raw['capability']),
            'scope' => self::scope($raw['scope']),
            'authority_level' => self::enum($raw['authority_level'], self::AUTHORITY_LEVELS, 'grant.authority_level'),
            'policy_ref' => self::policyRef($raw['policy_ref']),
            'budget_limit' => self::nullableMoney($raw['budget_limit']),
            'granted_at' => $grantedAt,
            'expires_at' => $expiresAt,
        ];
    }

    public static function normalizeEvent(array $raw): array
    {
        $raw += ['expires_at' => null];
        self::fields($raw, [
            'version', 'event_id', 'action', 'grant_id', 'identity_id', 'actor_identity_id',
            'reason', 'scope', 'occurred_at', 'expires_at',
        ], 'GrantEvent');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('GrantEvent version invalid.');
        }

        $occurredAt = self::timestamp($raw['occurred_at'], 'event.occurred_at');
        $expiresAt = self::nullableTimestamp($raw['expires_at'], 'event.expires_at');
        if ($expiresAt !== null && $expiresAt <= $occurredAt) {
            throw new InvalidArgumentException('event.expires_at invalid.');
        }

        return [
            'version' => 1,
            'event_id' => self::id($raw['event_id'], 'event_id'),
            'action' => self::enum($raw['action'], self::EVENT_ACTIONS, 'event.action'),
            'grant_id' => self::id($raw['grant_id'], 'event.grant_id'),
            'identity_id' => self::id($raw['identity_id'], 'event.identity_id'),
            'actor_identity_id' => self::id($raw['actor_identity_id'], 'event.actor_identity_id'),
            'reason' => self::text($raw['reason'], 'event.reason', 240),
            'scope' => self::scope($raw['scope']),
            'occurred_at' => $occurredAt,
            'expires_at' => $expiresAt,
        ];
    }

    public static function scopedGrants(array $grants, string $scope, int $now): array
    {
        $scope = self::scope($scope);
        $normalized = self::grantList($grants, $now);
        $out = array_values(array_filter(
            $normalized,
            static fn (array $grant): bool => $grant['scope'] === $scope,
        ));
        usort($out, static fn (array $a, array $b): int => $a['grant_id'] <=> $b['grant_id']);
        return $out;
    }

    public static function revokeGrant(
        array $identity,
        array $grants,
        array $history,
        string $grantId,
        array $event,
        int $now,
    ): array {
        $identity = self::normalizeIdentity($identity);
        $grants = self::grantList($grants, $now);
        $history = self::eventList($history, $now);
        $grantId = self::id($grantId, 'grant_id');
        $event = self::normalizeEvent($event);

        if ($event['action'] !== 'revoke' || $event['occurred_at'] > $now) {
            throw new InvalidArgumentException('Revocation event invalid.');
        }

        $target = null;
        $remaining = [];
        foreach ($grants as $grant) {
            if ($grant['grant_id'] === $grantId) {
                $target = $grant;
                continue;
            }
            $remaining[] = $grant;
        }
        if ($target === null) {
            throw new InvalidArgumentException('Grant to revoke not found.');
        }
        if (
            $target['identity_id'] !== $identity['identity_id']
            || $event['grant_id'] !== $target['grant_id']
            || $event['identity_id'] !== $identity['identity_id']
            || $event['scope'] !== $target['scope']
            || $event['expires_at'] !== $target['expires_at']
            || $event['occurred_at'] < $target['granted_at']
        ) {
            throw new InvalidArgumentException('Revocation attribution mismatch.');
        }

        foreach ($history as $prior) {
            if ($prior['event_id'] === $event['event_id']) {
                throw new InvalidArgumentException('GrantEvent duplicated.');
            }
        }
        if (count($history) >= 500) {
            throw new InvalidArgumentException('history capacity exceeded.');
        }
        $history[] = $event;
        usort($history, static function (array $a, array $b): int {
            $byTime = $a['occurred_at'] <=> $b['occurred_at'];
            return $byTime !== 0 ? $byTime : ($a['event_id'] <=> $b['event_id']);
        });

        usort($remaining, static fn (array $a, array $b): int => $a['grant_id'] <=> $b['grant_id']);
        return [
            'identity' => $identity,
            'grants' => $remaining,
            'history' => $history,
        ];
    }

    private static function grantList(mixed $rows, int $now): array
    {
        self::now($now);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 200) {
            throw new InvalidArgumentException('grants invalid.');
        }
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('grant invalid.');
            }
            $grant = self::normalizeGrant($row, $now);
            if (isset($seen[$grant['grant_id']])) {
                throw new InvalidArgumentException('AccessGrant duplicated.');
            }
            $seen[$grant['grant_id']] = true;
            $out[] = $grant;
        }
        return $out;
    }

    private static function eventList(mixed $rows, int $now): array
    {
        self::now($now);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 500) {
            throw new InvalidArgumentException('history invalid.');
        }
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('GrantEvent invalid.');
            }
            $event = self::normalizeEvent($row);
            if ($event['occurred_at'] > $now) {
                throw new InvalidArgumentException('GrantEvent from future.');
            }
            if (isset($seen[$event['event_id']])) {
                throw new InvalidArgumentException('GrantEvent duplicated.');
            }
            $seen[$event['event_id']] = true;
            $out[] = $event;
        }
        return $out;
    }

    private static function projectRefs(mixed $refs): array
    {
        if (!is_array($refs) || !array_is_list($refs) || count($refs) > 100) {
            throw new InvalidArgumentException('project_refs invalid.');
        }
        $out = [];
        foreach ($refs as $ref) {
            $ref = self::controlRef($ref, 'project_ref');
            if (!str_starts_with($ref, 'controlbot:project/')) {
                throw new InvalidArgumentException('project_ref invalid.');
            }
            if (isset($out[$ref])) {
                throw new InvalidArgumentException('project_ref duplicated.');
            }
            $out[$ref] = true;
        }
        $refs = array_keys($out);
        sort($refs);
        return $refs;
    }

    private static function idList(mixed $values, string $label, int $max): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > $max) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $out = [];
        foreach ($values as $value) {
            $value = self::id($value, $label);
            if (isset($out[$value])) {
                throw new InvalidArgumentException($label . ' duplicated.');
            }
            $out[$value] = true;
        }
        $values = array_keys($out);
        sort($values);
        return $values;
    }

    private static function capability(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1 || strlen($value) > 120) {
            throw new InvalidArgumentException('grant.capability invalid.');
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

    private static function policyRef(mixed $value): string
    {
        if (!is_string($value) || preg_match('#^controlbot:policy/[a-z][a-z0-9._/-]{1,119}$#D', $value) !== 1) {
            throw new InvalidArgumentException('grant.policy_ref invalid.');
        }
        return $value;
    }

    private static function controlRef(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\#@-]{1,238}$#D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableMoney(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('grant.budget_limit invalid.');
        }
        if (!is_finite((float) $value) || $value < 0) {
            throw new InvalidArgumentException('grant.budget_limit invalid.');
        }
        return (float) $value;
    }

    private static function nullableTimestamp(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }
        return self::timestamp($value, $label);
    }

    private static function now(int $value): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException('now invalid.');
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

use InvalidArgumentException;

final class PauseControl
{
    private const SCOPES = ['session' => 1, 'account' => 2, 'project' => 3, 'global' => 4];
    private const STATES = ['active', 'releasing', 'released', 'unknown'];
    private const SOURCES = ['owner', 'policy', 'system'];
    private const PREEMPTIBILITY = ['immediate', 'safe_point', 'non_preemptible'];

    public static function state(array $raw): array
    {
        self::fields($raw, [
            'version', 'pause_id', 'scope_type', 'scope_id', 'state', 'reason', 'source',
            'created_at', 'activated_at', 'released_at', 'preemptibility', 'safe_point_at',
            'policy_version', 'incident_id', 'evidence_ref',
        ]);
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('PauseState version invalid.');
        }
        $scope = self::choice($raw['scope_type'], array_keys(self::SCOPES), 'scope_type');
        $state = self::choice($raw['state'], self::STATES, 'state');
        $source = self::choice($raw['source'], self::SOURCES, 'source');
        $preemptibility = self::choice($raw['preemptibility'], self::PREEMPTIBILITY, 'preemptibility');
        $created = self::time($raw['created_at'], 'created_at');
        $activated = self::nullableTime($raw['activated_at'], 'activated_at');
        $released = self::nullableTime($raw['released_at'], 'released_at');
        $safePoint = self::nullableTime($raw['safe_point_at'], 'safe_point_at');

        if (($activated !== null && $activated < $created)
            || ($released !== null && ($activated === null || $released < $activated))
            || ($safePoint !== null && $safePoint < $created)) {
            throw new InvalidArgumentException('PauseState timestamps invalid.');
        }
        if ($state === 'unknown' && ($activated !== null || $released !== null || $safePoint !== null)) {
            throw new InvalidArgumentException('Unknown PauseState cannot claim activation evidence.');
        }
        if (in_array($state, ['active', 'releasing'], true) && ($activated === null || $released !== null)) {
            throw new InvalidArgumentException('Active PauseState timestamps invalid.');
        }
        if ($state === 'released' && ($activated === null || $released === null)) {
            throw new InvalidArgumentException('Released PauseState timestamps invalid.');
        }
        if ($preemptibility === 'immediate' && $safePoint !== null) {
            throw new InvalidArgumentException('Immediate pause cannot claim a safe point.');
        }
        if ($state !== 'unknown' && $preemptibility !== 'immediate'
            && ($safePoint === null || $activated === null || $safePoint > $activated)) {
            throw new InvalidArgumentException('Pause activation requires safe-point evidence.');
        }

        $policyVersion = self::nullableRef($raw['policy_version'], 'policy_version');
        $incidentId = self::nullableRef($raw['incident_id'], 'incident_id');
        $evidenceRef = self::nullableRef($raw['evidence_ref'], 'evidence_ref');
        if ($scope === 'project' && $source === 'policy'
            && ($policyVersion === null || $incidentId === null || $evidenceRef === null)) {
            throw new InvalidArgumentException('Policy project freeze requires provenance.');
        }

        return [
            'version' => 1,
            'pause_id' => self::ref($raw['pause_id'], 'pause_id'),
            'scope_type' => $scope,
            'scope_id' => $scope === 'global' ? self::globalId($raw['scope_id']) : self::ref($raw['scope_id'], 'scope_id'),
            'state' => $state,
            'reason' => self::text($raw['reason'], 'reason', 240),
            'source' => $source,
            'created_at' => $created,
            'activated_at' => $activated,
            'released_at' => $released,
            'preemptibility' => $preemptibility,
            'safe_point_at' => $safePoint,
            'policy_version' => $policyVersion,
            'incident_id' => $incidentId,
            'evidence_ref' => $evidenceRef,
        ];
    }

    public static function effective(array $contextRaw, array $statesRaw, bool $mutation = true): array
    {
        $context = self::context($contextRaw);
        if (!array_is_list($statesRaw) || count($statesRaw) > 32) {
            throw new InvalidArgumentException('PauseState list invalid.');
        }
        $winner = null;
        $seen = [];
        foreach ($statesRaw as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('PauseState invalid.');
            }
            $pause = self::state($raw);
            if (isset($seen[$pause['pause_id']])) {
                throw new InvalidArgumentException('PauseState duplicated.');
            }
            $seen[$pause['pause_id']] = true;
            self::assertScope($pause, $context);
            $blocks = in_array($pause['state'], ['active', 'releasing'], true)
                || ($mutation && $pause['state'] === 'unknown');
            if (!$blocks) {
                continue;
            }
            $rank = self::SCOPES[$pause['scope_type']];
            if ($winner === null || $rank > $winner['rank']
                || ($rank === $winner['rank'] && strcmp($pause['pause_id'], $winner['pause']['pause_id']) < 0)) {
                $winner = ['rank' => $rank, 'pause' => $pause];
            }
        }
        $pause = $winner['pause'] ?? null;
        return [
            'blocked' => $pause !== null,
            'mutation_allowed' => $pause === null,
            'effective_scope' => $pause['scope_type'] ?? null,
            'effective_pause_id' => $pause['pause_id'] ?? null,
            'effective_state' => $pause['state'] ?? null,
        ];
    }

    public static function release(array $raw, int $releasedAt): array
    {
        $pause = self::state($raw);
        if ($pause['state'] === 'unknown') {
            throw new InvalidArgumentException('Unknown PauseState cannot be released.');
        }
        if ($pause['state'] === 'released') {
            return $pause;
        }
        if ($releasedAt < ($pause['activated_at'] ?? PHP_INT_MAX)) {
            throw new InvalidArgumentException('released_at invalid.');
        }
        $pause['state'] = 'released';
        $pause['released_at'] = $releasedAt;
        return self::state($pause);
    }

    private static function context(array $raw): array
    {
        self::fields($raw, ['version', 'session_id', 'account_id', 'project_id']);
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Pause context version invalid.');
        }
        return [
            'version' => 1,
            'session_id' => self::ref($raw['session_id'], 'session_id'),
            'account_id' => self::ref($raw['account_id'], 'account_id'),
            'project_id' => self::ref($raw['project_id'], 'project_id'),
        ];
    }

    private static function assertScope(array $pause, array $context): void
    {
        $expected = match ($pause['scope_type']) {
            'session' => $context['session_id'],
            'account' => $context['account_id'],
            'project' => $context['project_id'],
            'global' => 'global',
        };
        if ($pause['scope_id'] !== $expected) {
            throw new InvalidArgumentException('PauseState scope mismatch.');
        }
    }

    private static function fields(array $raw, array $expected): void
    {
        $actual = array_keys($raw);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('PauseState fields invalid.');
        }
    }

    private static function choice(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('/(?:password|passwd|bearer\s+|token\s*[:=]|secret\s*[:=]|cookie\s*[:=]|authorization\s*[:=])/i', $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function ref(mixed $value, string $label): string
    {
        $value = self::text($value, $label, 180);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\\/-]{0,179}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::ref($value, $label);
    }

    private static function globalId(mixed $value): string
    {
        if ($value !== 'global') {
            throw new InvalidArgumentException('global scope_id invalid.');
        }
        return 'global';
    }

    private static function time(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableTime(mixed $value, string $label): ?int
    {
        return $value === null ? null : self::time($value, $label);
    }
}

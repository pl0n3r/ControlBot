<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class InfrastructureObservation
{
    private const STATES = ['online', 'degraded', 'offline', 'maintenance', 'unknown'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const BACKUP_STATES = ['fresh', 'stale', 'unknown'];
    private const RESTORE_STATES = ['verified', 'failed', 'unknown'];
    private const COST_STATES = ['attributed', 'unknown'];
    private const RELEASE_STATES = ['current', 'drift', 'unknown'];

    public static function normalize(array $raw): array
    {
        InfrastructureProvider::assertFields($raw, [
            'version', 'resource_id', 'state', 'source_ref', 'observed_at', 'freshness',
            'backup_freshness', 'restore_verification', 'cost_attribution',
            'release_drift', 'incident_refs',
        ], 'InfrastructureObservation');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('InfrastructureObservation version invalid.');
        }

        return [
            'version' => 1,
            'resource_id' => InfrastructureProvider::normalizeId($raw['resource_id'], 'observation.resource_id'),
            'state' => InfrastructureProvider::normalizeEnum($raw['state'], self::STATES, 'observation.state'),
            'source_ref' => InfrastructureProvider::normalizeReference($raw['source_ref'], 'observation.source_ref'),
            'observed_at' => InfrastructureProvider::normalizeTimestamp($raw['observed_at'], 'observation.observed_at'),
            'freshness' => InfrastructureProvider::normalizeEnum($raw['freshness'], self::FRESHNESS, 'observation.freshness'),
            'backup_freshness' => self::simpleSignal(
                $raw['backup_freshness'],
                self::BACKUP_STATES,
                'backup_freshness',
            ),
            'restore_verification' => self::simpleSignal(
                $raw['restore_verification'],
                self::RESTORE_STATES,
                'restore_verification',
            ),
            'cost_attribution' => self::costSignal($raw['cost_attribution']),
            'release_drift' => self::releaseSignal($raw['release_drift']),
            'incident_refs' => self::refs($raw['incident_refs'], 'incident_ref'),
        ];
    }

    public static function effectiveState(?array $raw): string
    {
        if ($raw === null) {
            return 'unknown';
        }
        $observation = self::normalize($raw);
        if ($observation['freshness'] !== 'fresh') {
            return 'unknown';
        }
        return $observation['state'];
    }

    private static function simpleSignal(mixed $raw, array $states, string $label): array
    {
        InfrastructureProvider::assertFields($raw, ['state', 'source_ref', 'observed_at'], $label);
        $state = InfrastructureProvider::normalizeEnum($raw['state'], $states, $label . '.state');
        $source = self::nullableRef($raw['source_ref'], $label . '.source_ref');
        $observedAt = self::nullableTimestamp($raw['observed_at'], $label . '.observed_at');
        if ($state !== 'unknown' && ($source === null || $observedAt === null)) {
            throw new InvalidArgumentException($label . ' evidence required.');
        }
        if (($source === null) !== ($observedAt === null)) {
            throw new InvalidArgumentException($label . ' evidence incomplete.');
        }
        return ['state' => $state, 'source_ref' => $source, 'observed_at' => $observedAt];
    }

    private static function costSignal(mixed $raw): array
    {
        InfrastructureProvider::assertFields(
            $raw,
            ['state', 'cost_ref', 'source_ref', 'observed_at'],
            'cost_attribution',
        );
        $state = InfrastructureProvider::normalizeEnum(
            $raw['state'],
            self::COST_STATES,
            'cost_attribution.state',
        );
        $costRef = self::nullableControlRef($raw['cost_ref'], 'cost_attribution.cost_ref', 'controlbot:cost/');
        $source = self::nullableRef($raw['source_ref'], 'cost_attribution.source_ref');
        $observedAt = self::nullableTimestamp($raw['observed_at'], 'cost_attribution.observed_at');
        $hasEvidence = $costRef !== null && $source !== null && $observedAt !== null;
        if ($state === 'attributed' && !$hasEvidence) {
            throw new InvalidArgumentException('cost_attribution evidence required.');
        }
        if ($state === 'unknown' && ($costRef !== null || $source !== null || $observedAt !== null)) {
            throw new InvalidArgumentException('cost_attribution unknown must not imply evidence.');
        }
        return [
            'state' => $state,
            'cost_ref' => $costRef,
            'source_ref' => $source,
            'observed_at' => $observedAt,
        ];
    }

    private static function releaseSignal(mixed $raw): array
    {
        InfrastructureProvider::assertFields(
            $raw,
            ['state', 'expected_sha', 'observed_sha', 'source_ref', 'observed_at'],
            'release_drift',
        );
        $state = InfrastructureProvider::normalizeEnum(
            $raw['state'],
            self::RELEASE_STATES,
            'release_drift.state',
        );
        $expected = self::nullableSha($raw['expected_sha'], 'release_drift.expected_sha');
        $observed = self::nullableSha($raw['observed_sha'], 'release_drift.observed_sha');
        $source = self::nullableRef($raw['source_ref'], 'release_drift.source_ref');
        $observedAt = self::nullableTimestamp($raw['observed_at'], 'release_drift.observed_at');
        $complete = $expected !== null && $observed !== null && $source !== null && $observedAt !== null;

        if ($state === 'unknown') {
            if ($expected !== null || $observed !== null || $source !== null || $observedAt !== null) {
                throw new InvalidArgumentException('release_drift unknown must not imply evidence.');
            }
        } elseif (!$complete) {
            throw new InvalidArgumentException('release_drift evidence required.');
        } elseif (($state === 'current') !== ($expected === $observed)) {
            throw new InvalidArgumentException('release_drift state mismatch.');
        }

        return [
            'state' => $state,
            'expected_sha' => $expected,
            'observed_sha' => $observed,
            'source_ref' => $source,
            'observed_at' => $observedAt,
        ];
    }

    private static function refs(mixed $refs, string $label): array
    {
        if (!is_array($refs) || !array_is_list($refs) || count($refs) > 50) {
            throw new InvalidArgumentException($label . 's invalid.');
        }
        $out = [];
        foreach ($refs as $ref) {
            $ref = InfrastructureProvider::normalizeReference($ref, $label);
            if (isset($out[$ref])) {
                throw new InvalidArgumentException($label . ' duplicated.');
            }
            $out[$ref] = true;
        }
        $refs = array_keys($out);
        sort($refs);
        return $refs;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : InfrastructureProvider::normalizeReference($value, $label);
    }

    private static function nullableControlRef(mixed $value, string $label, string $prefix): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = InfrastructureProvider::normalizeControlRef($value, $label);
        if (!str_starts_with($value, $prefix)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableTimestamp(mixed $value, string $label): ?int
    {
        return $value === null ? null : InfrastructureProvider::normalizeTimestamp($value, $label);
    }

    private static function nullableSha(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
}

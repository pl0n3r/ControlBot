<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class RecoveryProfile
{
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const APPLICABILITY = ['required', 'not_applicable'];
    private const SOURCES = ['database', 'media', 'repository', 'configuration'];

    public static function normalize(array $raw): array
    {
        InfrastructureProvider::assertFields($raw, [
            'version', 'project_ref', 'manifest_ref', 'targets', 'retention',
            'sources', 'strategy', 'encryption_required',
            'restore_drill_cadence_hours', 'source_ref', 'observed_at', 'freshness',
        ], 'RecoveryProfile');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('RecoveryProfile version invalid.');
        }

        $freshness = InfrastructureProvider::normalizeEnum(
            $raw['freshness'], self::FRESHNESS, 'recovery.freshness'
        );
        $sourceRef = self::nullableRef($raw['source_ref'], 'recovery.source_ref');
        $observedAt = self::nullableTimestamp($raw['observed_at'], 'recovery.observed_at');
        if ($freshness === 'unknown' && ($sourceRef !== null || $observedAt !== null)) {
            throw new InvalidArgumentException('RecoveryProfile unknown must not imply provenance.');
        }
        if ($freshness !== 'unknown' && ($sourceRef === null || $observedAt === null)) {
            throw new InvalidArgumentException('RecoveryProfile provenance required.');
        }
        if (($raw['encryption_required'] ?? null) !== true) {
            throw new InvalidArgumentException('RecoveryProfile encryption must be required.');
        }

        return [
            'version' => 1,
            'project_ref' => self::prefixedRef(
                $raw['project_ref'], 'recovery.project_ref', 'controlbot:project/'
            ),
            'manifest_ref' => self::prefixedRef(
                $raw['manifest_ref'], 'recovery.manifest_ref', 'controlbot:recovery-manifest/'
            ),
            'targets' => self::targets($raw['targets']),
            'retention' => self::retention($raw['retention']),
            'sources' => self::sources($raw['sources']),
            'strategy' => self::strategy($raw['strategy']),
            'encryption_required' => true,
            'restore_drill_cadence_hours' => self::positiveInt(
                $raw['restore_drill_cadence_hours'], 8760, 'recovery.restore_drill_cadence_hours'
            ),
            'source_ref' => $sourceRef,
            'observed_at' => $observedAt,
            'freshness' => $freshness,
        ];
    }

    public static function effectiveStatus(?array $raw): string
    {
        if ($raw === null) {
            return 'unknown';
        }
        return self::normalize($raw)['freshness'] === 'fresh' ? 'configured' : 'unknown';
    }

    private static function targets(mixed $raw): array
    {
        InfrastructureProvider::assertFields($raw, ['rpo_minutes', 'rto_minutes'], 'RecoveryTargets');
        return [
            'rpo_minutes' => self::positiveInt(
                $raw['rpo_minutes'], 525600, 'recovery.targets.rpo_minutes'
            ),
            'rto_minutes' => self::positiveInt(
                $raw['rto_minutes'], 525600, 'recovery.targets.rto_minutes'
            ),
        ];
    }

    private static function retention(mixed $raw): array
    {
        $keys = ['hourly', 'daily', 'weekly', 'monthly'];
        InfrastructureProvider::assertFields($raw, $keys, 'RecoveryRetention');
        $result = [];
        foreach ($keys as $key) {
            $value = $raw[$key] ?? null;
            if (!is_int($value) || $value < 0 || $value > 10000) {
                throw new InvalidArgumentException("recovery.retention.$key invalid.");
            }
            $result[$key] = $value;
        }
        if (array_sum($result) < 1) {
            throw new InvalidArgumentException('RecoveryRetention requires at least one retained copy.');
        }
        return $result;
    }

    private static function sources(mixed $raw): array
    {
        InfrastructureProvider::assertFields($raw, self::SOURCES, 'RecoverySources');
        $result = [];
        foreach (self::SOURCES as $key) {
            $result[$key] = InfrastructureProvider::normalizeEnum(
                $raw[$key], self::APPLICABILITY, "recovery.sources.$key"
            );
        }
        if (!in_array('required', $result, true)) {
            throw new InvalidArgumentException('RecoverySources requires at least one required source.');
        }
        return $result;
    }

    private static function strategy(mixed $raw): array
    {
        $expected = [
            'copies_required' => 3,
            'media_types_required' => 2,
            'offsite_required' => true,
            'immutable_required' => true,
            'undetected_restore_failures_target' => 0,
        ];
        InfrastructureProvider::assertFields($raw, array_keys($expected), 'RecoveryStrategy');
        if ($raw !== $expected) {
            throw new InvalidArgumentException('RecoveryStrategy must express 3-2-1-1-0.');
        }
        return $expected;
    }

    private static function positiveInt(mixed $value, int $max, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidArgumentException("$label invalid.");
        }
        return $value;
    }

    private static function prefixedRef(mixed $value, string $label, string $prefix): string
    {
        $ref = InfrastructureProvider::normalizeControlRef($value, $label);
        if (!str_starts_with($ref, $prefix) || strlen($ref) <= strlen($prefix)) {
            throw new InvalidArgumentException("$label invalid.");
        }
        return $ref;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : InfrastructureProvider::normalizeReference($value, $label);
    }

    private static function nullableTimestamp(mixed $value, string $label): ?int
    {
        return $value === null ? null : InfrastructureProvider::normalizeTimestamp($value, $label);
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class RecoveryProfile
{
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const APPLICABILITY = ['required', 'not_applicable'];
    private const SOURCE_KEYS = ['database', 'media', 'repository', 'configuration'];

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
            $raw['freshness'],
            self::FRESHNESS,
            'recovery.freshness',
        );
        $sourceRef = self::nullableReference($raw['source_ref'], 'recovery.source_ref');
        $observedAt = self::nullableTimestamp($raw['observed_at'], 'recovery.observed_at');

        if ($freshness === 'unknown') {
            if ($sourceRef !== null || $observedAt !== null) {
                throw new InvalidArgumentException('RecoveryProfile unknown must not imply provenance.');
            }
        } elseif ($sourceRef === null || $observedAt === null) {
            throw new InvalidArgumentException('RecoveryProfile provenance required.');
        }

        if (($raw['encryption_required'] ?? null) !== true) {
            throw new InvalidArgumentException('RecoveryProfile encryption must be required.');
        }

        return [
            'version' => 1,
            'project_ref' => self::prefixedRef(
                $raw['project_ref'],
                'recovery.project_ref',
                'controlbot:project/',
            ),
            'manifest_ref' => self::prefixedRef(
                $raw['manifest_ref'],
                'recovery.manifest_ref',
                'controlbot:recovery-manifest/',
            ),
            'targets' => self::targets($raw['targets']),
            'retention' => self::retention($raw['retention']),
            'sources' => self::sources($raw['sources']),
            'strategy' => self::strategy($raw['strategy']),
            'encryption_required' => true,
            'restore_drill_cadence_hours' => self::boundedPositiveInt(
                $raw['restore_drill_cadence_hours'],
                8760,
                'recovery.restore_drill_cadence_hours',
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

        $profile = self::normalize($raw);
        return $profile['freshness'] === 'fresh' ? 'configured' : 'unknown';
    }

    private static function targets(mixed $raw): array
    {
        InfrastructureProvider::assertFields(
            $raw,
            ['rpo_minutes', 'rto_minutes'],
            'RecoveryTargets',
        );

        return [
            'rpo_minutes' => self::boundedPositiveInt(
                $raw['rpo_minutes'],
                525600,
                'recovery.targets.rpo_minutes',
            ),
            'rto_minutes' => self::boundedPositiveInt(
                $raw['rto_minutes'],
                525600,
                'recovery.targets.rto_minutes',
            ),
        ];
    }

    private static function retention(mixed $raw): array
    {
        InfrastructureProvider::assertFields(
            $raw,
            ['hourly', 'daily', 'weekly', 'monthly'],
            'RecoveryRetention',
        );

        $result = [];
        foreach (['hourly', 'daily', 'weekly', 'monthly'] as $key) {
            $value = $raw[$key] ?? null;
            if (!is_int($value) || $value < 0 || $value > 10000) {
                throw new InvalidArgumentException('recovery.retention.' . $key . ' invalid.');
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
        InfrastructureProvider::assertFields($raw, self::SOURCE_KEYS, 'RecoverySources');

        $result = [];
        $required = 0;
        foreach (self::SOURCE_KEYS as $key) {
            $value = InfrastructureProvider::normalizeEnum(
                $raw[$key],
                self::APPLICABILITY,
                'recovery.sources.' . $key,
            );
            $result[$key] = $value;
            if ($value === 'required') {
                $required++;
            }
        }

        if ($required < 1) {
            throw new InvalidArgumentException('RecoverySources requires at least one required source.');
        }

        return $result;
    }

    private static function strategy(mixed $raw): array
    {
        InfrastructureProvider::assertFields($raw, [
            'copies_required', 'media_types_required', 'offsite_required',
            'immutable_required', 'undetected_restore_failures_target',
        ], 'RecoveryStrategy');

        if (($raw['copies_required'] ?? null) !== 3
            || ($raw['media_types_required'] ?? null) !== 2
            || ($raw['offsite_required'] ?? null) !== true
            || ($raw['immutable_required'] ?? null) !== true
            || ($raw['undetected_restore_failures_target'] ?? null) !== 0) {
            throw new InvalidArgumentException('RecoveryStrategy must express 3-2-1-1-0.');
        }

        return [
            'copies_required' => 3,
            'media_types_required' => 2,
            'offsite_required' => true,
            'immutable_required' => true,
            'undetected_restore_failures_target' => 0,
        ];
    }

    private static function boundedPositiveInt(mixed $value, int $max, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function prefixedRef(mixed $value, string $label, string $prefix): string
    {
        $ref = InfrastructureProvider::normalizeControlRef($value, $label);
        if (!str_starts_with($ref, $prefix) || strlen($ref) <= strlen($prefix)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $ref;
    }

    private static function nullableReference(mixed $value, string $label): ?string
    {
        return $value === null ? null : InfrastructureProvider::normalizeReference($value, $label);
    }

    private static function nullableTimestamp(mixed $value, string $label): ?int
    {
        return $value === null ? null : InfrastructureProvider::normalizeTimestamp($value, $label);
    }
}

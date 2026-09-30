<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class DisasterRecoveryPolicy
{
    private const COMPONENTS = ['database', 'media', 'code', 'secrets'];
    private const EVIDENCE_STATES = ['healthy', 'degraded', 'unknown', 'blocked'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const STRATEGIES = [
        'database' => ['consistent_backup'],
        'media' => ['versioned_backup'],
        'code' => ['repository_mirror'],
        'secrets' => ['vault_reference_only'],
    ];

    public static function normalize(array $raw): array
    {
        self::assertFields($raw, [
            'version', 'project_id', 'rpo_seconds', 'rto_seconds',
            'retention', 'strategies', 'capabilities',
            'destinations', 'policy_ref', 'provenance_refs',
        ], 'DisasterRecoveryPolicy');
        self::assertNoSensitiveMaterial($raw);

        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('DisasterRecoveryPolicy version invalid.');
        }

        $projectId = self::projectId($raw['project_id'] ?? null);
        $destinations = self::destinations($raw['destinations'] ?? null);
        $provenanceRefs = self::provenanceRefs($raw['provenance_refs'] ?? null);

        return [
            'version' => 1,
            'project_id' => $projectId,
            'rpo_seconds' => self::positiveInt(
                $raw['rpo_seconds'] ?? null,
                31_536_000,
                'dr.rpo_seconds'
            ),
            'rto_seconds' => self::positiveInt(
                $raw['rto_seconds'] ?? null,
                31_536_000,
                'dr.rto_seconds'
            ),
            'retention' => self::retention($raw['retention'] ?? null),
            'strategies' => self::strategies($raw['strategies'] ?? null),
            'capabilities' => self::capabilities($raw['capabilities'] ?? null),
            'destinations' => $destinations,
            'policy_ref' => self::policyRef($raw['policy_ref'] ?? null, $projectId),
            'provenance_refs' => $provenanceRefs,
        ];
    }

    public static function evidenceStatus(?array $raw): string
    {
        if ($raw === null) {
            return 'unknown';
        }

        self::assertFields(
            $raw,
            ['state', 'freshness', 'complete'],
            'DisasterRecoveryEvidenceState'
        );

        $state = self::enum(
            $raw['state'] ?? null,
            self::EVIDENCE_STATES,
            'dr.evidence.state'
        );
        $freshness = self::enum(
            $raw['freshness'] ?? null,
            self::FRESHNESS,
            'dr.evidence.freshness'
        );
        $complete = $raw['complete'] ?? null;
        if (!is_bool($complete)) {
            throw new InvalidArgumentException('dr.evidence.complete invalid.');
        }

        if ($state === 'blocked') {
            return 'blocked';
        }
        if ($freshness === 'unknown') {
            return 'unknown';
        }
        if ($freshness === 'stale' || !$complete) {
            return 'degraded';
        }
        return $state;
    }

    public static function fingerprint(array $raw): string
    {
        $normalized = self::normalize($raw);
        $canonical = self::canonicalize($normalized);
        return hash(
            'sha256',
            json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )
        );
    }

    private static function retention(mixed $raw): array
    {
        $keys = ['recent', 'daily', 'weekly', 'monthly'];
        self::assertFields($raw, $keys, 'DisasterRecoveryRetention');

        $result = [];
        foreach ($keys as $key) {
            $value = $raw[$key] ?? null;
            if (!is_int($value) || $value < 0 || $value > 100_000) {
                throw new InvalidArgumentException("dr.retention.$key invalid.");
            }
            $result[$key] = $value;
        }

        if (array_sum($result) < 1) {
            throw new InvalidArgumentException(
                'DisasterRecoveryRetention requires at least one retained copy.'
            );
        }
        return $result;
    }

    private static function strategies(mixed $raw): array
    {
        self::assertFields($raw, self::COMPONENTS, 'DisasterRecoveryStrategies');

        $result = [];
        foreach (self::COMPONENTS as $component) {
            $result[$component] = self::enum(
                $raw[$component] ?? null,
                self::STRATEGIES[$component],
                "dr.strategies.$component"
            );
        }
        return $result;
    }

    private static function capabilities(mixed $raw): array
    {
        $keys = [
            'offsite',
            'versioned_or_immutable',
            'checksum_required',
            'freshness_required',
            'restore_drill_required',
        ];
        self::assertFields($raw, $keys, 'DisasterRecoveryCapabilities');

        $result = [];
        foreach ($keys as $key) {
            $value = $raw[$key] ?? null;
            if (!is_bool($value)) {
                throw new InvalidArgumentException("dr.capabilities.$key invalid.");
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private static function destinations(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new InvalidArgumentException('dr.destinations invalid.');
        }

        $result = [];
        $seen = [];
        foreach ($raw as $row) {
            self::assertFields($row, ['provider', 'role'], 'DisasterRecoveryDestination');
            $provider = self::slug($row['provider'] ?? null, 'dr.destination.provider');
            $role = self::slug($row['role'] ?? null, 'dr.destination.role');

            if ($provider === 'google_drive' && $role !== 'offsite_encrypted_copy') {
                throw new InvalidArgumentException(
                    'Google Drive is only allowed as offsite_encrypted_copy.'
                );
            }
            if (
                $provider === 'icloud'
                && in_array(
                    $role,
                    ['primary_runtime_storage', 'server_automation_dependency'],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'iCloud cannot be primary runtime storage or server automation dependency.'
                );
            }

            $key = $provider . '|' . $role;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('dr.destination duplicated.');
            }
            $seen[$key] = true;
            $result[] = ['provider' => $provider, 'role' => $role];
        }

        usort(
            $result,
            static fn(array $left, array $right): int =>
                ($left['provider'] . '|' . $left['role'])
                <=> ($right['provider'] . '|' . $right['role'])
        );
        return $result;
    }

    private static function provenanceRefs(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new InvalidArgumentException('dr.provenance_refs invalid.');
        }

        $result = [];
        $seen = [];
        foreach ($raw as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 500) {
                throw new InvalidArgumentException('dr.provenance_ref invalid.');
            }
            if (preg_match('/\s/D', $value) === 1) {
                throw new InvalidArgumentException('dr.provenance_ref invalid.');
            }
            if (isset($seen[$value])) {
                throw new InvalidArgumentException('dr.provenance_ref duplicated.');
            }
            $seen[$value] = true;
            $result[] = $value;
        }
        sort($result, SORT_STRING);
        return $result;
    }

    private static function policyRef(mixed $value, string $projectId): string
    {
        if (
            !is_string($value)
            || preg_match(
                '/^controlbot:dr-policy\/[a-z][a-z0-9-]{1,63}\/[a-z0-9._-]{1,80}$/D',
                $value
            ) !== 1
        ) {
            throw new InvalidArgumentException('dr.policy_ref invalid.');
        }

        $prefix = 'controlbot:dr-policy/' . $projectId . '/';
        if (!str_starts_with($value, $prefix)) {
            throw new InvalidArgumentException('dr.policy_ref project mismatch.');
        }
        return $value;
    }

    private static function projectId(mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('dr.project_id invalid.');
        }
        return $value;
    }

    private static function positiveInt(mixed $value, int $max, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidArgumentException("$label invalid.");
        }
        return $value;
    }

    private static function slug(mixed $value, string $label): string
    {
        if (
            !is_string($value)
            || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException("$label invalid.");
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("$label invalid.");
        }
        return $value;
    }

    private static function assertFields(
        mixed $raw,
        array $expected,
        string $label
    ): void {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException("$label invalid.");
        }

        $actual = array_keys($raw);
        sort($actual, SORT_STRING);
        $required = $expected;
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw new InvalidArgumentException("$label fields invalid.");
        }
    }

    private static function assertNoSensitiveMaterial(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                self::assertNoSensitiveMaterial($child);
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        if (
            preg_match(
                '/(?:password|passwd|token|secret|api[_-]?key|private[_-]?key)\s*[:=]/i',
                $value
            ) === 1
            || str_contains($value, '-----BEGIN PRIVATE KEY-----')
            || preg_match('/\bsk-[A-Za-z0-9_-]{12,}\b/', $value) === 1
        ) {
            throw new InvalidArgumentException('Sensitive recovery material is not allowed.');
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }

        $result = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $result[$key] = self::canonicalize($value[$key]);
        }
        return $result;
    }
}

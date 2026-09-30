<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class DisasterRecoveryPolicy
{
    private const COMPONENTS = ['database', 'media', 'code', 'secrets'];
    private const STRATEGIES = [
        'database' => 'consistent_backup',
        'media' => 'versioned_backup',
        'code' => 'repository_mirror',
        'secrets' => 'vault_reference_only',
    ];
    private const CAPS = [
        'offsite', 'versioned_or_immutable', 'checksum_required',
        'freshness_required', 'restore_drill_required',
    ];
    private const SENSITIVE = '/(?:password|passwd|token|secret|api[_-]?key|private[_-]?key)\s*[:=]/i';

    public static function normalize(array $raw): array
    {
        self::fields($raw, [
            'version', 'project_id', 'rpo_seconds', 'rto_seconds', 'retention',
            'strategies', 'capabilities', 'destinations', 'policy_ref', 'provenance_refs',
        ], 'policy');
        self::safe($raw);
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('dr.version invalid.');
        }
        $project = self::slug($raw['project_id'] ?? null, 'project_id', false);
        $policyRef = self::ref($raw['policy_ref'] ?? null, 'policy_ref');
        if (!str_starts_with($policyRef, "controlbot:dr-policy/$project/")) {
            throw new InvalidArgumentException('dr.policy_ref project mismatch.');
        }
        return [
            'version' => 1,
            'project_id' => $project,
            'rpo_seconds' => self::positive($raw['rpo_seconds'] ?? null, 'rpo_seconds'),
            'rto_seconds' => self::positive($raw['rto_seconds'] ?? null, 'rto_seconds'),
            'retention' => self::retention($raw['retention'] ?? null),
            'strategies' => self::strategies($raw['strategies'] ?? null),
            'capabilities' => self::capabilities($raw['capabilities'] ?? null),
            'destinations' => self::destinations($raw['destinations'] ?? null),
            'policy_ref' => $policyRef,
            'provenance_refs' => self::refs($raw['provenance_refs'] ?? null),
        ];
    }

    public static function evidenceStatus(?array $raw): string
    {
        if ($raw === null) {
            return 'unknown';
        }
        self::fields($raw, ['state', 'freshness', 'complete'], 'evidence');
        $state = self::oneOf($raw['state'] ?? null, ['healthy', 'degraded', 'unknown', 'blocked'], 'state');
        $freshness = self::oneOf($raw['freshness'] ?? null, ['fresh', 'stale', 'unknown'], 'freshness');
        if (!is_bool($raw['complete'] ?? null)) {
            throw new InvalidArgumentException('dr.evidence.complete invalid.');
        }
        if ($state === 'blocked') {
            return 'blocked';
        }
        if ($freshness === 'unknown') {
            return 'unknown';
        }
        return $freshness === 'stale' || !$raw['complete'] ? 'degraded' : $state;
    }

    public static function fingerprint(array $raw): string
    {
        return hash('sha256', json_encode(self::normalize($raw), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function retention(mixed $raw): array
    {
        $keys = ['recent', 'daily', 'weekly', 'monthly'];
        self::fields($raw, $keys, 'retention');
        $out = [];
        foreach ($keys as $key) {
            $value = $raw[$key] ?? null;
            if (!is_int($value) || $value < 0 || $value > 100_000) {
                throw new InvalidArgumentException("dr.retention.$key invalid.");
            }
            $out[$key] = $value;
        }
        if (array_sum($out) < 1) {
            throw new InvalidArgumentException('dr.retention empty.');
        }
        return $out;
    }

    private static function strategies(mixed $raw): array
    {
        self::fields($raw, self::COMPONENTS, 'strategies');
        $out = [];
        foreach (self::COMPONENTS as $component) {
            if (($raw[$component] ?? null) !== self::STRATEGIES[$component]) {
                throw new InvalidArgumentException("dr.strategies.$component invalid.");
            }
            $out[$component] = self::STRATEGIES[$component];
        }
        return $out;
    }

    private static function capabilities(mixed $raw): array
    {
        self::fields($raw, self::CAPS, 'capabilities');
        $out = [];
        foreach (self::CAPS as $key) {
            if (!is_bool($raw[$key] ?? null)) {
                throw new InvalidArgumentException("dr.capabilities.$key invalid.");
            }
            $out[$key] = $raw[$key];
        }
        return $out;
    }

    private static function destinations(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new InvalidArgumentException('dr.destinations invalid.');
        }
        $out = $seen = [];
        foreach ($raw as $row) {
            self::fields($row, ['provider', 'role'], 'destination');
            $provider = self::slug($row['provider'] ?? null, 'provider');
            $role = self::slug($row['role'] ?? null, 'role');
            if ($provider === 'google_drive' && $role !== 'offsite_encrypted_copy') {
                throw new InvalidArgumentException('dr.google_drive role invalid.');
            }
            if ($provider === 'icloud' && in_array($role, ['primary_runtime_storage', 'server_automation_dependency'], true)) {
                throw new InvalidArgumentException('dr.icloud role invalid.');
            }
            $key = "$provider|$role";
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('dr.destination duplicated.');
            }
            $seen[$key] = true;
            $out[] = ['provider' => $provider, 'role' => $role];
        }
        usort($out, static fn(array $a, array $b): int => ($a['provider'] . '|' . $a['role']) <=> ($b['provider'] . '|' . $b['role']));
        return $out;
    }

    private static function refs(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new InvalidArgumentException('dr.provenance_refs invalid.');
        }
        $out = $seen = [];
        foreach ($raw as $value) {
            $ref = self::ref($value, 'provenance_ref');
            if (isset($seen[$ref])) {
                throw new InvalidArgumentException('dr.provenance_ref duplicated.');
            }
            $seen[$ref] = true;
            $out[] = $ref;
        }
        sort($out, SORT_STRING);
        return $out;
    }

    private static function fields(mixed $raw, array $expected, string $label): void
    {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException("dr.$label invalid.");
        }
        $actual = array_keys($raw);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException("dr.$label fields invalid.");
        }
    }

    private static function positive(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > 31_536_000) {
            throw new InvalidArgumentException("dr.$label invalid.");
        }
        return $value;
    }

    private static function slug(mixed $value, string $label, bool $underscore = true): string
    {
        $middle = $underscore ? '[a-z0-9_]' : '[a-z0-9-]';
        if (!is_string($value) || preg_match("/^[a-z]$middle{1,63}$/D", $value) !== 1) {
            throw new InvalidArgumentException("dr.$label invalid.");
        }
        return $value;
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value) || strlen($value) > 180 || preg_match('#^controlbot:[a-z0-9][a-z0-9._:/-]+$#D', $value) !== 1) {
            throw new InvalidArgumentException("dr.$label invalid.");
        }
        self::safe($value);
        return $value;
    }

    private static function oneOf(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("dr.evidence.$label invalid.");
        }
        return $value;
    }

    private static function safe(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                self::safe($child);
            }
        } elseif (is_string($value) && (preg_match(self::SENSITIVE, $value) === 1 || str_contains($value, '-----BEGIN PRIVATE KEY-----'))) {
            throw new InvalidArgumentException('Sensitive recovery material is not allowed.');
        }
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class InfrastructureResource
{
    private const KINDS = [
        'environment', 'service', 'database', 'storage', 'dns',
        'certificate', 'backup', 'network',
    ];
    private const SECRET_PATTERN = '/(?i)(password|passwd|secret|token|api[_-]?key|private[_-]?key|dsn\s*[:=]|bearer\s+)/';

    public static function normalize(array $raw): array
    {
        self::fields($raw, [
            'version', 'resource_id', 'kind', 'provider_id', 'account_id',
            'project_ref', 'venture_ref', 'environment_ref', 'service_ref',
            'parent_ref', 'release_evidence', 'cost_ref', 'backup_refs',
            'source_ref', 'observed_at',
        ], 'InfrastructureResource');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('InfrastructureResource version invalid.');
        }

        return [
            'version' => 1,
            'resource_id' => self::id($raw['resource_id'], 'resource_id'),
            'kind' => self::enum($raw['kind'], self::KINDS, 'resource.kind'),
            'provider_id' => self::id($raw['provider_id'], 'resource.provider_id'),
            'account_id' => self::id($raw['account_id'], 'resource.account_id'),
            'project_ref' => self::nullableRef($raw['project_ref'], 'resource.project_ref', 'controlbot:project/'),
            'venture_ref' => self::nullableRef($raw['venture_ref'], 'resource.venture_ref', 'controlbot:venture/'),
            'environment_ref' => self::nullableRef($raw['environment_ref'], 'resource.environment_ref', 'controlbot:environment/'),
            'service_ref' => self::nullableRef($raw['service_ref'], 'resource.service_ref', 'controlbot:resource/'),
            'parent_ref' => self::nullableRef($raw['parent_ref'], 'resource.parent_ref', 'controlbot:resource/'),
            'release_evidence' => self::releaseEvidence($raw['release_evidence']),
            'cost_ref' => self::nullableRef($raw['cost_ref'], 'resource.cost_ref', 'controlbot:'),
            'backup_refs' => self::backupRefs($raw['backup_refs']),
            'source_ref' => self::reference($raw['source_ref'], 'resource.source_ref'),
            'observed_at' => self::timestamp($raw['observed_at'], 'resource.observed_at'),
        ];
    }

    public static function normalizeInventory(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) > 1000) {
            throw new InvalidArgumentException('resources invalid.');
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('resource invalid.');
            }
            $resource = self::normalize($row);
            $id = $resource['resource_id'];
            if (isset($out[$id])) {
                throw new InvalidArgumentException('InfrastructureResource duplicated.');
            }
            $out[$id] = $resource;
        }
        ksort($out);
        return array_values($out);
    }

    private static function releaseEvidence(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        self::fields($raw, ['version', 'sha', 'source_ref', 'observed_at'], 'ReleaseEvidence');
        if (($raw['version'] ?? null) !== 1
            || !is_string($raw['sha'])
            || preg_match('/^[0-9a-f]{40}$/D', $raw['sha']) !== 1) {
            throw new InvalidArgumentException('ReleaseEvidence invalid.');
        }
        return [
            'version' => 1,
            'sha' => $raw['sha'],
            'source_ref' => self::reference($raw['source_ref'], 'release.source_ref'),
            'observed_at' => self::timestamp($raw['observed_at'], 'release.observed_at'),
        ];
    }

    private static function backupRefs(mixed $refs): array
    {
        if (!is_array($refs) || !array_is_list($refs) || count($refs) > 50) {
            throw new InvalidArgumentException('backup_refs invalid.');
        }
        $out = [];
        foreach ($refs as $ref) {
            $ref = self::reference($ref, 'backup_ref');
            if (isset($out[$ref])) {
                throw new InvalidArgumentException('backup_ref duplicated.');
            }
            $out[$ref] = true;
        }
        $refs = array_keys($out);
        sort($refs);
        return $refs;
    }

    private static function nullableRef(mixed $value, string $label, string $prefix): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = self::reference($value, $label);
        if (!str_starts_with($value, $prefix)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function reference(mixed $value, string $label): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 240 || preg_match(self::SECRET_PATTERN, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        if (str_starts_with($value, 'controlbot:')) {
            if (preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\#@-]*$#D', $value) !== 1) {
                throw new InvalidArgumentException($label . ' invalid.');
            }
            return $value;
        }
        if (preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/(?:issues|pull)/[1-9][0-9]*)?$#D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value)
            || strlen($value) > 64
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1
            || preg_match(self::SECRET_PATTERN, $value) === 1) {
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

    private static function timestamp(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
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

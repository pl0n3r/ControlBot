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
    public static function normalize(array $raw): array
    {
        InfrastructureProvider::assertFields($raw, [
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
            'resource_id' => InfrastructureProvider::normalizeId($raw['resource_id'], 'resource_id'),
            'kind' => InfrastructureProvider::normalizeEnum($raw['kind'], self::KINDS, 'resource.kind'),
            'provider_id' => InfrastructureProvider::normalizeId($raw['provider_id'], 'resource.provider_id'),
            'account_id' => InfrastructureProvider::normalizeId($raw['account_id'], 'resource.account_id'),
            'project_ref' => self::nullableRef($raw['project_ref'], 'resource.project_ref', 'controlbot:project/'),
            'venture_ref' => self::nullableRef($raw['venture_ref'], 'resource.venture_ref', 'controlbot:venture/'),
            'environment_ref' => self::nullableRef($raw['environment_ref'], 'resource.environment_ref', 'controlbot:environment/'),
            'service_ref' => self::nullableRef($raw['service_ref'], 'resource.service_ref', 'controlbot:resource/'),
            'parent_ref' => self::nullableRef($raw['parent_ref'], 'resource.parent_ref', 'controlbot:resource/'),
            'release_evidence' => self::releaseEvidence($raw['release_evidence']),
            'cost_ref' => self::nullableRef($raw['cost_ref'], 'resource.cost_ref', 'controlbot:'),
            'backup_refs' => self::backupRefs($raw['backup_refs']),
            'source_ref' => InfrastructureProvider::normalizeReference($raw['source_ref'], 'resource.source_ref'),
            'observed_at' => InfrastructureProvider::normalizeTimestamp($raw['observed_at'], 'resource.observed_at'),
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
        InfrastructureProvider::assertFields($raw, ['version', 'sha', 'source_ref', 'observed_at'], 'ReleaseEvidence');
        if (($raw['version'] ?? null) !== 1
            || !is_string($raw['sha'])
            || preg_match('/^[0-9a-f]{40}$/D', $raw['sha']) !== 1) {
            throw new InvalidArgumentException('ReleaseEvidence invalid.');
        }
        return [
            'version' => 1,
            'sha' => $raw['sha'],
            'source_ref' => InfrastructureProvider::normalizeReference($raw['source_ref'], 'release.source_ref'),
            'observed_at' => InfrastructureProvider::normalizeTimestamp($raw['observed_at'], 'release.observed_at'),
        ];
    }

    private static function backupRefs(mixed $refs): array
    {
        if (!is_array($refs) || !array_is_list($refs) || count($refs) > 50) {
            throw new InvalidArgumentException('backup_refs invalid.');
        }
        $out = [];
        foreach ($refs as $ref) {
            $ref = InfrastructureProvider::normalizeReference($ref, 'backup_ref');
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
        $value = InfrastructureProvider::normalizeReference($value, $label);
        if (!str_starts_with($value, $prefix)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

}

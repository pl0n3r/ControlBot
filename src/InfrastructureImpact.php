<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class InfrastructureImpact
{
    public static function build(array $resources, array $bindings): array
    {
        $resources = InfrastructureResource::normalizeInventory($resources);
        $bindings = self::bindings($bindings);

        $byRef = [];
        $environments = [];
        foreach ($resources as $resource) {
            $byRef['controlbot:resource/' . $resource['resource_id']] = $resource;
            if ($resource['kind'] === 'environment') {
                $sourceRef = $resource['source_ref'];
                if (isset($environments[$sourceRef])) {
                    throw new InvalidArgumentException('resource.environment_ref ambiguous.');
                }
                $environments[$sourceRef] = $resource;
            }
        }
        foreach ($resources as $resource) {
            self::validateResourceLinks($resource, $byRef, $environments);
        }

        $forward = [];
        $reverse = [];
        foreach ($resources as $resource) {
            $resourceRef = 'controlbot:resource/' . $resource['resource_id'];
            $capabilities = [];
            foreach ($bindings as $binding) {
                if (
                    $resource['project_ref'] !== null
                    && $resource['venture_ref'] !== null
                    && $binding['project_ref'] === $resource['project_ref']
                    && $binding['venture_ref'] === $resource['venture_ref']
                ) {
                    $capabilities[] = $binding['capability_ref'];
                }
            }
            sort($capabilities);

            $forward[$resource['resource_id']] = [
                'resource_ref' => $resourceRef,
                'service_ref' => $resource['service_ref'],
                'environment_ref' => $resource['environment_ref'],
                'project_ref' => $resource['project_ref'],
                'venture_ref' => $resource['venture_ref'],
                'capability_refs' => array_values(array_unique($capabilities)),
            ];

            if ($resource['venture_ref'] !== null) {
                $reverse[$resource['venture_ref']] ??= [];
                $reverse[$resource['venture_ref']][] = $resourceRef;
            }
        }

        ksort($forward);
        ksort($reverse);
        foreach ($reverse as &$resourceRefs) {
            sort($resourceRefs);
        }
        unset($resourceRefs);

        return [
            'version' => 1,
            'resource_impact' => $forward,
            'venture_resources' => $reverse,
        ];
    }

    public static function impactForResource(
        array $resources,
        array $bindings,
        string $resourceId,
    ): array {
        $graph = self::build($resources, $bindings);
        $resourceId = InfrastructureProvider::normalizeId($resourceId, 'resource_id');
        $impact = $graph['resource_impact'][$resourceId] ?? null;
        if (!is_array($impact)) {
            throw new InvalidArgumentException('Resource impact unknown.');
        }
        return $impact;
    }

    public static function resourcesForVenture(
        array $resources,
        array $bindings,
        string $ventureRef,
    ): array {
        $graph = self::build($resources, $bindings);
        $ventureRef = InfrastructureProvider::normalizeControlRef($ventureRef, 'venture_ref');
        if (!str_starts_with($ventureRef, 'controlbot:venture/')) {
            throw new InvalidArgumentException('venture_ref invalid.');
        }
        return $graph['venture_resources'][$ventureRef] ?? [];
    }

    private static function validateResourceLinks(
        array $resource,
        array $byRef,
        array $environments,
    ): void {
        if ($resource['service_ref'] !== null) {
            $service = $byRef[$resource['service_ref']] ?? null;
            if (!is_array($service) || $service['kind'] !== 'service') {
                throw new InvalidArgumentException('resource.service_ref unresolved.');
            }
            self::requireSameScope(
                $resource,
                $service,
                ['project_ref', 'venture_ref', 'environment_ref'],
                'resource.service_ref cross-scope.',
            );
        }
        if ($resource['parent_ref'] !== null) {
            $parent = $byRef[$resource['parent_ref']] ?? null;
            if (!is_array($parent)) {
                throw new InvalidArgumentException('resource.parent_ref unresolved.');
            }
            self::requireSameScope(
                $resource,
                $parent,
                ['project_ref', 'venture_ref'],
                'resource.parent_ref cross-scope.',
            );
        }
        if ($resource['environment_ref'] !== null) {
            $environment = $environments[$resource['environment_ref']] ?? null;
            if (!is_array($environment)) {
                throw new InvalidArgumentException('resource.environment_ref unresolved.');
            }
            self::requireSameScope(
                $resource,
                $environment,
                ['project_ref', 'venture_ref'],
                'resource.environment_ref cross-scope.',
            );
        }
    }

    private static function requireSameScope(
        array $resource,
        array $target,
        array $fields,
        string $message,
    ): void {
        foreach ($fields as $field) {
            if ($resource[$field] !== $target[$field]) {
                throw new InvalidArgumentException($message);
            }
        }
    }

    private static function bindings(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 500) {
            throw new InvalidArgumentException('capability bindings invalid.');
        }
        $out = [];
        foreach ($rows as $row) {
            InfrastructureProvider::assertFields(
                $row,
                ['capability_ref', 'project_ref', 'venture_ref', 'source_ref', 'observed_at'],
                'CapabilityBinding',
            );
            $capability = self::prefixedRef($row['capability_ref'], 'capability_ref', 'controlbot:capability/');
            $project = self::prefixedRef($row['project_ref'], 'project_ref', 'controlbot:project/');
            $venture = self::prefixedRef($row['venture_ref'], 'venture_ref', 'controlbot:venture/');
            $source = InfrastructureProvider::normalizeReference($row['source_ref'], 'binding.source_ref');
            $observedAt = InfrastructureProvider::normalizeTimestamp($row['observed_at'], 'binding.observed_at');
            $key = $venture . '|' . $project . '|' . $capability;
            if (isset($out[$key])) {
                throw new InvalidArgumentException('CapabilityBinding duplicated.');
            }
            $out[$key] = [
                'capability_ref' => $capability,
                'project_ref' => $project,
                'venture_ref' => $venture,
                'source_ref' => $source,
                'observed_at' => $observedAt,
            ];
        }
        ksort($out);
        return array_values($out);
    }

    private static function prefixedRef(mixed $value, string $label, string $prefix): string
    {
        $value = InfrastructureProvider::normalizeControlRef($value, $label);
        if (!str_starts_with($value, $prefix)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

}

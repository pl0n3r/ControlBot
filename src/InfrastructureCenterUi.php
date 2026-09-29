<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class InfrastructureCenterUi
{
    /** Build a read-only view-model from canonical infrastructure contracts. */
    public static function project(
        array $resources,
        array $observations,
        array $bindings,
    ): array {
        if (func_num_args() !== 3) {
            throw new InvalidArgumentException('Infrastructure UI action input is not supported.');
        }

        $resources = InfrastructureResource::normalizeInventory($resources);
        $graph = InfrastructureImpact::build($resources, $bindings);
        $resourceById = [];
        foreach ($resources as $resource) {
            $resourceById[$resource['resource_id']] = $resource;
        }
        $observed = self::observations($observations, $resourceById);

        $rows = [];
        foreach ($resources as $resource) {
            $id = $resource['resource_id'];
            $observation = $observed[$id] ?? null;
            $rows[] = [
                'resource_id' => $id,
                'kind' => $resource['kind'],
                'project_ref' => $resource['project_ref'],
                'venture_ref' => $resource['venture_ref'],
                'environment_ref' => $resource['environment_ref'],
                'state' => self::state($observation),
                'signals' => self::signals($observation),
                'impact' => $graph['resource_impact'][$id],
                'actions' => [],
            ];
        }

        return [
            'version' => 1,
            'execution' => false,
            'resources' => $rows,
            'drilldown' => self::drilldown($resources, $graph),
        ];
    }

    private static function observations(array $rows, array $resources): array
    {
        if (!array_is_list($rows) || count($rows) > 1000) {
            throw new InvalidArgumentException('Infrastructure UI observations invalid.');
        }

        $out = [];
        foreach ($rows as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Infrastructure UI observation invalid.');
            }
            $row = InfrastructureObservation::normalize($raw);
            $id = $row['resource_id'];
            if (!isset($resources[$id]) || isset($out[$id])) {
                throw new InvalidArgumentException('Infrastructure UI observation join invalid.');
            }
            $out[$id] = $row;
        }
        return $out;
    }

    private static function state(?array $row): array
    {
        if ($row === null) {
            return [
                'observed_state' => 'unknown',
                'effective_state' => 'unknown',
                'freshness' => 'unknown',
                'source_ref' => null,
                'observed_at' => null,
            ];
        }
        return [
            'observed_state' => $row['state'],
            'effective_state' => InfrastructureObservation::effectiveState($row),
            'freshness' => $row['freshness'],
            'source_ref' => $row['source_ref'],
            'observed_at' => $row['observed_at'],
        ];
    }

    private static function signals(?array $row): array
    {
        if ($row !== null) {
            return [
                'backup_freshness' => $row['backup_freshness'],
                'restore_verification' => $row['restore_verification'],
                'cost_attribution' => $row['cost_attribution'],
                'release_drift' => $row['release_drift'],
                'incident_refs' => $row['incident_refs'],
            ];
        }

        $unknown = ['state' => 'unknown', 'source_ref' => null, 'observed_at' => null];
        return [
            'backup_freshness' => $unknown,
            'restore_verification' => $unknown,
            'cost_attribution' => $unknown + ['cost_ref' => null],
            'release_drift' => $unknown + ['expected_sha' => null, 'observed_sha' => null],
            'incident_refs' => [],
        ];
    }

    private static function drilldown(array $resources, array $graph): array
    {
        $scopes = [];
        foreach ($resources as $resource) {
            $venture = $resource['venture_ref'];
            $project = $resource['project_ref'];
            if ($venture === null || $project === null) {
                continue;
            }
            $environment = $resource['environment_ref'] ?? '_direct';
            $bucket =& $scopes[$venture][$project][$environment];
            $bucket[] = 'controlbot:resource/' . $resource['resource_id'];
            sort($bucket);
            unset($bucket);
        }

        return [
            'scopes' => $scopes,
            'venture_resources' => $graph['venture_resources'],
        ];
    }
}

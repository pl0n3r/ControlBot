<?php
declare(strict_types=1);

namespace ControlBot\Readiness;

/** Offline-only evidence projection of ControlBot #750; never authorizes go-live. */
final class DefinitionOfDoneTracker
{
    private const MAX_EVIDENCE_AGE = 300;
    private const GROUPS = [
        'product_v1' => [
            'project_business_os', 'owner_views', 'agent_runtime_scheduler',
            'github_control_plane', 'incidents_observability',
            'security_secrets_traceability', 'factoryrunner_transport',
            'autofactory_bridge_readiness',
        ],
        'live' => [
            'deploy_auth_625', 'production_authority_45', 'recovery_182',
            'privacy_legal_749', 'kill_rollback_health_smoke',
            'real_data_explicit_gate', 'owner_go_live_decision',
        ],
        'terminal_acceptance' => [
            'roadmap_macro_coverage', 'observable_authenticated_deploy',
            'runner_autofactory_dependencies', 'fresh_recovery_security_rollback',
            'p0_p1_tracked', 'separate_owner_go_live_approval',
        ],
    ];

    /** Stable identifiers derived from #750; not arbitrary caller-provided labels. */
    public static function criteria(): array
    {
        return self::GROUPS;
    }

    /** @return array<string, mixed> Only fixed identifiers, enums and synthetic-only metadata. */
    public static function evaluate(array $input, int $now): array
    {
        $results = [];
        foreach (self::GROUPS as $group => $ids) {
            foreach ($ids as $id) {
                $results[$id] = ['id' => $id, 'group' => $group, 'state' => 'UNKNOWN', 'reason' => 'missing_evidence'];
            }
        }
        if (!self::validEnvelope($input, $now)) {
            return self::invalidResults($results);
        }
        $seen = [];
        foreach ($input['criteria'] as $row) {
            if (!self::validRow($row, $results, $seen)) {
                return self::invalidResults($results);
            }
            $id = $row['id'];
            $seen[$id] = true;
            if ($row['outcome'] === 'unknown') {
                $results[$id]['reason'] = 'unverified';
                continue;
            }
            if ($row['observed_at'] > $now || $now - $row['observed_at'] > self::MAX_EVIDENCE_AGE) {
                $results[$id]['reason'] = 'stale_or_future_evidence';
                continue;
            }
            $results[$id]['state'] = $row['outcome'] === 'pass' ? 'DONE' : 'NOT_DONE';
            $results[$id]['reason'] = 'synthetic_evidence_only';
        }
        return self::summarize($results, false);
    }

    private static function validEnvelope(array $input, int $now): bool
    {
        if (!self::exactKeys($input, ['version', 'source', 'criteria'])) {
            return false;
        }
        return $input['version'] === 1
            && $input['source'] === 'fixture'
            && is_int($now) && $now > 0
            && is_array($input['criteria'])
            && array_is_list($input['criteria'])
            && count($input['criteria']) <= count(self::allIds());
    }

    private static function validRow(mixed $row, array $results, array $seen): bool
    {
        if (!is_array($row) || !self::exactKeys($row, ['id', 'outcome', 'evidence_ref', 'observed_at'])) {
            return false;
        }
        $id = $row['id'];
        if (!is_string($id) || !array_key_exists($id, $results) || isset($seen[$id])) {
            return false;
        }
        if (!in_array($row['outcome'], ['pass', 'fail', 'unknown'], true)) {
            return false;
        }
        if ($row['outcome'] === 'unknown') {
            return $row['evidence_ref'] === null && $row['observed_at'] === null;
        }
        return is_string($row['evidence_ref'])
            && hash_equals('synthetic:cb750/' . $id, $row['evidence_ref'])
            && is_int($row['observed_at'])
            && $row['observed_at'] > 0;
    }

    private static function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    private static function allIds(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    private static function invalidResults(array $results): array
    {
        foreach ($results as &$row) {
            $row['state'] = 'UNKNOWN';
            $row['reason'] = 'invalid_or_ambiguous_input';
        }
        unset($row);
        return self::summarize($results, true);
    }

    private static function summarize(array $results, bool $invalid): array
    {
        $states = array_column(array_values($results), 'state');
        $total = $invalid || in_array('UNKNOWN', $states, true) ? 'UNKNOWN' : 'DONE';
        if (!$invalid && in_array('NOT_DONE', $states, true)) {
            $total = 'NOT_DONE';
        }
        return [
            'version' => 1,
            'source' => 'synthetic_only',
            'overall' => $total,
            'criteria' => array_values($results),
            'can_go_live' => false,
            'execution' => false,
            'real_world_evidence' => false,
        ];
    }
}

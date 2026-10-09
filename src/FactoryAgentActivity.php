<?php
declare(strict_types=1);
namespace ControlBot\Business;
use InvalidArgumentException;
/** Read-only GitHub observations. An updated issue/PR is not an agent heartbeat. */
final class FactoryAgentActivity
{
    private const REPOS = ['Factory', 'Condor', 'GrindFlow', 'brvtal', 'ControlBot', 'AutoFactory', 'FactoryRunner']; private const MAX_ITEMS = 500;
    private const FRESH = ['current', 'stale', 'unknown']; private const ISSUE_STATES = ['available', 'reserved', 'in_review', 'blocked', 'planned', 'other'];
    private const RUN_STATES = ['queued', 'in_progress', 'completed'];
    public static function build(array $rows, int $now): array
    {
        if ($now < 1 || !array_is_list($rows) || count($rows) !== count(self::REPOS)) {
            throw new InvalidArgumentException('Activity inventory invalid.');
        }
        $projects = [];
        foreach (self::REPOS as $index => $name) {
            $projects[] = self::project($rows[$index], 'pl0n3r/' . $name, $now);
        }
        $complete = true;
        foreach ($projects as $row) {
            if ($row['freshness'] !== 'current' || $row['available'] === null
                || $row['planned_unlockable'] === null) {
                $complete = false;
                break;
            }
        }
        $queueEmpty = $complete
            ? (array_sum(array_column($projects, 'available')) === 0
                && array_sum(array_column($projects, 'planned_unlockable')) > 0)
            : null;
        return ['version' => 1, 'read_only' => true, 'observed_at' => $now,
            'queue_empty' => $queueEmpty, 'projects' => $projects];
    }
    private static function project(mixed $raw, string $repo, int $now): array
    {
        self::fields($raw, ['repository_ref', 'source_ref', 'observed_at', 'freshness',
            'issues', 'pull_requests', 'coordination_runs', 'planned_unlockable'], 'project');
        if ($raw['repository_ref'] !== $repo || !in_array($raw['freshness'], self::FRESH, true)) {
            throw new InvalidArgumentException('Project identity or freshness invalid.');
        }
        $fresh = $raw['freshness'];
        $source = $raw['source_ref'];
        $at = $raw['observed_at'];
        if ($fresh === 'unknown') {
            if ($source !== null || $at !== null || $raw['issues'] !== null ||
                $raw['pull_requests'] !== null || $raw['coordination_runs'] !== null ||
                $raw['planned_unlockable'] !== null) {
                throw new InvalidArgumentException('Unknown provenance must be absent.');
            }
        } else {
            $expected = 'https://api.github.com/repos/' . $repo . '/issues';
            if (!is_string($source) || $source !== $expected || !is_int($at) || $at < 1 || $at > $now) {
                throw new InvalidArgumentException('GitHub provenance invalid.');
            }
        }
        $issues = self::entries($raw['issues'], 'issue', $now);
        $prs = self::entries($raw['pull_requests'], 'pr', $now);
        $runs = self::entries($raw['coordination_runs'], 'run', $now);
        $planned = $raw['planned_unlockable'];
        if ($planned !== null && (!is_int($planned) || $planned < 0 || $planned > self::MAX_ITEMS)) {
            throw new InvalidArgumentException('Planned count invalid.');
        }
        $current = $fresh === 'current';
        $available = $current && $issues !== null ? count(array_filter($issues, static fn ($x) => $x['status'] === 'available')) : null;
        $reserved = $current && $issues !== null ? count(array_filter($issues, static fn ($x) => in_array($x['status'], ['reserved', 'in_review'], true))) : null;
        $openPrs = $current && $prs !== null ? count($prs) : null;
        $planned = $current ? $planned : null;
        $signalsComplete = $current && $issues !== null && $prs !== null && $runs !== null;
        $last = null;
        if ($signalsComplete) {
            foreach ([['issue', $issues], ['pr', $prs], ['coordination', $runs]] as [$kind, $items]) {
                foreach ($items as $item) {
                    if ($last === null || $item['updated_at'] > $last['at']) {
                        $last = ['at' => $item['updated_at'], 'kind' => $kind];
                    }
                }
            }
        }
        $queueEmpty = $available !== null && $planned !== null
            ? ($available === 0 && $planned > 0) : null;
        return ['repository_ref' => $repo, 'available' => $available, 'reserved' => $reserved,
            'open_prs' => $openPrs, 'planned_unlockable' => $planned, 'queue_empty' => $queueEmpty,
            'last_signal_at' => $last['at'] ?? null,
            'last_signal_age_seconds' => $last === null ? null : $now - $last['at'],
            'last_signal_kind' => $last['kind'] ?? null, 'source_ref' => $source,
            'observed_at' => $at, 'source_age_seconds' => $at === null ? null : $now - $at,
            'freshness' => $fresh, 'activity_state' => !$current ? strtoupper($fresh)
                : ($signalsComplete ? 'OBSERVED' : 'UNKNOWN')];
    }
    private static function entries(mixed $raw, string $type, int $now): ?array
    {
        if ($raw === null) return null;
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Activity source incomplete or invalid.');
        }
        $items = []; $seen = [];
        foreach ($raw as $item) {
            $fields = $type === 'issue' ? ['number', 'status', 'updated_at']
                : ($type === 'pr' ? ['number', 'updated_at'] : ['id', 'status', 'updated_at']);
            self::fields($item, $fields, 'activity item');
            $id = $item[$type === 'run' ? 'id' : 'number'];
            if (!is_int($id) || $id < 1 || isset($seen[$id]) || !is_int($item['updated_at']) ||
                $item['updated_at'] < 1 || $item['updated_at'] > $now) {
                throw new InvalidArgumentException('Activity item identity/time invalid.');
            }
            $seen[$id] = true;
            if ($type === 'issue' && !in_array($item['status'], self::ISSUE_STATES, true)) {
                throw new InvalidArgumentException('Issue status invalid.');
            }
            if ($type === 'run' && !in_array($item['status'], self::RUN_STATES, true)) {
                throw new InvalidArgumentException('Coordination status invalid.');
            }
            $items[] = $item;
        }
        return $items;
    }
    private static function fields(mixed $row, array $required, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) throw new InvalidArgumentException($label . ' invalid.');
        $actual = array_keys($row); sort($actual, SORT_STRING); sort($required, SORT_STRING);
        if ($actual !== $required) throw new InvalidArgumentException($label . ' fields invalid.');
    }
}
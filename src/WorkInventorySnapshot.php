<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class WorkInventorySnapshot
{
    private const REPOSITORIES = [
        'pl0n3r/Factory',
        'pl0n3r/Condor',
        'pl0n3r/GrindFlow',
        'pl0n3r/brvtal',
        'pl0n3r/ControlBot',
        'pl0n3r/AutoFactory',
        'pl0n3r/FactoryRunner',
    ];

    private const STATES = [
        'READY',
        'ALL_BLOCKED',
        'UNMATERIALIZED_WORK',
        'WAITING_DECISION',
        'LIVE_GATED',
        'NO_WORK',
    ];

    private const COUNT_KEYS = [
        'completed',
        'available',
        'reserved',
        'blocked',
        'unmaterialized',
        'decision_required',
        'live_only',
        'future_idea',
        'already_materialized',
    ];

    private const FRESHNESS = ['current', 'stale'];
    private const LIMIT = 100;

    public static function fromCanonical(
        array $inventory,
        string $sourceRef,
        int $observedAt,
        string $freshness
    ): array {
        self::exactFields($inventory, ['version', 'projects'], 'inventory');
        if ($inventory['version'] !== 1) {
            throw new InvalidArgumentException('inventory.version invalid.');
        }

        $sourceRef = self::text($sourceRef, 'source_ref', 240);
        if ($observedAt < 1) {
            throw new InvalidArgumentException('observed_at invalid.');
        }
        if (!in_array($freshness, self::FRESHNESS, true)) {
            throw new InvalidArgumentException('freshness invalid.');
        }

        $projects = $inventory['projects'];
        if (!is_array($projects) || !array_is_list($projects) || count($projects) !== count(self::REPOSITORIES)) {
            throw new InvalidArgumentException('projects invalid.');
        }

        $normalized = [];
        foreach ($projects as $index => $project) {
            $normalized[] = self::project($project, self::REPOSITORIES[$index]);
        }

        return [
            'version' => 1,
            'source_ref' => $sourceRef,
            'observed_at' => $observedAt,
            'freshness' => $freshness,
            'projects' => $normalized,
        ];
    }

    private static function project(mixed $raw, string $expectedRepository): array
    {
        self::exactFields(
            $raw,
            ['repository_ref', 'state', 'counts', 'next_work', 'unmaterialized_identities', 'parent_progress'],
            'project'
        );

        $repository = self::text($raw['repository_ref'], 'repository_ref', 160);
        if ($repository !== $expectedRepository) {
            throw new InvalidArgumentException('repository order invalid.');
        }

        $state = self::choice($raw['state'], self::STATES, 'project.state');
        $counts = self::counts($raw['counts']);
        $nextWork = $raw['next_work'] === null
            ? null
            : self::text($raw['next_work'], 'project.next_work', 200);
        $identities = self::textList($raw['unmaterialized_identities'], 'project.unmaterialized_identities');
        $progress = self::parentProgress($raw['parent_progress']);

        if ($state === 'READY' && $nextWork === null) {
            throw new InvalidArgumentException('READY project requires next_work.');
        }
        if ($state !== 'READY' && $nextWork !== null) {
            throw new InvalidArgumentException('Non-READY project cannot expose next_work.');
        }

        return [
            'repository_ref' => $repository,
            'state' => $state,
            'counts' => $counts,
            'next_work' => $nextWork,
            'unmaterialized_identities' => $identities,
            'parent_progress' => $progress,
        ];
    }

    private static function counts(mixed $raw): array
    {
        self::exactFields($raw, self::COUNT_KEYS, 'counts');
        $out = [];
        foreach (self::COUNT_KEYS as $key) {
            $value = $raw[$key];
            if (!is_int($value) || $value < 0 || $value > 1_000_000) {
                throw new InvalidArgumentException('count invalid.');
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private static function parentProgress(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::LIMIT) {
            throw new InvalidArgumentException('parent_progress invalid.');
        }

        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            self::exactFields($row, ['parent_ref', 'completed', 'total', 'percent'], 'parent_progress');
            $parent = self::text($row['parent_ref'], 'parent_ref', 200);
            if (isset($seen[$parent])) {
                throw new InvalidArgumentException('parent_progress duplicated.');
            }
            $seen[$parent] = true;

            foreach (['completed', 'total', 'percent'] as $field) {
                if (!is_int($row[$field]) || $row[$field] < 0) {
                    throw new InvalidArgumentException('parent_progress number invalid.');
                }
            }
            if ($row['total'] < 1 || $row['completed'] > $row['total'] || $row['percent'] > 100) {
                throw new InvalidArgumentException('parent_progress incoherent.');
            }
            if ($row['percent'] !== intdiv($row['completed'] * 100, $row['total'])) {
                throw new InvalidArgumentException('parent_progress percent invalid.');
            }

            $out[] = [
                'parent_ref' => $parent,
                'completed' => $row['completed'],
                'total' => $row['total'],
                'percent' => $row['percent'],
            ];
        }
        return $out;
    }

    private static function textList(mixed $raw, string $label): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::LIMIT) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $out = [];
        foreach ($raw as $value) {
            $text = self::text($value, $label, 200);
            if (isset($out[$text])) {
                throw new InvalidArgumentException($label . ' duplicated.');
            }
            $out[$text] = true;
        }
        $values = array_keys($out);
        sort($values, SORT_STRING);
        return $values;
    }

    private static function choice(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function exactFields(mixed $raw, array $expected, string $label): void
    {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($raw);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
}

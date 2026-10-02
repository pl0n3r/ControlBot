<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryLiveOrchestratorSnapshot
{
    private const REPOS = [
        'pl0n3r/Factory',
        'pl0n3r/Condor',
        'pl0n3r/GrindFlow',
        'pl0n3r/brvtal',
        'pl0n3r/ControlBot',
        'pl0n3r/AutoFactory',
        'pl0n3r/FactoryRunner',
    ];

    private const FRONT = [
        'available',
        'reserved',
        'in_review',
        'blocked',
        'merged',
        'unknown',
    ];

    private const SENSITIVE =
        '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';

    private const PII =
        '/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|'
        . '\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function build(array $snapshot, int $now): array
    {
        self::canonical($snapshot, $now);

        $central = self::central($snapshot['work_inventory'] ?? null, $now);
        $fronts = self::fronts($snapshot['sections']['work'], $now);
        $decisions = self::decisions($snapshot['sections']['owner_decisions'], $now);

        $out = [
            'version' => 1,
            'observed_at' => $now,
            'source_snapshot' => $snapshot['fingerprint'],
            'read_only' => true,
            'central' => $central,
            'fronts' => $fronts,
            'owner_decisions' => $decisions,
        ];

        $encoded = json_encode(
            $out,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return $out + [
            'fingerprint' => hash('sha256', $encoded),
        ];
    }

    private static function canonical(array $snapshot, int $now): void
    {
        $invalidShape =
            ($snapshot['version'] ?? null) !== 1
            || ! is_int($snapshot['observed_at'] ?? null)
            || $snapshot['observed_at'] < 1
            || $snapshot['observed_at'] > $now
            || ! is_array($snapshot['sections'] ?? null)
            || array_is_list($snapshot['sections']);

        if ($invalidShape) {
            throw new InvalidArgumentException(
                'Canonical FactoryLiveSnapshot invalid.',
            );
        }

        $expected = [
            'batches',
            'owner_decisions',
            'releases',
            'blockers',
            'production',
            'quality',
            'work',
            'learning',
        ];

        if (array_keys($snapshot['sections']) !== $expected) {
            throw new InvalidArgumentException(
                'FactoryLiveSnapshot sections invalid.',
            );
        }

        $fingerprint = $snapshot['fingerprint'] ?? null;
        if (
            ! is_string($fingerprint)
            || preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1
        ) {
            throw new InvalidArgumentException(
                'FactoryLiveSnapshot fingerprint invalid.',
            );
        }

        $copy = $snapshot;
        unset($copy['fingerprint']);

        $encoded = json_encode(
            $copy,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $hash = hash('sha256', $encoded);

        if (! hash_equals($hash, $fingerprint)) {
            throw new InvalidArgumentException(
                'FactoryLiveSnapshot fingerprint mismatch.',
            );
        }
    }

    private static function central(mixed $inventory, int $now): array
    {
        if ($inventory === null) {
            return [
                'activity_state' => 'UNKNOWN',
                'available' => null,
                'reserved' => null,
                'blocked' => null,
                'source_ref' => null,
                'observed_at' => null,
                'freshness' => 'unknown',
                'age_seconds' => null,
            ];
        }

        self::fields(
            $inventory,
            ['version', 'source_ref', 'observed_at', 'freshness', 'projects'],
            'work_inventory',
        );

        if ($inventory['version'] !== 1) {
            throw new InvalidArgumentException(
                'work_inventory.version invalid.',
            );
        }

        $source = self::text(
            $inventory['source_ref'],
            'work_inventory.source_ref',
            240,
        );
        $observedAt = self::time(
            $inventory['observed_at'],
            'work_inventory.observed_at',
            $now,
        );

        if (! in_array($inventory['freshness'], ['current', 'stale'], true)) {
            throw new InvalidArgumentException(
                'work_inventory.freshness invalid.',
            );
        }

        $projects = $inventory['projects'];
        if (
            ! is_array($projects)
            || ! array_is_list($projects)
            || count($projects) !== count(self::REPOS)
        ) {
            throw new InvalidArgumentException(
                'work_inventory.projects invalid.',
            );
        }

        $available = 0;
        $reserved = 0;
        $blocked = 0;

        foreach ($projects as $index => $project) {
            $invalidProject =
                ! is_array($project)
                || array_is_list($project)
                || ($project['repository_ref'] ?? null) !== self::REPOS[$index]
                || ! is_array($project['counts'] ?? null);

            if ($invalidProject) {
                throw new InvalidArgumentException(
                    'work_inventory project invalid.',
                );
            }

            $available += self::count($project['counts']['available'] ?? null);
            $reserved += self::count($project['counts']['reserved'] ?? null);
            $blocked += self::count($project['counts']['blocked'] ?? null);
        }

        $state = self::activityState(
            $inventory['freshness'],
            $available,
            $reserved,
            $blocked,
        );

        return [
            'activity_state' => $state,
            'available' => $available,
            'reserved' => $reserved,
            'blocked' => $blocked,
            'source_ref' => $source,
            'observed_at' => $observedAt,
            'freshness' => $inventory['freshness'],
            'age_seconds' => $now - $observedAt,
        ];
    }

    private static function activityState(
        string $freshness,
        int $available,
        int $reserved,
        int $blocked,
    ): string {
        if ($freshness === 'stale') {
            return 'STALE';
        }
        if ($reserved > 0) {
            return 'ACTIVE';
        }
        if ($available > 0) {
            return 'READY';
        }
        if ($blocked > 0) {
            return 'BLOCKED';
        }

        return 'IDLE';
    }

    private static function fronts(mixed $rows, int $now): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('work signals invalid.');
        }

        $out = [];
        foreach ($rows as $row) {
            self::signal($row, 'github_project_snapshot', $now);

            if ($row['freshness'] === 'unknown') {
                continue;
            }

            $data = $row['data'] ?? null;
            if (! is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('work data invalid.');
            }

            $repository = $data['repository_ref'] ?? null;
            if (
                ! is_string($repository)
                || ! in_array($repository, self::REPOS, true)
            ) {
                throw new InvalidArgumentException('repository_ref invalid.');
            }

            $status = $data['status'] ?? 'unknown';
            if (! is_string($status) || ! in_array($status, self::FRONT, true)) {
                throw new InvalidArgumentException('work status invalid.');
            }

            [$progress, $progressState] = self::progress($row, $data);

            if ($row['freshness'] === 'stale') {
                $status = 'STALE';
            }

            $out[] = [
                'id' => self::id($row['id']),
                'repository_ref' => $repository,
                'issue_ref' => self::issue($data['issue_ref'] ?? null),
                'status' => $status,
                'progress_percent' => $progress,
                'progress_state' => $progressState,
                'source_ref' => self::text(
                    $row['source_ref'],
                    'work.source_ref',
                    240,
                ),
                'observed_at' => $row['observed_at'],
                'freshness' => $row['freshness'],
                'age_seconds' => $now - $row['observed_at'],
            ];
        }

        if (count($out) > 24) {
            throw new InvalidArgumentException('Too many active fronts.');
        }

        usort(
            $out,
            static fn (array $left, array $right): int =>
                $left['id'] <=> $right['id'],
        );

        return $out;
    }

    private static function progress(array $row, array $data): array
    {
        if (
            $row['freshness'] !== 'current'
            || ! isset($data['progress_percent'], $data['progress_evidence'])
        ) {
            return [null, 'UNKNOWN'];
        }

        $progress = $data['progress_percent'];
        if (! is_int($progress) || $progress < 0 || $progress > 100) {
            throw new InvalidArgumentException('progress invalid.');
        }

        self::text(
            $data['progress_evidence'],
            'progress_evidence',
            240,
        );

        return [$progress, 'EVIDENCED'];
    }

    private static function decisions(mixed $rows, int $now): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException(
                'owner_decisions invalid.',
            );
        }

        $out = [];
        foreach ($rows as $row) {
            self::signal($row, 'owner_inbox', $now);

            if ($row['freshness'] === 'unknown') {
                continue;
            }

            $data = $row['data'] ?? null;
            if (! is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException(
                    'owner decision data invalid.',
                );
            }

            $out[] = [
                'id' => self::id($row['id']),
                'issue_ref' => self::issue($data['issue_ref'] ?? null),
                'source_ref' => self::text(
                    $row['source_ref'],
                    'decision.source_ref',
                    240,
                ),
                'observed_at' => $row['observed_at'],
                'freshness' => $row['freshness'],
                'age_seconds' => $now - $row['observed_at'],
            ];
        }

        usort(
            $out,
            static fn (array $left, array $right): int =>
                $left['id'] <=> $right['id'],
        );

        return $out;
    }

    private static function signal(
        mixed $row,
        string $authority,
        int $now,
    ): void {
        $invalid =
            ! is_array($row)
            || array_is_list($row)
            || ($row['authority'] ?? null) !== $authority
            || ! in_array(
                $row['freshness'] ?? null,
                ['current', 'stale', 'unknown'],
                true,
            );

        if ($invalid) {
            throw new InvalidArgumentException('Canonical signal invalid.');
        }

        if ($row['freshness'] === 'unknown') {
            if (
                ($row['source_ref'] ?? null) !== null
                || ($row['observed_at'] ?? null) !== null
            ) {
                throw new InvalidArgumentException(
                    'Unknown signal invalid.',
                );
            }

            return;
        }

        self::text(
            $row['source_ref'] ?? null,
            'signal.source_ref',
            240,
        );
        self::time(
            $row['observed_at'] ?? null,
            'signal.observed_at',
            $now,
        );

        if (
            $row['freshness'] === 'stale'
            && ($row['state'] ?? null) === 'healthy'
        ) {
            throw new InvalidArgumentException(
                'Stale signal cannot be healthy.',
            );
        }
    }

    private static function issue(mixed $value): string
    {
        $pattern =
            '~^(?:https://github\.com/pl0n3r/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*'
            . '|github:pl0n3r/[A-Za-z0-9_.-]+#[1-9][0-9]*)$~D';

        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('issue_ref invalid.');
        }

        return self::text($value, 'issue_ref', 240);
    }

    private static function id(mixed $value): string
    {
        if (
            ! is_string($value)
            || preg_match('/^[a-z][a-z0-9._:\/#-]{2,160}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('signal id invalid.');
        }

        return self::text($value, 'signal.id', 180);
    }

    private static function text(
        mixed $value,
        string $label,
        int $max,
    ): string {
        if (! is_string($value)) {
            throw new InvalidArgumentException($label . ' unsafe.');
        }

        $value = trim($value);
        $invalid =
            $value === ''
            || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match(self::PII, $value) === 1;

        if ($invalid) {
            throw new InvalidArgumentException($label . ' unsafe.');
        }

        return $value;
    }

    private static function time(
        mixed $value,
        string $label,
        int $now,
    ): int {
        if (! is_int($value) || $value < 1 || $value > $now) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function count(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 1_000_000) {
            throw new InvalidArgumentException('count invalid.');
        }

        return $value;
    }

    private static function fields(
        mixed $row,
        array $expected,
        string $label,
    ): void {
        if (! is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $actual = array_keys($row);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new InvalidArgumentException(
                $label . ' fields invalid.',
            );
        }
    }
}

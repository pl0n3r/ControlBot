<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use InvalidArgumentException;

final class GitHubProjectView
{
    private const STATES = ['queued','in_progress','completed','waiting','requested','pending'];
    private const CONCLUSIONS = ['success','failure','neutral','cancelled','skipped','timed_out','action_required','stale','startup_failure'];
    private const LIMIT = 100;

    public static function project(array $snapshot, int $now, int $maxAgeSeconds = 300): array
    {
        if ($now < 1 || $maxAgeSeconds < 1) {
            throw new InvalidArgumentException('View clock invalid.');
        }

        try {
            return self::normalize($snapshot, $now, $maxAgeSeconds);
        } catch (InvalidArgumentException) {
            return self::unknown('snapshot_invalid');
        }
    }

    private static function normalize(array $snapshot, int $now, int $maxAgeSeconds): array
    {
        self::fields($snapshot, ['version','project_id','observed_at','repositories'], 'snapshot');
        if ($snapshot['version'] !== 1) {
            throw new InvalidArgumentException('Snapshot version invalid.');
        }

        $projectId = self::id($snapshot['project_id'], 'project_id');
        $observedAt = self::timestamp($snapshot['observed_at'], $now, 'observed_at');
        $age = $now - $observedAt;
        $freshness = $age <= $maxAgeSeconds ? 'current' : 'stale';

        $rows = self::rows($snapshot['repositories'], 50, 'repositories');
        $repositories = [];
        $seen = [];
        $state = $freshness === 'current' ? 'current' : 'unknown';

        foreach ($rows as $row) {
            $repository = self::repository($row, $observedAt, $now, $maxAgeSeconds);
            $key = strtolower($repository['repository']);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Repository duplicated.');
            }
            $seen[$key] = true;
            if ($repository['state'] === 'unknown') {
                $state = 'unknown';
            }
            $repositories[] = $repository;
        }

        return [
            'version' => 1,
            'project_id' => $projectId,
            'state' => $state,
            'observed_at' => $observedAt,
            'freshness' => $freshness,
            'age_seconds' => $age,
            'repositories' => $repositories,
        ];
    }

    private static function repository(mixed $row, int $projectObservedAt, int $now, int $maxAgeSeconds): array
    {
        self::fields($row, [
            'repository_id','repository','source_ref','observed_at','main_sha',
            'checks','pull_requests','issues','latest_release','latest_workflow',
        ], 'repository');

        $repositoryId = self::id($row['repository_id'], 'repository_id');
        $repository = self::repositoryName($row['repository']);
        $sourceRef = self::sourceRef($row['source_ref'], $repository);
        $observedAt = self::timestamp($row['observed_at'], $now, 'repository.observed_at');
        if ($observedAt !== $projectObservedAt) {
            throw new InvalidArgumentException('Repository observation mismatch.');
        }

        $age = $now - $observedAt;
        $freshness = $age <= $maxAgeSeconds ? 'current' : 'stale';
        $checks = self::checks($row['checks']);
        $pullRequests = self::pullRequests($row['pull_requests']);
        $issues = self::issues($row['issues']);
        $release = self::release($row['latest_release']);
        $workflow = self::workflow($row['latest_workflow']);

        $partial = $checks['truncated'] || $pullRequests['truncated'] || $issues['truncated'];

        return [
            'repository_id' => $repositoryId,
            'repository' => $repository,
            'source_ref' => $sourceRef,
            'observed_at' => $observedAt,
            'freshness' => $freshness,
            'age_seconds' => $age,
            'state' => $freshness === 'current' && !$partial ? 'current' : 'unknown',
            'main_sha' => self::sha($row['main_sha'], 'main_sha'),
            'checks' => $checks,
            'pull_requests' => $pullRequests,
            'issues' => $issues,
            'latest_release' => $release,
            'latest_workflow' => $workflow,
        ];
    }

    private static function checks(mixed $raw): array
    {
        self::fields($raw, ['items','truncated'], 'checks');
        $items = [];
        foreach (self::rows($raw['items'], self::LIMIT, 'checks.items') as $row) {
            self::fields($row, ['name','status','conclusion'], 'check');
            $status = self::choice($row['status'], self::STATES, 'check.status');
            $conclusion = $row['conclusion'] === null
                ? null
                : self::choice($row['conclusion'], self::CONCLUSIONS, 'check.conclusion');
            if (($status === 'completed') !== ($conclusion !== null)) {
                throw new InvalidArgumentException('Check status/conclusion ambiguous.');
            }
            $items[] = [
                'name' => self::text($row['name'], 'check.name', 200),
                'status' => $status,
                'conclusion' => $conclusion,
            ];
        }
        return ['items' => $items, 'truncated' => self::boolean($raw['truncated'], 'checks.truncated')];
    }

    private static function pullRequests(mixed $raw): array
    {
        self::fields($raw, ['items','truncated'], 'pull_requests');
        $items = [];
        foreach (self::rows($raw['items'], self::LIMIT, 'pull_requests.items') as $row) {
            self::fields($row, ['number','title','draft','head_sha','base_ref'], 'pull_request');
            $items[] = [
                'number' => self::natural($row['number'], 'pull_request.number'),
                'title' => self::text($row['title'], 'pull_request.title', 300),
                'draft' => self::boolean($row['draft'], 'pull_request.draft'),
                'head_sha' => self::sha($row['head_sha'], 'pull_request.head_sha'),
                'base_ref' => self::ref($row['base_ref'], 'pull_request.base_ref'),
            ];
        }
        return ['items' => $items, 'truncated' => self::boolean($raw['truncated'], 'pull_requests.truncated')];
    }

    private static function issues(mixed $raw): array
    {
        self::fields($raw, ['items','truncated'], 'issues');
        $items = [];
        foreach (self::rows($raw['items'], self::LIMIT, 'issues.items') as $row) {
            self::fields($row, ['number','title','labels'], 'issue');
            $labels = [];
            foreach (self::rows($row['labels'], 50, 'issue.labels') as $label) {
                $labels[] = self::text($label, 'issue.label', 100);
            }
            $items[] = [
                'number' => self::natural($row['number'], 'issue.number'),
                'title' => self::text($row['title'], 'issue.title', 300),
                'labels' => $labels,
            ];
        }
        return ['items' => $items, 'truncated' => self::boolean($raw['truncated'], 'issues.truncated')];
    }

    private static function release(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        self::fields($raw, ['tag_name','draft','prerelease'], 'release');
        return [
            'tag_name' => self::ref($raw['tag_name'], 'release.tag_name'),
            'draft' => self::boolean($raw['draft'], 'release.draft'),
            'prerelease' => self::boolean($raw['prerelease'], 'release.prerelease'),
        ];
    }

    private static function workflow(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        self::fields($raw, ['name','status','conclusion','head_sha','run_number'], 'workflow');
        $status = self::choice($raw['status'], self::STATES, 'workflow.status');
        $conclusion = $raw['conclusion'] === null
            ? null
            : self::choice($raw['conclusion'], self::CONCLUSIONS, 'workflow.conclusion');
        if (($status === 'completed') !== ($conclusion !== null)) {
            throw new InvalidArgumentException('Workflow status/conclusion ambiguous.');
        }
        return [
            'name' => self::text($raw['name'], 'workflow.name', 200),
            'status' => $status,
            'conclusion' => $conclusion,
            'head_sha' => self::sha($raw['head_sha'], 'workflow.head_sha'),
            'run_number' => self::natural($raw['run_number'], 'workflow.run_number'),
        ];
    }

    private static function unknown(string $reason): array
    {
        return [
            'version' => 1,
            'project_id' => null,
            'state' => 'unknown',
            'observed_at' => null,
            'freshness' => 'unknown',
            'age_seconds' => null,
            'repositories' => [],
            'reason' => $reason,
        ];
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label.' fields invalid.');
        }
    }

    private static function rows(mixed $rows, int $max, string $label): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $max) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $rows;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function repositoryName(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Repository name invalid.');
        }
        return $value;
    }

    private static function sourceRef(mixed $value, string $repository): string
    {
        $expected = 'https://github.com/'.$repository;
        if (!is_string($value) || $value !== $expected) {
            throw new InvalidArgumentException('Repository source invalid.');
        }
        return $value;
    }

    private static function timestamp(mixed $value, int $now, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > $now) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function natural(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > 1_000_000_000) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function sha(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return trim($value);
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 200
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function choice(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function boolean(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }
}

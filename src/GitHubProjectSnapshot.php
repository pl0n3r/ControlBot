<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use ControlBot\Project\ProjectModel;
use InvalidArgumentException;
use RuntimeException;

final class GitHubProjectSnapshot
{
    private const LIMIT = 100;
    private const CHECK_STATUSES = ['queued', 'in_progress', 'completed', 'waiting', 'requested', 'pending'];
    private const CONCLUSIONS = [
        'success', 'failure', 'neutral', 'cancelled', 'skipped',
        'timed_out', 'action_required', 'stale', 'startup_failure',
    ];
    private const RUN_STATUSES = ['queued', 'in_progress', 'completed', 'waiting', 'requested', 'pending'];

    public function __construct(private readonly ApiClient $api) {}

    public function project(array $rawProject, int $observedAt): array
    {
        if ($observedAt < 1) {
            throw new InvalidArgumentException('observed_at invalid.');
        }

        $project = ProjectModel::normalize($rawProject);
        $repositories = [];
        foreach ($project['repositories'] as $repository) {
            $repositories[] = $this->repository($repository, $observedAt);
        }

        return [
            'version' => 1,
            'project_id' => $project['project_id'],
            'observed_at' => $observedAt,
            'repositories' => $repositories,
        ];
    }

    private function repository(array $repository, int $observedAt): array
    {
        $name = $repository['repository'];
        $base = Gateway::repoPath($name);

        $branch = $this->api->json('GET', $base . '/branches/main', null, [200]);
        $commit = self::object($branch['commit'] ?? null, 'main.commit');
        $sha = self::sha($commit['sha'] ?? null, 'main.sha');

        $checks = $this->checks(
            $this->api->json('GET', $base . '/commits/' . $sha . '/check-runs', null, [200], ['per_page' => self::LIMIT]),
        );
        $pullRequests = $this->pullRequests(
            $this->api->json('GET', $base . '/pulls', null, [200], ['state' => 'open', 'per_page' => self::LIMIT]),
        );
        $issues = $this->issues(
            $this->api->json('GET', $base . '/issues', null, [200], ['state' => 'open', 'per_page' => self::LIMIT]),
        );
        $release = $this->release(
            $this->api->json('GET', $base . '/releases', null, [200], ['per_page' => 1]),
        );
        $workflow = $this->workflow(
            $this->api->json('GET', $base . '/actions/runs', null, [200], ['branch' => 'main', 'per_page' => 1]),
        );

        return [
            'repository_id' => $repository['repository_id'],
            'repository' => $name,
            'source_ref' => $repository['source_ref'],
            'observed_at' => $observedAt,
            'main_sha' => $sha,
            'checks' => $checks,
            'pull_requests' => $pullRequests,
            'issues' => $issues,
            'latest_release' => $release,
            'latest_workflow' => $workflow,
        ];
    }

    private function checks(array $payload): array
    {
        $total = self::natural($payload['total_count'] ?? null, 'checks.total_count', true);
        $rows = self::list($payload['check_runs'] ?? null, 'checks.check_runs');

        $items = [];
        foreach ($rows as $row) {
            $row = self::object($row, 'check');
            $status = self::enum($row['status'] ?? null, self::CHECK_STATUSES, 'check.status');
            $conclusion = self::nullableEnum($row['conclusion'] ?? null, self::CONCLUSIONS, 'check.conclusion');
            if (($status === 'completed') !== ($conclusion !== null)) {
                throw new RuntimeException('Check status/conclusion ambiguous.');
            }
            $items[] = [
                'name' => self::text($row['name'] ?? null, 'check.name', 200),
                'status' => $status,
                'conclusion' => $conclusion,
            ];
        }

        if ($total < count($items)) {
            throw new RuntimeException('Check count invalid.');
        }

        return [
            'items' => $items,
            'truncated' => $total > count($items) || count($rows) >= self::LIMIT,
        ];
    }

    private function pullRequests(array $rows): array
    {
        self::listValue($rows, 'pull_requests');
        $items = [];
        foreach ($rows as $row) {
            $row = self::object($row, 'pull_request');
            $head = self::object($row['head'] ?? null, 'pull_request.head');
            $base = self::object($row['base'] ?? null, 'pull_request.base');
            if (!is_bool($row['draft'] ?? null)) {
                throw new RuntimeException('Pull request draft invalid.');
            }
            $items[] = [
                'number' => self::natural($row['number'] ?? null, 'pull_request.number'),
                'title' => self::text($row['title'] ?? null, 'pull_request.title', 300),
                'draft' => $row['draft'],
                'head_sha' => self::sha($head['sha'] ?? null, 'pull_request.head_sha'),
                'base_ref' => self::ref($base['ref'] ?? null, 'pull_request.base_ref'),
            ];
        }
        return ['items' => $items, 'truncated' => count($rows) >= self::LIMIT];
    }

    private function issues(array $rows): array
    {
        self::listValue($rows, 'issues');
        $items = [];
        foreach ($rows as $row) {
            $row = self::object($row, 'issue');
            if (array_key_exists('pull_request', $row)) {
                continue;
            }
            $labels = self::list($row['labels'] ?? null, 'issue.labels');
            if (count($labels) > 50) {
                throw new RuntimeException('Issue labels too many.');
            }
            $normalizedLabels = [];
            foreach ($labels as $label) {
                $label = self::object($label, 'issue.label');
                $normalizedLabels[] = self::text($label['name'] ?? null, 'issue.label.name', 100);
            }
            sort($normalizedLabels, SORT_STRING);
            $items[] = [
                'number' => self::natural($row['number'] ?? null, 'issue.number'),
                'title' => self::text($row['title'] ?? null, 'issue.title', 300),
                'labels' => $normalizedLabels,
            ];
        }
        return ['items' => $items, 'truncated' => count($rows) >= self::LIMIT];
    }

    private function release(array $rows): ?array
    {
        self::listValue($rows, 'releases');
        if ($rows === []) {
            return null;
        }

        $row = self::object($rows[0], 'release');
        if (!is_bool($row['draft'] ?? null) || !is_bool($row['prerelease'] ?? null)) {
            throw new RuntimeException('Release flags invalid.');
        }

        return [
            'tag_name' => self::ref($row['tag_name'] ?? null, 'release.tag_name'),
            'draft' => $row['draft'],
            'prerelease' => $row['prerelease'],
        ];
    }

    private function workflow(array $payload): ?array
    {
        $total = self::natural($payload['total_count'] ?? null, 'workflow.total_count', true);
        $rows = self::list($payload['workflow_runs'] ?? null, 'workflow.runs');
        if ($total < count($rows)) {
            throw new RuntimeException('Workflow count invalid.');
        }
        if ($rows === []) {
            return null;
        }

        $row = self::object($rows[0], 'workflow');
        if (($row['head_branch'] ?? null) !== 'main') {
            throw new RuntimeException('Workflow scope invalid.');
        }
        $status = self::enum($row['status'] ?? null, self::RUN_STATUSES, 'workflow.status');
        $conclusion = self::nullableEnum($row['conclusion'] ?? null, self::CONCLUSIONS, 'workflow.conclusion');
        if (($status === 'completed') !== ($conclusion !== null)) {
            throw new RuntimeException('Workflow status/conclusion ambiguous.');
        }

        return [
            'name' => self::text($row['name'] ?? null, 'workflow.name', 200),
            'status' => $status,
            'conclusion' => $conclusion,
            'head_sha' => self::sha($row['head_sha'] ?? null, 'workflow.head_sha'),
            'run_number' => self::natural($row['run_number'] ?? null, 'workflow.run_number'),
        ];
    }

    private static function list(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::LIMIT) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function listValue(array $value, string $label): void
    {
        if (!array_is_list($value) || count($value) > self::LIMIT) {
            throw new RuntimeException($label . ' invalid.');
        }
    }

    private static function object(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function sha(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function natural(mixed $value, string $label, bool $zero = false): int
    {
        $minimum = $zero ? 0 : 1;
        if (!is_int($value) || $value < $minimum || $value > 1_000_000_000) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\\x00-\\x1f\\x7f]/', $value) === 1) {
            throw new RuntimeException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 200
            || preg_match('/[\\x00-\\x20\\x7f]/', $value) === 1) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableEnum(mixed $value, array $allowed, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::enum($value, $allowed, $label);
    }
}

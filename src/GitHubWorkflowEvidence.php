<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use InvalidArgumentException;
use RuntimeException;

final class GitHubWorkflowEvidence
{
    private const WORKFLOW_LIMIT = 25;
    private const RUN_LIMIT = 2;
    private const STATES = ['queued', 'in_progress', 'completed', 'waiting', 'requested', 'pending'];
    private const CONCLUSIONS = [
        'success', 'failure', 'neutral', 'cancelled', 'skipped',
        'timed_out', 'action_required', 'stale', 'startup_failure',
    ];

    public function __construct(private readonly ApiClient $api) {}

    public function collect(string $repository, int $observedAt): array
    {
        if ($observedAt < 1) {
            throw new InvalidArgumentException('observed_at invalid.');
        }

        $base = Gateway::repoPath($repository);
        $branch = self::object(
            $this->api->json('GET', $base . '/branches/main', null, [200]),
            'main',
        );
        $mainSha = self::sha(
            self::object($branch['commit'] ?? null, 'main.commit')['sha'] ?? null,
            'main.sha',
        );

        $payload = self::object(
            $this->api->json(
                'GET',
                $base . '/actions/workflows',
                null,
                [200],
                ['per_page' => self::WORKFLOW_LIMIT],
            ),
            'workflows',
        );
        $total = self::natural($payload['total_count'] ?? null, 'workflows.total_count', true);
        $rows = self::rows($payload['workflows'] ?? null, 'workflows.items', self::WORKFLOW_LIMIT);
        if ($total < count($rows)) {
            throw new RuntimeException('Workflow count invalid.');
        }

        $items = [];
        foreach ($rows as $row) {
            $row = self::object($row, 'workflow');
            $id = self::natural($row['id'] ?? null, 'workflow.id');
            $name = self::text($row['name'] ?? null, 'workflow.name', 200);
            $path = self::text($row['path'] ?? null, 'workflow.path', 300);
            $state = self::text($row['state'] ?? null, 'workflow.state', 80);

            $runs = self::object(
                $this->api->json(
                    'GET',
                    $base . '/actions/workflows/' . $id . '/runs',
                    null,
                    [200],
                    ['branch' => 'main', 'per_page' => self::RUN_LIMIT],
                ),
                'workflow.runs',
            );

            $items[] = [
                'id' => $id,
                'name' => $name,
                'path' => $path,
                'state' => $state,
                'latest_run' => self::latestRun($runs, $id, $mainSha, $repository, $observedAt),
                'source_ref' => "github:{$repository}#workflow:{$id}@{$mainSha}:{$observedAt}",
            ];
        }

        return [
            'version' => 1,
            'repository' => $repository,
            'observed_at' => $observedAt,
            'main_sha' => $mainSha,
            'items' => $items,
            'truncated' => $total > count($rows) || count($rows) >= self::WORKFLOW_LIMIT,
        ];
    }

    private static function latestRun(
        array $payload,
        int $workflowId,
        string $mainSha,
        string $repository,
        int $observedAt,
    ): array {
        $total = self::natural($payload['total_count'] ?? null, 'workflow.runs.total_count', true);
        $rows = self::rows($payload['workflow_runs'] ?? null, 'workflow.runs.items', self::RUN_LIMIT);
        if ($total < count($rows)) {
            throw new RuntimeException('Workflow run count invalid.');
        }

        $truncated = $total > count($rows) || count($rows) >= self::RUN_LIMIT;
        if ($rows === []) {
            return self::unknownRun('MISSING', $truncated);
        }

        $row = self::object($rows[0], 'workflow.run');
        $runWorkflowId = $row['workflow_id'] ?? null;
        $headBranch = $row['head_branch'] ?? null;
        if (
            !is_int($runWorkflowId)
            || $runWorkflowId !== $workflowId
            || $headBranch !== 'main'
        ) {
            return self::unknownRun('AMBIGUOUS', $truncated);
        }

        $id = self::natural($row['id'] ?? null, 'workflow.run.id');
        $runNumber = self::natural($row['run_number'] ?? null, 'workflow.run.run_number');
        $headSha = self::sha($row['head_sha'] ?? null, 'workflow.run.head_sha');
        $status = self::enum($row['status'] ?? null, self::STATES, 'workflow.run.status');
        $conclusion = self::nullableEnum(
            $row['conclusion'] ?? null,
            self::CONCLUSIONS,
            'workflow.run.conclusion',
        );
        self::terminalPair($status, $conclusion);
        $event = self::text($row['event'] ?? null, 'workflow.run.event', 80);

        $freshness = hash_equals($mainSha, $headSha) ? 'CURRENT' : 'STALE';
        return [
            'evidence_state' => $freshness === 'CURRENT' ? 'COMPLETE' : 'UNKNOWN',
            'freshness' => $freshness,
            'id' => $id,
            'run_number' => $runNumber,
            'head_sha' => $headSha,
            'status' => $status,
            'conclusion' => $conclusion,
            'event' => $event,
            'truncated' => $truncated,
            'source_ref' => "github:{$repository}#workflow-run:{$id}@{$headSha}:{$observedAt}",
        ];
    }

    private static function unknownRun(string $freshness, bool $truncated): array
    {
        return [
            'evidence_state' => 'UNKNOWN',
            'freshness' => $freshness,
            'id' => null,
            'run_number' => null,
            'head_sha' => null,
            'status' => null,
            'conclusion' => null,
            'event' => null,
            'truncated' => $truncated,
            'source_ref' => null,
        ];
    }

    private static function terminalPair(string $status, ?string $conclusion): void
    {
        if (($status === 'completed') !== ($conclusion !== null)) {
            throw new RuntimeException('Workflow run status/conclusion ambiguous.');
        }
    }

    private static function rows(mixed $value, string $label, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function object(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function natural(mixed $value, string $label, bool $zero = false): int
    {
        if (!is_int($value) || $value < ($zero ? 0 : 1) || $value > 1_000_000_000_000) {
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

    private static function text(mixed $value, string $label, int $max): string
    {
        if (
            !is_string($value)
            || trim($value) === ''
            || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            throw new RuntimeException($label . ' invalid.');
        }
        return trim($value);
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
        return $value === null ? null : self::enum($value, $allowed, $label);
    }
}

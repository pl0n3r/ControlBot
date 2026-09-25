<?php
declare(strict_types=1);

namespace ControlBot\Decisions;

use ControlBot\Approvals\HumanGate;
use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\Gateway;
use InvalidArgumentException;

final class GateInbox
{
    private const TRUSTED = ['OWNER', 'MEMBER', 'COLLABORATOR'];

    public function __construct(
        private readonly ApiClient $api,
        private readonly Gateway $gateway,
    ) {}

    public function load(array $repositories): array
    {
        $decisions = [];
        foreach ($repositories as $repository) {
            if (!is_string($repository)) {
                throw new InvalidArgumentException('Repositorio de inbox inválido.');
            }
            $repoPath = Gateway::repoPath($repository);
            $issues = $this->api->json('GET', $repoPath . '/issues', null, [200]);

            foreach ($issues as $issue) {
                $decision = $this->fromIssue($repository, $issue);
                if ($decision !== null) {
                    $decisions[] = $decision;
                }
            }
        }

        usort($decisions, static function (array $left, array $right): int {
            $leftBlocks = isset($left['blocks']) && trim((string) $left['blocks']) !== '' ? 0 : 1;
            $rightBlocks = isset($right['blocks']) && trim((string) $right['blocks']) !== '' ? 0 : 1;
            return [$leftBlocks, $left['_created_at'], $left['repository'], $left['issue']]
                <=> [$rightBlocks, $right['_created_at'], $right['repository'], $right['issue']];
        });

        return array_map(static function (array $decision): array {
            unset($decision['_created_at']);
            return $decision;
        }, $decisions);
    }

    private function fromIssue(string $repository, mixed $issue): ?array
    {
        if (
            !is_array($issue)
            || isset($issue['pull_request'])
            || ($issue['state'] ?? null) !== 'open'
            || !in_array($issue['author_association'] ?? null, self::TRUSTED, true)
            || !is_int($issue['number'] ?? null)
            || ($issue['number'] ?? 0) < 1
            || !is_string($issue['title'] ?? null)
            || !is_string($issue['body'] ?? null)
        ) {
            return null;
        }

        try {
            $gate = HumanGate::fromIssueBody($issue['body']);
            $payload = self::gatePayload($issue['body']);
        } catch (\Throwable) {
            return null;
        }

        $decision = [
            'repository' => $repository,
            'issue' => $issue['number'],
            'title' => $issue['title'],
            'context' => $gate->context,
            'options' => self::options($payload['options']),
            'recommendation' => $gate->recommendation,
            'safe_default' => $gate->safeDefault,
            '_created_at' => self::createdAt($issue['created_at'] ?? null),
        ];

        foreach (['title_simple', 'summary_simple', 'why_recommended', 'blocks'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $decision[$field] = $payload[$field];
            }
        }
        if (isset($issue['html_url']) && is_string($issue['html_url'])) {
            $decision['issue_url'] = $issue['html_url'];
        }

        if ($gate->category === 'factory-release') {
            $decision += $this->releaseEvidence($repository);
        }

        return $decision;
    }

    private function releaseEvidence(string $repository): array
    {
        $sha = $this->gateway->mainSha($repository);
        $path = Gateway::repoPath($repository);
        $checks = $this->api->json('GET', $path . "/commits/{$sha}/check-runs", null, [200]);
        $commit = $this->api->json('GET', $path . "/commits/{$sha}", null, [200]);

        $runs = is_array($checks['check_runs'] ?? null) ? $checks['check_runs'] : [];
        $state = self::ciState($runs);
        $evidence = [];
        foreach ($runs as $run) {
            if (is_array($run) && is_string($run['html_url'] ?? null) && $run['html_url'] !== '') {
                $evidence[] = $run['html_url'];
            }
        }

        $result = [
            'sha' => $sha,
            'ci' => [
                'state' => $state,
                'total' => count($runs),
                'evidence' => array_values(array_unique($evidence)),
            ],
        ];
        if (is_string($commit['html_url'] ?? null) && $commit['html_url'] !== '') {
            $result['commit_url'] = $commit['html_url'];
        }
        return $result;
    }

    private static function ciState(array $runs): string
    {
        if ($runs === []) {
            return 'unknown';
        }
        foreach ($runs as $run) {
            if (!is_array($run) || ($run['status'] ?? null) !== 'completed') {
                return 'pending';
            }
        }
        foreach ($runs as $run) {
            if (!in_array($run['conclusion'] ?? null, ['success', 'neutral', 'skipped'], true)) {
                return 'failure';
            }
        }
        return 'success';
    }

    private static function options(array $options): array
    {
        return array_map(static function (mixed $option): array {
            if (!is_array($option)) {
                throw new InvalidArgumentException('Opción de puerta inválida.');
            }
            return $option;
        }, array_values($options));
    }

    private static function gatePayload(string $body): array
    {
        if (preg_match('/<!--\s*factory-human-gate\s+(\{.*?\})\s*-->/s', $body, $match) !== 1) {
            throw new InvalidArgumentException('Marker factory-human-gate inválido.');
        }
        $payload = json_decode($match[1], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Puerta humana inválida.');
        }
        return $payload;
    }

    private static function createdAt(mixed $value): int
    {
        if (!is_string($value) || strtotime($value) === false) {
            return PHP_INT_MAX;
        }
        return (int) strtotime($value);
    }
}

final class ReleaseRunTracker
{
    public function __construct(private readonly ApiClient $api) {}

    public function status(
        string $repository,
        string $workflow,
        string $sha,
        int $dispatchedAt,
    ): array {
        if (
            preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/', $workflow) !== 1
            || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1
            || $dispatchedAt < 1
        ) {
            throw new InvalidArgumentException('Seguimiento de release inválido.');
        }

        $data = $this->api->json(
            'GET',
            Gateway::repoPath($repository) . '/actions/workflows/' . rawurlencode($workflow) . '/runs',
            null,
            [200]
        );
        $runs = is_array($data['workflow_runs'] ?? null) ? $data['workflow_runs'] : [];

        $matches = [];
        foreach ($runs as $run) {
            if (
                !is_array($run)
                || ($run['event'] ?? null) !== 'workflow_dispatch'
                || ($run['head_sha'] ?? null) !== $sha
                || !is_string($run['created_at'] ?? null)
            ) {
                continue;
            }
            $createdAt = strtotime($run['created_at']);
            if ($createdAt === false || $createdAt < $dispatchedAt) {
                continue;
            }
            $matches[] = ['created' => $createdAt, 'run' => $run];
        }

        if ($matches === []) {
            return ['state' => 'pending', 'terminal' => false, 'run_url' => null];
        }
        usort($matches, static fn (array $a, array $b): int => $a['created'] <=> $b['created']);
        $run = $matches[0]['run'];
        $status = $run['status'] ?? null;
        $conclusion = $run['conclusion'] ?? null;
        $terminal = $status === 'completed';
        $state = $terminal
            ? ($conclusion === 'success' ? 'success' : 'failure')
            : 'running';

        return [
            'state' => $state,
            'terminal' => $terminal,
            'conclusion' => is_string($conclusion) ? $conclusion : null,
            'run_id' => is_int($run['id'] ?? null) ? $run['id'] : null,
            'run_url' => is_string($run['html_url'] ?? null) ? $run['html_url'] : null,
        ];
    }
}

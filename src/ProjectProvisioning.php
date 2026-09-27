<?php
declare(strict_types=1);

namespace ControlBot\Project;

use ControlBot\GitHub\Gateway;
use InvalidArgumentException;
use RuntimeException;

interface ProjectProvisioningAdapter
{
    public function dispatch(array $inputs): string;
}

final class GitHubFactoryProvisioningAdapter implements ProjectProvisioningAdapter
{
    private const FACTORY_REPOSITORY = 'pl0n3r/factory';
    private const FACTORY_WORKFLOW = 'provision-project.yml';
    private const INPUTS = [
        'project_id',
        'project_slug',
        'target_repository',
        'governance_ref',
        'idempotency_key',
    ];

    public function __construct(private readonly Gateway $github) {}

    public function dispatch(array $inputs): string
    {
        $keys = array_keys($inputs);
        sort($keys);
        $expected = self::INPUTS;
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Contrato Factory de provisión inválido.');
        }

        return $this->github->dispatchWorkflow(
            self::FACTORY_REPOSITORY,
            self::FACTORY_WORKFLOW,
            $inputs,
        );
    }
}

final class ProjectProvisioner
{
    private const GOVERNANCE_REF = 'pl0n3r/factory@v1';

    public static function request(
        array $rawProject,
        string $repository,
        bool $approved,
        ProjectProvisioningAdapter $adapter,
        int $observedAt,
    ): array {
        if (!$approved) {
            throw new InvalidArgumentException('La provisión requiere aprobación explícita.');
        }
        self::repository($repository);
        self::observedAt($observedAt);

        $project = ProjectModel::normalize($rawProject);
        $idempotencyKey = hash('sha256', $project['project_id'] . "\0" . strtolower($repository));

        foreach ($project['repositories'] as $current) {
            if (strcasecmp($current['repository'], $repository) === 0) {
                return [
                    'status' => 'confirmed',
                    'dispatched' => false,
                    'idempotency_key' => $idempotencyKey,
                    'repository' => $current,
                    'evidence' => null,
                    'project' => $project,
                ];
            }
        }

        $inputs = [
            'project_id' => $project['project_id'],
            'project_slug' => $project['slug'],
            'target_repository' => $repository,
            'governance_ref' => self::GOVERNANCE_REF,
            'idempotency_key' => $idempotencyKey,
        ];
        $evidence = $adapter->dispatch($inputs);
        if (
            !str_starts_with($evidence, 'https://github.com/pl0n3r/factory/actions/workflows/')
            || strlen($evidence) > 300
        ) {
            throw new RuntimeException('Evidencia de provisión inválida.');
        }

        return [
            'status' => 'requested',
            'dispatched' => true,
            'idempotency_key' => $idempotencyKey,
            'repository' => self::relation($repository, $observedAt),
            'evidence' => $evidence,
            'project' => $project,
        ];
    }

    public static function confirm(array $rawProject, array $repository): array
    {
        $project = ProjectModel::normalize($rawProject);
        foreach ($project['repositories'] as $current) {
            if (strcasecmp($current['repository'], (string) ($repository['repository'] ?? '')) === 0) {
                return $project;
            }
        }

        $next = $project['repositories'];
        $next[] = $repository;
        return ProjectModel::reassociateRepositories($project, $next);
    }

    private static function relation(string $repository, int $observedAt): array
    {
        return [
            'repository_id' => 'repo-' . substr(hash('sha256', strtolower($repository)), 0, 16),
            'repository' => $repository,
            'source_ref' => 'https://github.com/' . $repository,
            'observed_at' => $observedAt,
        ];
    }

    private static function repository(string $repository): void
    {
        if (
            preg_match('/^pl0n3r\/[A-Za-z0-9_.-]{1,100}$/D', $repository) !== 1
            || str_contains($repository, '..')
        ) {
            throw new InvalidArgumentException('Repositorio objetivo inválido.');
        }
    }

    private static function observedAt(int $observedAt): void
    {
        if ($observedAt < 1) {
            throw new InvalidArgumentException('observed_at inválido.');
        }
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Project;

use InvalidArgumentException;

final class ProjectBackfill
{
    public static function apply(array $existing, array $definitions, int $observedAt): array
    {
        if (!array_is_list($existing) || count($existing) > 100 || $observedAt < 1) {
            throw new InvalidArgumentException('Entrada de backfill inválida.');
        }
        if (!array_is_list($definitions) || count($definitions) > 20) {
            throw new InvalidArgumentException('Definiciones de backfill inválidas.');
        }

        $projects = [];
        foreach ($existing as $raw) {
            $project = ProjectModel::normalize($raw);
            $id = $project['project_id'];
            if (isset($projects[$id])) {
                throw new InvalidArgumentException('Proyecto duplicado en backfill.');
            }
            $projects[$id] = $project;
        }

        $definitionIds = [];
        $definitionRepositories = [];
        foreach ($definitions as $definition) {
            self::fields($definition);
            $id = (string) $definition['project_id'];
            $repository = (string) $definition['repository'];
            $repositoryKey = strtolower($repository);
            if (isset($definitionIds[$id]) || isset($definitionRepositories[$repositoryKey])) {
                throw new InvalidArgumentException('Definición duplicada en backfill.');
            }
            $definitionIds[$id] = true;
            $definitionRepositories[$repositoryKey] = true;

            if (!isset($projects[$id])) {
                $projects[$id] = ProjectModel::normalize(self::seed($definition, $observedAt));
                continue;
            }

            $project = $projects[$id];
            $present = false;
            foreach ($project['repositories'] as $current) {
                if (strcasecmp($current['repository'], $repository) === 0) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                $repositories = $project['repositories'];
                $repositories[] = self::relation($repository, $observedAt);
                $projects[$id] = ProjectModel::reassociateRepositories($project, $repositories);
            }
        }

        ksort($projects);
        return array_values($projects);
    }

    private static function seed(array $definition, int $observedAt): array
    {
        return [
            'version' => 1,
            'project_id' => $definition['project_id'],
            'slug' => $definition['slug'],
            'title' => $definition['title'],
            'phase' => $definition['phase'],
            'priority' => $definition['priority'],
            'repositories' => [self::relation($definition['repository'], $observedAt)],
            'environments' => [],
            'aggregate_refs' => [
                'roadmap' => null,
                'agents' => null,
                'decisions' => null,
                'health' => null,
                'incidents' => null,
                'costs' => null,
            ],
            'history_refs' => [],
        ];
    }

    private static function relation(string $repository, int $observedAt): array
    {
        if (
            preg_match('/^pl0n3r\/[A-Za-z0-9_.-]{1,100}$/D', $repository) !== 1
            || str_contains($repository, '..')
        ) {
            throw new InvalidArgumentException('Repositorio de backfill inválido.');
        }

        return [
            'repository_id' => 'repo-' . substr(hash('sha256', strtolower($repository)), 0, 16),
            'repository' => $repository,
            'source_ref' => 'https://github.com/' . $repository,
            'observed_at' => $observedAt,
        ];
    }

    private static function fields(mixed $definition): void
    {
        if (!is_array($definition) || array_is_list($definition)) {
            throw new InvalidArgumentException('Definición de proyecto inválida.');
        }
        $expected = ['phase', 'priority', 'project_id', 'repository', 'slug', 'title'];
        $actual = array_keys($definition);
        sort($actual);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Campos de definición inválidos.');
        }
    }
}

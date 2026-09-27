<?php
declare(strict_types=1);

namespace ControlBot\Project;

use InvalidArgumentException;

final class ProjectModel
{
    private const PHASES = ['idea','discovery','planned','building','validating','live','paused','archived'];
    private const PRIORITIES = ['critical','high','medium','low'];
    private const ENVIRONMENTS = ['dev','staging','prod'];
    private const AGGREGATES = ['roadmap','agents','decisions','health','incidents','costs'];

    public static function normalize(array $raw): array
    {
        self::fields($raw, [
            'version','project_id','slug','title','phase','priority',
            'repositories','environments','aggregate_refs','history_refs',
        ], 'Project');

        if ($raw['version'] !== 1) {
            throw new InvalidArgumentException('Project version invalid.');
        }

        return [
            'version' => 1,
            'project_id' => self::id($raw['project_id'], 'project_id'),
            'slug' => self::slug($raw['slug'], 'slug'),
            'title' => self::text($raw['title'], 'title', 120),
            'phase' => self::enum($raw['phase'], self::PHASES, 'phase'),
            'priority' => self::enum($raw['priority'], self::PRIORITIES, 'priority'),
            'repositories' => self::repositories($raw['repositories']),
            'environments' => self::environments($raw['environments']),
            'aggregate_refs' => self::aggregates($raw['aggregate_refs']),
            'history_refs' => self::historyRefs($raw['history_refs']),
        ];
    }

    public static function reassociateRepositories(array $raw, array $repositories): array
    {
        $project = self::normalize($raw);
        $next = self::repositories($repositories);

        $nextById = [];
        foreach ($next as $repository) {
            $nextById[$repository['repository_id']] = $repository;
        }

        $history = $project['history_refs'];
        foreach ($project['repositories'] as $repository) {
            $replacement = $nextById[$repository['repository_id']] ?? null;
            if ($replacement === null || $replacement['source_ref'] !== $repository['source_ref']) {
                $history[] = $repository['source_ref'];
            }
        }

        $project['repositories'] = $next;
        $project['history_refs'] = self::historyRefs(array_values(array_unique($history)));
        return $project;
    }

    private static function repositories(mixed $rows): array
    {
        $out = [];
        $seen = [];
        foreach (self::rows($rows, 50, 'repositories') as $row) {
            self::fields($row, ['repository_id','repository','source_ref','observed_at'], 'Repository');
            $id = self::id($row['repository_id'], 'repository_id');
            $name = self::repoName($row['repository']);
            $source = self::githubRef($row['source_ref']);
            self::unique($seen, 'id:' . $id, 'Repository duplicated.');
            self::unique($seen, 'name:' . strtolower($name), 'Repository duplicated.');
            if ($source !== 'https://github.com/' . $name) {
                throw new InvalidArgumentException('Repository source mismatch.');
            }
            $out[] = [
                'repository_id' => $id,
                'repository' => $name,
                'source_ref' => $source,
                'observed_at' => self::timestamp($row['observed_at'], 'repository.observed_at'),
            ];
        }
        return $out;
    }

    private static function environments(mixed $rows): array
    {
        $out = [];
        $seen = [];
        foreach (self::rows($rows, 20, 'environments') as $row) {
            self::fields($row, ['environment_id','kind','source_ref','observed_at'], 'Environment');
            $id = self::id($row['environment_id'], 'environment_id');
            $source = self::reference($row['source_ref'], 'environment.source_ref');
            self::unique($seen, 'id:' . $id, 'Environment duplicated.');
            self::unique($seen, 'source:' . $source, 'Environment duplicated.');
            $out[] = [
                'environment_id' => $id,
                'kind' => self::enum($row['kind'], self::ENVIRONMENTS, 'environment.kind'),
                'source_ref' => $source,
                'observed_at' => self::timestamp($row['observed_at'], 'environment.observed_at'),
            ];
        }
        return $out;
    }

    private static function rows(mixed $rows, int $max, string $label): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $max) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $rows;
    }

    private static function unique(array &$seen, string $key, string $message): void
    {
        if (isset($seen[$key])) {
            throw new InvalidArgumentException($message);
        }
        $seen[$key] = true;
    }

    private static function aggregates(mixed $raw): array
    {
        self::fields($raw, self::AGGREGATES, 'aggregate_refs');
        $out = [];
        foreach (self::AGGREGATES as $key) {
            $value = $raw[$key];
            if ($value === null) {
                $out[$key] = null;
                continue;
            }
            self::fields($value, ['ref','observed_at'], 'aggregate_ref');
            $out[$key] = [
                'ref' => self::reference($value['ref'], 'aggregate_ref.ref'),
                'observed_at' => self::timestamp($value['observed_at'], 'aggregate_ref.observed_at'),
            ];
        }
        return $out;
    }

    private static function historyRefs(mixed $refs): array
    {
        if (!is_array($refs) || !array_is_list($refs)) {
            throw new InvalidArgumentException('history_refs invalid.');
        }
        $out = [];
        foreach ($refs as $ref) {
            $normalized = self::reference($ref, 'history_ref');
            if (isset($out[$normalized])) {
                throw new InvalidArgumentException('history_ref duplicated.');
            }
            $out[$normalized] = true;
        }
        $refs = array_keys($out);
        sort($refs);
        return $refs;
    }

    private static function reference(mixed $value, string $label): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 240) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        if (str_starts_with($value, 'controlbot:')) {
            if (preg_match('/^controlbot:[A-Za-z0-9][A-Za-z0-9._:\\/#@-]*$/D', $value) !== 1) {
                throw new InvalidArgumentException($label . ' invalid.');
            }
            return $value;
        }
        return self::githubRef($value);
    }

    private static function githubRef(mixed $value): string
    {
        if (!is_string($value) || preg_match('#^https://github\\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/(?:issues|pull)/[1-9][0-9]*)?$#D', $value) !== 1) {
            throw new InvalidArgumentException('GitHub ref invalid.');
        }
        return $value;
    }

    private static function repoName(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('Repository name invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function slug(mixed $value, string $label): string
    {
        return self::id($value, $label);
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\\x00-\\x1f\\x7f]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
}

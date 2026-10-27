<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use Closure;
use Throwable;

require_once __DIR__ . '/GitHubProjectView.php';

/** Offline-only, fail-closed projection assembler. No runtime or network integration. */
final class GitHubReadOnlyProjectionWriter
{
    private const MAX_PROJECTS = 50;
    private const MAX_REPOSITORIES = 100;
    private const MAX_BYTES = 2_000_000;

    public function __construct(private readonly bool $enabled = false) {}

    /**
     * @param array<array<string,mixed>> $snapshots Complete GitHubProjectSnapshot v1 structures.
     * @param array<int,array<string,mixed>> $expected Exact project/repository/SHA/clock attestations.
     * @param Closure|null $witness Test-only stable-identity fixture, called before and after staging.
     * @return array<string,mixed> No data is returned on failure.
     */
    public function write(
        array $snapshots,
        array $expected,
        string $fixtureDirectory,
        int $now,
        int $maxAgeSeconds = 300,
        ?Closure $witness = null,
    ): array {
        if (!$this->enabled || $witness === null) {
            return self::denied('disabled');
        }
        $temp = null;
        try {
            if ($now < 1 || $maxAgeSeconds < 1 || $maxAgeSeconds > 3600
                || !array_is_list($snapshots) || count($snapshots) < 1
                || count($snapshots) > self::MAX_PROJECTS
                || !array_is_list($expected) || count($expected) !== count($snapshots)) {
                return self::denied('input_invalid');
            }

            // Never address the runtime var/github-readonly.json. Only one explicit
            // first-level, private, test-fixture directory under the system temp root.
            $root = realpath(sys_get_temp_dir());
            $directory = realpath($fixtureDirectory);
            if ($root === false || $directory === false || is_link($fixtureDirectory)
                || dirname($directory) !== $root
                || !str_starts_with(basename($directory), 'cb-projection-fixture-')
                || !is_writable($directory)) {
                return self::denied('fixture_invalid');
            }

            $seenProjects = [];
            $seenRepositories = [];
            $manifest = [];
            $repositoryCount = 0;
            foreach ($snapshots as $snapshot) {
                if (!is_array($snapshot) || array_is_list($snapshot)
                    || ($snapshot['version'] ?? null) !== 1
                    || !is_string($snapshot['project_id'] ?? null)
                    || !is_int($snapshot['observed_at'] ?? null)
                    || !is_array($snapshot['repositories'] ?? null)
                    || !array_is_list($snapshot['repositories'])
                    || count($snapshot['repositories']) < 1) {
                    return self::denied('snapshot_invalid');
                }
                $projectId = $snapshot['project_id'];
                if (isset($seenProjects[$projectId])) {
                    return self::denied('duplicate_project');
                }
                $seenProjects[$projectId] = true;
                $view = GitHubProjectView::project($snapshot, $now, $maxAgeSeconds);
                if (($view['state'] ?? null) !== 'current'
                    || ($view['freshness'] ?? null) !== 'current'
                    || ($view['project_id'] ?? null) !== $projectId) {
                    return self::denied('partial_or_stale');
                }
                $identities = [];
                foreach ($view['repositories'] as $repository) {
                    $repositoryCount++;
                    if ($repositoryCount > self::MAX_REPOSITORIES
                        || ($repository['state'] ?? null) !== 'current'
                        || ($repository['freshness'] ?? null) !== 'current') {
                        return self::denied('partial_or_stale');
                    }
                    $name = $repository['repository'];
                    $key = strtolower($name);
                    if (isset($seenRepositories[$key])
                        || $repository['source_ref'] !== 'https://github.com/' . $name
                        || !preg_match('/^[a-f0-9]{40}$/D', $repository['main_sha'])
                        || $repository['observed_at'] !== $snapshot['observed_at']) {
                        return self::denied('identity_invalid');
                    }
                    $seenRepositories[$key] = true;
                    $identities[] = [
                        'repository' => $name,
                        'main_sha' => $repository['main_sha'],
                        'observed_at' => $repository['observed_at'],
                    ];
                }
                $manifest[] = [
                    'project_id' => $projectId,
                    'observed_at' => $snapshot['observed_at'],
                    'repositories' => $identities,
                ];
            }
            if ($manifest !== $expected || $witness() !== $expected) {
                return self::denied('identity_race');
            }
            $body = json_encode(['version' => 1, 'projects' => $snapshots],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($body) || strlen($body) < 2 || strlen($body) > self::MAX_BYTES
                || preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $body) === 1
                || preg_match('/\b(?:ghp_|github_pat_|Bearer )[A-Za-z0-9_\-]+/i', $body) === 1) {
                return self::denied('size_limit');
            }
            $temp = tempnam($directory, '.projection-stage-');
            if ($temp === false || dirname($temp) !== $directory) {
                return self::denied('stage_invalid');
            }
            chmod($temp, 0600);
            if (file_put_contents($temp, $body, LOCK_EX) !== strlen($body)) {
                return self::denied('stage_invalid');
            }
            // A concurrent change in owner-provided witness refuses the atomic swap.
            if ($witness() !== $expected) {
                return self::denied('identity_race');
            }
            $target = $directory . '/projection.json';
            if (is_link($target) || (file_exists($target) && !is_file($target))
                || !rename($temp, $target)) {
                return self::denied('commit_invalid');
            }
            $temp = null;
            return [
                'status' => 'CURRENT', 'reason' => 'atomic_fixture_written',
                'project_count' => count($manifest), 'repository_count' => $repositoryCount,
                'runtime_authorized' => false, 'production_verified' => false,
                'execute_actions' => false,
            ];
        } catch (Throwable) {
            return self::denied('invalid');
        } finally {
            if (is_string($temp) && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private static function denied(string $reason): array
    {
        return [
            'status' => 'UNKNOWN', 'reason' => $reason,
            'project_count' => 0, 'repository_count' => 0,
            'runtime_authorized' => false, 'production_verified' => false,
            'execute_actions' => false,
        ];
    }
}

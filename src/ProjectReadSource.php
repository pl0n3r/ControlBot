<?php
declare(strict_types=1);
namespace ControlBot\Project;
use RuntimeException;
use Throwable;
require_once __DIR__ . '/ProjectModel.php';
/**
 * Private, read-only server snapshot. A public repository list is not authority
 * for environments or health; absence/staleness fails closed.
 */
final class ProjectReadSource
{
    private const MAX_BYTES = 262144;
    private const MAX_AGE = 300;
    private const REPOSITORIES = [
        'project-controlbot'=>'pl0n3r/ControlBot', 'project-condor'=>'pl0n3r/Condor',
        'project-brvtal'=>'pl0n3r/brvtal', 'project-grindflow'=>'pl0n3r/GrindFlow',
    ];
    public static function readConfigured(array $environment, int $now): array
    {
        $path = $environment['CONTROLBOT_PROJECTS_SNAPSHOT_PATH'] ?? null;
        if (!is_string($path) || $path === '' || strlen($path) > 2048
            || $path[0] !== '/' || str_contains($path, "\0")
            || str_contains($path, '..') || str_contains($path, '//')) {
            throw new RuntimeException('Private project snapshot not configured.');
        }
        $real = realpath($path);
        if (!is_string($real) || $real !== $path || is_link($path)
            || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Private project snapshot unavailable.');
        }
        $public = $environment['CONTROLBOT_DOCUMENT_ROOT'] ?? ($_SERVER['DOCUMENT_ROOT'] ?? null);
        if (is_string($public) && $public !== '') {
            $publicReal = realpath($public);
            if ($publicReal !== false && ($real === $publicReal
                || str_starts_with($real, rtrim($publicReal, '/') . '/'))) {
                throw new RuntimeException('Project snapshot must not be public.');
            }
        }
        $before = @stat($real);
        // Owner-only files: reject any group/other read, write or execute permission.
        if (!is_array($before) || ($before['mode'] & 0077) !== 0
            || $before['size'] < 2 || $before['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Private project snapshot permissions/size invalid.');
        }
        $stream = @fopen($real, 'rb');
        if (!is_resource($stream)) throw new RuntimeException('Project snapshot unreadable.');
        try {
            $during = fstat($stream);
            if (!is_array($during) || $during['ino'] !== $before['ino']
                || $during['dev'] !== $before['dev']
                || ($during['mode'] & 0077) !== 0) {
                throw new RuntimeException('Project snapshot changed during read.');
            }
            $raw = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally { fclose($stream); }
        if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > self::MAX_BYTES) {
            throw new RuntimeException('Project snapshot size invalid.');
        }
        try { $decoded = json_decode($raw, true, 20, JSON_THROW_ON_ERROR); }
        catch (Throwable $error) { throw new RuntimeException('Project snapshot JSON invalid.', 0, $error); }
        if (!is_array($decoded) || array_is_list($decoded)
            || !self::sameKeys($decoded, ['version', 'observed_at', 'projects'])
            || !self::fresh($decoded['observed_at'], $now)) {
            throw new RuntimeException('Project snapshot timestamp/schema invalid.');
        }
        return self::verifyProjection(['version'=>$decoded['version'], 'projects'=>$decoded['projects']], $now);
    }
    /** Validate the injected backend as well: no project may be synthesized. */
    public static function verifyProjection(mixed $snapshot, int $now): array
    {
        if (!is_array($snapshot) || array_is_list($snapshot)
            || !self::sameKeys($snapshot, ['version','projects'])
            || $snapshot['version'] !== 1 || !is_array($snapshot['projects'])
            || !array_is_list($snapshot['projects'])
            || count($snapshot['projects']) !== count(self::REPOSITORIES)) {
            throw new RuntimeException('Trusted project projection unavailable.');
        }
        $seen = [];
        $out = [];
        try {
            foreach ($snapshot['projects'] as $row) {
                if (!is_array($row)) throw new RuntimeException('Invalid project.');
                $project = ProjectModel::normalize($row);
                $id = $project['project_id'];
                $repository = self::REPOSITORIES[$id] ?? null;
                if ($repository === null || isset($seen[$id]) || $project['repositories'] === []) {
                    throw new RuntimeException('Unknown or duplicate project.');
                }
                $seen[$id] = true;
                $found = false;
                foreach ($project['repositories'] as $repo) {
                    if (!self::fresh($repo['observed_at'], $now)) {
                        throw new RuntimeException('Repository observation is stale.');
                    }
                    if ($repo['repository'] === $repository) $found = true;
                }
                if (!$found) throw new RuntimeException('Project repository provenance invalid.');
                foreach ($project['environments'] as $env) {
                    if (!self::fresh($env['observed_at'], $now)) {
                        throw new RuntimeException('Environment observation is stale.');
                    }
                }
                foreach ($project['aggregate_refs'] as $aggregate) {
                    if ($aggregate !== null && !self::fresh($aggregate['observed_at'], $now)) {
                        throw new RuntimeException('Aggregate observation is stale.');
                    }
                }
                $out[] = $project;
            }
        } catch (Throwable $error) {
            throw new RuntimeException('Trusted project records invalid.', 0, $error);
        }
        if (count($seen) !== count(self::REPOSITORIES)) {
            throw new RuntimeException('Trusted project coverage incomplete.');
        }
        return ['version' => 1, 'projects' => $out];
    }
    private static function fresh(mixed $timestamp, int $now): bool
    {
        return is_int($timestamp) && $now > 0 && $timestamp > 0
            && $timestamp <= $now && $now - $timestamp <= self::MAX_AGE;
    }
    private static function sameKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys); sort($expected);
        return $keys === $expected;
    }
}

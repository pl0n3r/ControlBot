<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use InvalidArgumentException;
use RuntimeException;

final class GitHubReleaseProvenance
{
    private const RELEASE_LIMIT = 10;
    private const REF_LIMIT = 10;
    private const MAX_TAG_DEPTH = 4;

    public function __construct(private readonly ApiClient $api) {}

    public function collect(string $repository, int $observedAt): array
    {
        if ($observedAt < 1) {
            throw new InvalidArgumentException('observed_at invalid.');
        }

        $base = Gateway::repoPath($repository);
        $releases = $this->api->json(
            'GET',
            $base . '/releases',
            null,
            [200],
            ['per_page' => self::RELEASE_LIMIT],
        );
        self::rows($releases, 'releases', self::RELEASE_LIMIT);

        $release = $this->stableRelease($releases);
        if ($release === null) {
            return $this->unknown(
                $repository,
                $observedAt,
                count($releases) >= self::RELEASE_LIMIT
                    ? 'stable_release_not_observed_within_bound'
                    : 'stable_release_absent',
            );
        }

        $releaseId = self::natural($release['id'] ?? null, 'release.id');
        $tag = self::tag($release['tag_name'] ?? null, 'release.tag_name');

        $refs = $this->api->json(
            'GET',
            $base . '/git/matching-refs/tags/' . rawurlencode($tag),
            null,
            [200],
        );
        self::rows($refs, 'tag.refs', self::REF_LIMIT);
        $targetRef = 'refs/tags/' . $tag;
        $exact = [];
        foreach ($refs as $row) {
            $row = self::object($row, 'tag.ref');
            if (($row['ref'] ?? null) === $targetRef) {
                $exact[] = $row;
            }
        }

        if (count($exact) === 0) {
            return $this->unknown($repository, $observedAt, 'release_tag_absent');
        }
        if (count($exact) !== 1 || count($refs) >= self::REF_LIMIT) {
            return $this->unknown($repository, $observedAt, 'release_tag_ambiguous');
        }

        $object = self::object($exact[0]['object'] ?? null, 'tag.ref.object');
        $refType = self::targetType($object['type'] ?? null, 'tag.ref.object.type');
        $refSha = self::sha($object['sha'] ?? null, 'tag.ref.object.sha');

        $commitSha = null;
        $tagDepth = 0;
        $targetType = $refType;
        $targetSha = $refSha;

        while ($targetType === 'tag') {
            ++$tagDepth;
            if ($tagDepth > self::MAX_TAG_DEPTH) {
                return $this->unknown($repository, $observedAt, 'tag_chain_too_deep');
            }

            $annotated = $this->api->json(
                'GET',
                $base . '/git/tags/' . $targetSha,
                null,
                [200],
            );
            $annotated = self::object($annotated, 'annotated_tag');
            if (self::sha($annotated['sha'] ?? null, 'annotated_tag.sha') !== $targetSha) {
                throw new RuntimeException('annotated_tag identity mismatch.');
            }

            $next = self::object($annotated['object'] ?? null, 'annotated_tag.object');
            $targetType = self::targetType(
                $next['type'] ?? null,
                'annotated_tag.object.type',
            );
            $targetSha = self::sha(
                $next['sha'] ?? null,
                'annotated_tag.object.sha',
            );
        }

        if ($targetType !== 'commit') {
            return $this->unknown($repository, $observedAt, 'tag_target_not_commit');
        }
        $commitSha = $targetSha;

        $commit = $this->api->json(
            'GET',
            $base . '/commits/' . $commitSha,
            null,
            [200],
        );
        $commit = self::object($commit, 'commit');
        if (self::sha($commit['sha'] ?? null, 'commit.sha') !== $commitSha) {
            throw new RuntimeException('commit identity mismatch.');
        }

        return [
            'version' => 1,
            'repository' => $repository,
            'observed_at' => $observedAt,
            'release_identity' => [
                'state' => 'KNOWN',
                'reason' => null,
                'release_id' => $releaseId,
                'tag_name' => $tag,
                'tag_ref_sha' => $refSha,
                'tag_kind' => $refType === 'tag' ? 'annotated' : 'lightweight',
                'tag_depth' => $tagDepth,
                'commit_sha' => $commitSha,
                'source_ref' => "github:{$repository}#release:{$releaseId}@{$tag}:{$commitSha}:{$observedAt}",
            ],
            'deployed_version' => self::unknownDeployment(),
        ];
    }

    private function stableRelease(array $rows): ?array
    {
        foreach ($rows as $row) {
            $row = self::object($row, 'release');
            $draft = $row['draft'] ?? null;
            $prerelease = $row['prerelease'] ?? null;
            if (!is_bool($draft) || !is_bool($prerelease)) {
                throw new RuntimeException('release flags invalid.');
            }
            if (!$draft && !$prerelease) {
                return $row;
            }
        }

        return null;
    }

    private function unknown(string $repository, int $observedAt, string $reason): array
    {
        return [
            'version' => 1,
            'repository' => $repository,
            'observed_at' => $observedAt,
            'release_identity' => [
                'state' => 'UNKNOWN',
                'reason' => $reason,
                'release_id' => null,
                'tag_name' => null,
                'tag_ref_sha' => null,
                'tag_kind' => null,
                'tag_depth' => null,
                'commit_sha' => null,
                'source_ref' => null,
            ],
            'deployed_version' => self::unknownDeployment(),
        ];
    }

    private static function unknownDeployment(): array
    {
        return [
            'state' => 'UNKNOWN',
            'reason' => 'deployment_evidence_not_provided',
            'tag_name' => null,
            'commit_sha' => null,
            'source_ref' => null,
        ];
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

    private static function natural(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > 1_000_000_000_000) {
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

    private static function tag(mixed $value, string $label): string
    {
        if (
            !is_string($value)
            || trim($value) !== $value
            || $value === ''
            || strlen($value) > 200
            || str_contains($value, '..')
            || str_contains($value, '@{')
            || str_starts_with($value, '-')
            || str_ends_with($value, '.')
            || preg_match('/[\\x00-\\x20\\x7f~^:?*\\[\\]\\\\]/', $value) === 1
        ) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }

    private static function targetType(mixed $value, string $label): string
    {
        if (!is_string($value) || !in_array($value, ['tag', 'commit'], true)) {
            throw new RuntimeException($label . ' invalid.');
        }
        return $value;
    }
}

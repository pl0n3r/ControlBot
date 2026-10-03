<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use InvalidArgumentException;
use RuntimeException;

final class GitHubPrEvidence
{
    private const LIMIT = 25;
    private const REVIEW_STATES = [
        'APPROVED',
        'CHANGES_REQUESTED',
        'COMMENTED',
        'DISMISSED',
        'PENDING',
    ];

    public function __construct(private readonly ApiClient $api) {}

    public function collect(string $repository, int $observedAt): array
    {
        if ($observedAt < 1) {
            throw new InvalidArgumentException('observed_at invalid.');
        }

        $base = Gateway::repoPath($repository);
        $rows = $this->api->json(
            'GET',
            $base . '/pulls',
            null,
            [200],
            ['state' => 'open', 'per_page' => self::LIMIT],
        );
        self::rows($rows, 'pull_requests');

        $items = [];
        foreach ($rows as $row) {
            $row = self::object($row, 'pull_request');
            $number = self::natural($row['number'] ?? null, 'pull_request.number');
            $title = self::text($row['title'] ?? null, 'pull_request.title', 300);
            $draft = $row['draft'] ?? null;
            if (!is_bool($draft)) {
                throw new RuntimeException('pull_request.draft invalid.');
            }

            $head = self::object($row['head'] ?? null, 'pull_request.head');
            $headSha = self::sha($head['sha'] ?? null, 'pull_request.head_sha');

            $detail = $this->api->json('GET', $base . "/pulls/{$number}", null, [200]);
            $detail = self::object($detail, 'pull_request.detail');
            if (
                self::natural($detail['number'] ?? null, 'pull_request.detail.number') !== $number
                || self::sha(
                    self::object($detail['head'] ?? null, 'pull_request.detail.head')['sha'] ?? null,
                    'pull_request.detail.head_sha',
                ) !== $headSha
            ) {
                throw new RuntimeException('pull_request.detail identity mismatch.');
            }

            $reviews = $this->api->json(
                'GET',
                $base . "/pulls/{$number}/reviews",
                null,
                [200],
                ['per_page' => self::LIMIT],
            );
            self::rows($reviews, 'pull_request.reviews');

            $items[] = [
                'number' => $number,
                'title' => $title,
                'draft' => $draft,
                'head_sha' => $headSha,
                'mergeability' => self::mergeability($detail),
                'reviews' => self::reviews($reviews, $headSha),
                'source_ref' => "github:{$repository}#pull:{$number}@{$headSha}:{$observedAt}",
            ];
        }

        return [
            'version' => 1,
            'repository' => $repository,
            'observed_at' => $observedAt,
            'items' => $items,
            'truncated' => count($rows) >= self::LIMIT,
        ];
    }

    private static function mergeability(array $detail): array
    {
        $mergeable = $detail['mergeable'] ?? null;
        $mergeableState = $detail['mergeable_state'] ?? null;

        if (
            !is_bool($mergeable)
            || !is_string($mergeableState)
            || trim($mergeableState) === ''
            || strlen($mergeableState) > 80
        ) {
            return [
                'state' => 'UNKNOWN',
                'mergeable' => null,
                'github_state' => is_string($mergeableState) && trim($mergeableState) !== ''
                    ? trim($mergeableState)
                    : null,
            ];
        }

        return [
            'state' => $mergeable ? 'MERGEABLE' : 'CONFLICTING',
            'mergeable' => $mergeable,
            'github_state' => trim($mergeableState),
        ];
    }

    private static function reviews(array $rows, string $headSha): array
    {
        $items = [];
        $ambiguous = count($rows) >= self::LIMIT;

        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $ambiguous = true;
                continue;
            }

            $user = $row['user'] ?? null;
            $login = is_array($user) && !array_is_list($user)
                ? ($user['login'] ?? null)
                : null;
            $state = $row['state'] ?? null;
            $commitId = $row['commit_id'] ?? null;

            if (
                !is_string($login)
                || trim($login) === ''
                || strlen($login) > 100
                || !is_string($state)
                || !in_array($state, self::REVIEW_STATES, true)
            ) {
                $ambiguous = true;
                continue;
            }

            $current = null;
            if (is_string($commitId) && preg_match('/^[0-9a-f]{40}$/D', $commitId) === 1) {
                $current = hash_equals($headSha, $commitId);
            } else {
                $ambiguous = true;
                $commitId = null;
            }

            $items[] = [
                'author' => trim($login),
                'state' => $state,
                'commit_id' => $commitId,
                'head_matches' => $current,
            ];
        }

        return [
            'evidence_state' => $ambiguous ? 'UNKNOWN' : 'COMPLETE',
            'items' => $items,
            'truncated' => count($rows) >= self::LIMIT,
        ];
    }

    private static function rows(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::LIMIT) {
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
        if (!is_int($value) || $value < 1 || $value > 1_000_000_000) {
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
}

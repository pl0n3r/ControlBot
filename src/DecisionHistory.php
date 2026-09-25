<?php
declare(strict_types=1);

namespace ControlBot\Decisions;

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\GitHub\Gateway;
use InvalidArgumentException;
use RuntimeException;

final class DecisionHistory
{
    private const CATEGORIES = [
        'product-direction', 'brand', 'money', 'legal',
        'real-customer-data', 'release-1.0.0', 'factory-release', 'go-live',
    ];
    private const ACTIONS = ['preflight-sha', 'comment', 'move-v1', 'dispatch-release', 'close-issue'];

    private array $repositories;

    public function __construct(
        private readonly AppendOnlyAuditLog $audit,
        array $repositories,
    ) {
        if ($repositories === [] || count($repositories) > 20) {
            throw new InvalidArgumentException('Allowlist de historial inválida.');
        }
        $normalized = [];
        foreach ($repositories as $repository) {
            if (!is_string($repository)) {
                throw new InvalidArgumentException('Allowlist de historial inválida.');
            }
            Gateway::repoPath($repository);
            $normalized[$repository] = true;
        }
        $this->repositories = array_keys($normalized);
    }

    public function load(?string $repository = null, ?string $category = null): array
    {
        if ($repository !== null && !in_array($repository, $this->repositories, true)) {
            throw new InvalidArgumentException('Repositorio de historial fuera de allowlist.');
        }
        if ($category !== null && !in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Categoría de historial inválida.');
        }

        $groups = [];
        foreach ($this->audit->entries() as $entry) {
            $row = self::normalize($entry);
            if (!in_array($row['repository'], $this->repositories, true)) {
                continue;
            }
            if ($repository !== null && $row['repository'] !== $repository) {
                continue;
            }
            if ($category !== null && $row['category'] !== $category) {
                continue;
            }

            $key = implode('|', [$row['repository'], (string) $row['issue'], $row['option'], (string) $row['at']]);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'repository' => $row['repository'],
                    'issue' => $row['issue'],
                    'category' => $row['category'],
                    'option' => $row['option'],
                    'sha' => $row['sha'],
                    'result' => 'success',
                    'at' => $row['at'],
                    'actions' => [],
                    'evidence' => [],
                ];
            }

            $groups[$key]['actions'][] = $row['action'];
            if ($row['result'] !== 'success') {
                $groups[$key]['result'] = $row['result'];
            }
            if ($groups[$key]['sha'] === null && $row['sha'] !== null) {
                $groups[$key]['sha'] = $row['sha'];
            }
            if ($row['evidence'] !== null) {
                $groups[$key]['evidence'][] = $row['evidence'];
            }
        }

        $history = array_values($groups);
        foreach ($history as &$item) {
            $item['actions'] = array_values(array_unique($item['actions']));
            $item['evidence'] = array_values(array_unique($item['evidence']));
        }
        unset($item);

        usort($history, static function (array $left, array $right): int {
            if ($left['at'] !== $right['at']) {
                return $right['at'] <=> $left['at'];
            }
            return [$left['repository'], $left['issue'], $left['option']]
                <=> [$right['repository'], $right['issue'], $right['option']];
        });
        return $history;
    }

    private static function normalize(array $entry): array
    {
        $repository = $entry['repository'] ?? null;
        $issue = $entry['issue'] ?? null;
        $option = $entry['option'] ?? null;
        $action = $entry['action'] ?? null;
        $result = $entry['result'] ?? null;
        $at = $entry['at'] ?? null;
        $category = $entry['category'] ?? 'unknown';
        $sha = $entry['sha'] ?? null;
        $evidence = $entry['evidence'] ?? null;

        if (
            !is_string($repository)
            || !is_int($issue) || $issue < 1
            || !is_string($option) || preg_match('/^[A-D]$/', $option) !== 1
            || !is_string($action) || !in_array($action, self::ACTIONS, true)
            || !is_string($result) || !in_array($result, ['success', 'failed', 'blocked'], true)
            || !is_int($at) || $at < 1
            || !is_string($category) || ($category !== 'unknown' && !in_array($category, self::CATEGORIES, true))
            || ($sha !== null && (!is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1))
            || ($evidence !== null && !is_string($evidence))
        ) {
            throw new RuntimeException('Entrada de historial inválida.');
        }

        $safeEvidence = null;
        if ($evidence !== null) {
            $parts = parse_url($evidence);
            if (is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'github.com') {
                $safeEvidence = $evidence;
            }
        }

        return [
            'repository' => $repository,
            'issue' => $issue,
            'category' => $category,
            'option' => $option,
            'sha' => $sha,
            'action' => $action,
            'result' => $result,
            'evidence' => $safeEvidence,
            'at' => $at,
        ];
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Search;

use InvalidArgumentException;

final class GlobalSearchCore
{
    private const TYPES = ['project', 'repository', 'issue', 'pull_request', 'decision', 'lesson', 'incident', 'work_item', 'release'];
    private const SOURCES = ['github', 'controlbot', 'factory'];
    private const FRESHNESS = ['fresh', 'stale', 'unavailable'];
    private const ACCESS = ['allow', 'deny', 'unknown'];
    private const FRESHNESS_RANK = ['fresh' => 0, 'stale' => 1, 'unavailable' => 2];

    public static function search(array $queryRaw, array $documentsRaw): array
    {
        $query = self::query($queryRaw);
        self::documents($documentsRaw);
        $matched = [];
        foreach ($documentsRaw as $raw) {
            $document = self::document($raw);
            if ($document === null || !self::filters($document, $query)) {
                continue;
            }
            $score = self::match($document, $query['text']);
            if ($score === null) {
                continue;
            }
            $document['_score'] = $score;
            $matched[] = $document;
        }

        usort($matched, self::compare(...));
        $items = self::deduplicate($matched);
        $total = count($items);
        $offset = ($query['page'] - 1) * $query['per_page'];
        $items = array_slice($items, $offset, $query['per_page']);
        foreach ($items as &$item) {
            unset($item['_score']);
        }
        unset($item);

        return ['version' => 1, 'items' => $items, 'total' => $total, 'page' => $query['page'],
            'per_page' => $query['per_page'], 'has_more' => $offset + count($items) < $total];
    }
    private static function documents(array $documents): void
    {
        if (!array_is_list($documents) || count($documents) > 1000) {
            throw new InvalidArgumentException('documents invalid.');
        }
    }
    private static function query(array $raw): array
    {
        self::fields($raw, ['version', 'text', 'project', 'type', 'state', 'role', 'page', 'per_page'], 'SearchQuery');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('SearchQuery version invalid.');
        }
        return ['text' => self::plain($raw['text'], 'text', 120, false),
            'project' => self::nullableSlug($raw['project'], 'project'),
            'type' => $raw['type'] === null ? null : self::choice($raw['type'], self::TYPES, 'type'),
            'state' => self::nullableSlug($raw['state'], 'state'), 'role' => self::nullableSlug($raw['role'], 'role'),
            'page' => self::positive($raw['page'], 'page', 100000),
            'per_page' => self::positive($raw['per_page'], 'per_page', 100)];
    }
    private static function document(mixed $raw): ?array
    {
        $fields = ['version', 'type', 'title', 'project', 'repo', 'number_or_id', 'state', 'updated_at', 'snippet',
            'source', 'canonical_url', 'freshness', 'roles', 'access'];
        self::fields($raw, $fields, 'SearchDocument');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('SearchDocument version invalid.');
        }
        $access = self::choice($raw['access'], self::ACCESS, 'access');
        if ($access !== 'allow') {
            return null;
        }
        return ['type' => self::choice($raw['type'], self::TYPES, 'type'),
            'title' => self::sanitize(self::plain($raw['title'], 'title', 240, false)),
            'project' => self::slug($raw['project'], 'project'), 'repo' => self::repo($raw['repo']),
            'number_or_id' => self::numberOrId($raw['number_or_id']), 'state' => self::slug($raw['state'], 'state'),
            'updated_at' => self::positive($raw['updated_at'], 'updated_at', PHP_INT_MAX),
            'snippet' => self::sanitize(self::plain($raw['snippet'], 'snippet', 600, true)),
            'source' => self::choice($raw['source'], self::SOURCES, 'source'), 'canonical_url' => self::url($raw['canonical_url']),
            'freshness' => self::choice($raw['freshness'], self::FRESHNESS, 'freshness'), 'roles' => self::roles($raw['roles'])];
    }
    private static function numberOrId(mixed $value): int|string
    {
        if (!is_int($value)) {
            return self::plain($value, 'number_or_id', 96, false);
        }
        if ($value < 1) {
            throw new InvalidArgumentException('number_or_id invalid.');
        }
        return $value;
    }
    private static function filters(array $document, array $query): bool
    {
        if ($query['project'] !== null && $document['project'] !== $query['project']) {
            return false;
        }
        if ($query['type'] !== null && $document['type'] !== $query['type']) {
            return false;
        }
        if ($query['state'] !== null && $document['state'] !== $query['state']) {
            return false;
        }
        return $query['role'] === null || in_array($query['role'], $document['roles'], true);
    }
    private static function match(array $document, string $text): ?int
    {
        if (preg_match('/^#([1-9][0-9]*)$/D', $text, $matches) === 1) {
            return (string) $document['number_or_id'] === $matches[1] ? 0 : null;
        }
        $needle = preg_quote($text, '/');
        if (preg_match('/^' . $needle . '$/iu', (string) $document['number_or_id']) === 1) {
            return 0;
        }
        if (preg_match('/^' . $needle . '$/iu', $document['title']) === 1) {
            return 1;
        }
        if (preg_match('/' . $needle . '/iu', $document['title']) === 1) {
            return 2;
        }
        return preg_match('/' . $needle . '/iu', $document['snippet']) === 1 ? 3 : null;
    }
    private static function compare(array $left, array $right): int
    {
        return [$left['_score'], self::FRESHNESS_RANK[$left['freshness']], -$left['updated_at'], $left['canonical_url'],
            $left['title'], $left['snippet'], $left['repo'], $left['source'], (string) $left['number_or_id'], implode(',', $left['roles'])]
            <=> [$right['_score'], self::FRESHNESS_RANK[$right['freshness']], -$right['updated_at'], $right['canonical_url'],
                $right['title'], $right['snippet'], $right['repo'], $right['source'], (string) $right['number_or_id'], implode(',', $right['roles'])];
    }
    private static function deduplicate(array $documents): array
    {
        $deduplicated = [];
        foreach ($documents as $document) {
            $url = $document['canonical_url'];
            if (!isset($deduplicated[$url])) {
                $deduplicated[$url] = $document;
            }
        }
        return array_values($deduplicated);
    }
    private static function sanitize(string $text): string
    {
        $text = preg_replace('/bearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $text) ?? '';
        $credential = '/(?<![A-Za-z0-9_])"?(password|passwd|token|secret|api[_-]?key|authorization)"?'
            . '\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;}]+)/i';
        $text = preg_replace($credential, '$1=[REDACTED]', $text) ?? '';
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $text) ?? '';
        return preg_replace('/\+?[0-9][0-9(). -]{7,}[0-9]/', '[REDACTED_PHONE]', $text) ?? '';
    }
    private static function roles(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 32) {
            throw new InvalidArgumentException('roles invalid.');
        }
        $roles = [];
        foreach ($rows as $row) {
            $roles[] = self::slug($row, 'role');
        }
        $roles = array_values(array_unique($roles));
        sort($roles, SORT_STRING);
        return $roles;
    }
    private static function url(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 300) {
            throw new InvalidArgumentException('canonical_url invalid.');
        }
        $internal = preg_match('#^/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#D', $value) === 1;
        $github = preg_match('#^https://github\.com/pl0n3r/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+$#D', $value) === 1;
        $path = $internal ? $value : ($github ? (string) parse_url($value, PHP_URL_PATH) : '');
        if ($path === '' || array_intersect(explode('/', $path), ['.', '..'])) {
            throw new InvalidArgumentException('canonical_url invalid.');
        }
        return $value;
    }
    private static function repo(mixed $value): string
    {
        if (!is_string($value) || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $value) !== 1) {
            throw new InvalidArgumentException('repo invalid.');
        }
        return $value;
    }
    private static function nullableSlug(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::slug($value, $label);
    }
    private static function slug(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9_.:-]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
    private static function plain(mixed $value, string $label, int $max, bool $allowEmpty): string
    {
        $valid = is_string($value) && strlen($value) <= $max && ($allowEmpty || trim($value) !== '')
            && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
        if (!$valid) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }
    private static function positive(mixed $value, string $label, int $max): int
    {
        if (!is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
    private static function choice(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
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

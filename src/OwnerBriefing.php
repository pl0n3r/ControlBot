<?php
declare(strict_types=1);

namespace ControlBot\Briefing;

use InvalidArgumentException;

final class OwnerBriefing
{
    private const SECTIONS = ['delivered', 'today', 'broken', 'decisions', 'costs'];
    private const MAX_ITEMS = 50;
    private const VISIBLE_ITEMS = 3;
    private const MAX_SUMMARY = 200;

    public static function build(array $snapshot): array
    {
        foreach (array_keys($snapshot) as $key) {
            if (!is_string($key) || !in_array($key, self::SECTIONS, true)) {
                throw new InvalidArgumentException('Briefing field invalid.');
            }
        }

        $sections = [];
        foreach (self::SECTIONS as $section) {
            $sections[$section] = self::section($snapshot[$section] ?? null);
        }

        $needsAttention = self::needsAttention($sections['broken'])
            || self::needsAttention($sections['decisions']);

        return [
            'sections' => $sections,
            'needs_owner_attention' => $needsAttention,
            'attention_label' => $needsAttention ? 'Necesitas entrar' : 'No necesitas entrar',
        ];
    }

    private static function section(mixed $value): array
    {
        if ($value === null) {
            return [
                'status' => 'unknown',
                'items' => [],
                'total' => 0,
                'overflow_count' => 0,
            ];
        }

        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Briefing section invalid.');
        }

        if ($value === []) {
            return [
                'status' => 'empty',
                'items' => [],
                'total' => 0,
                'overflow_count' => 0,
            ];
        }

        $items = array_map([self::class, 'item'], $value);
        $total = count($items);

        return [
            'status' => 'real',
            'items' => array_slice($items, 0, self::VISIBLE_ITEMS),
            'total' => $total,
            'overflow_count' => max(0, $total - self::VISIBLE_ITEMS),
        ];
    }

    private static function item(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Briefing item invalid.');
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['evidence', 'summary']) {
            throw new InvalidArgumentException('Briefing item invalid.');
        }

        return [
            'summary' => self::safeSummary($value['summary']),
            'evidence' => self::safeEvidence($value['evidence']),
        ];
    }

    private static function safeSummary(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Briefing summary invalid.');
        }

        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > self::MAX_SUMMARY
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1
            || preg_match(
                '/(?:-----BEGIN [^-]*PRIVATE KEY-----|\b(?:bearer\s+[A-Za-z0-9._~+\/-]{8,}|(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)\s*[:=]\s*\S+|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}))/i',
                $value
            ) === 1
        ) {
            throw new InvalidArgumentException('Briefing summary invalid.');
        }

        return $value;
    }

    private static function safeEvidence(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 512) {
            throw new InvalidArgumentException('Briefing evidence invalid.');
        }

        if (str_starts_with($value, 'controlbot:')) {
            if (
                preg_match('/^controlbot:[A-Za-z0-9][A-Za-z0-9._:\/#@-]{0,479}$/D', $value) !== 1
                || preg_match('/(?:token|secret|password|passwd|cookie|authorization|private[_-]?key|api[_-]?key|dsn)/i', $value) === 1
            ) {
                throw new InvalidArgumentException('Briefing evidence invalid.');
            }
            return $value;
        }

        $parts = parse_url($value);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'github.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !is_string($parts['path'] ?? null)
            || $parts['path'] === '/'
        ) {
            throw new InvalidArgumentException('Briefing evidence invalid.');
        }

        $decoded = $parts['path'];
        for ($i = 0; $i < 3 && str_contains($decoded, '%'); $i++) {
            $decoded = rawurldecode($decoded);
        }
        if (
            str_contains($decoded, '%')
            || in_array('.', explode('/', $decoded), true)
            || in_array('..', explode('/', $decoded), true)
            || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1
            || preg_match('/(?:^|[:\/._-])(?:token|secret|password|passwd|cookie|authorization|private[_-]?key|api[_-]?key|dsn)(?:$|[:\/._=-])/i', $decoded) === 1
        ) {
            throw new InvalidArgumentException('Briefing evidence invalid.');
        }

        return $value;
    }

    private static function needsAttention(array $section): bool
    {
        return $section['status'] === 'unknown'
            || ($section['status'] === 'real' && $section['total'] > 0);
    }
}

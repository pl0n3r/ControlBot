<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryLiveSnapshot
{
    private const SECTION_AUTHORITIES = [
        'batches' => 'factory_plan',
        'owner_decisions' => 'owner_inbox',
        'releases' => 'github_project_snapshot',
        'blockers' => 'github_project_snapshot',
        'production' => 'observability_project_status',
        'quality' => 'quality_health',
        'work' => 'github_project_snapshot',
        'learning' => 'incident_lesson',
    ];
    private const STATES = ['healthy','degraded','critical','unknown','blocked','pending'];
    private const FRESHNESS = ['current','stale','unknown'];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const DIRECT_PII = '/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';
    private const MAX_SIGNALS = 50;

    public static function build(array $raw, int $now): array
    {
        if ($now < 1 || array_is_list($raw)) {
            throw new InvalidArgumentException('Factory live snapshot input invalid.');
        }
        $allowed = [...array_keys(self::SECTION_AUTHORITIES), 'tool_usage'];
        foreach (array_keys($raw) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Factory live snapshot field invalid.');
            }
        }

        $sections = [];
        foreach (self::SECTION_AUTHORITIES as $section => $authority) {
            $sections[$section] = self::section(
                $raw[$section] ?? null,
                $section,
                $authority,
                $now,
            );
        }

        $toolUsage = $raw['tool_usage'] ?? null;
        $toolUsage = $toolUsage === null
            ? self::unknown('tool_usage', 'tool_usage')
            : self::signal($toolUsage, 'tool_usage', 'tool_usage', $now);

        $canonical = [
            'version' => 1,
            'observed_at' => $now,
            'sections' => $sections,
            'tool_usage' => $toolUsage,
        ];
        self::secretFree($canonical);

        return $canonical + [
            'fingerprint' => hash(
                'sha256',
                json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ),
        ];
    }

    private static function section(
        mixed $raw,
        string $section,
        string $authority,
        int $now,
    ): array {
        if ($raw === null || $raw === []) {
            return [self::unknown($section, $authority)];
        }
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_SIGNALS) {
            throw new InvalidArgumentException($section.' signals invalid.');
        }

        $out = [];
        $seen = [];
        foreach ($raw as $row) {
            $signal = self::signal($row, $section, $authority, $now);
            if (isset($seen[$signal['id']])) {
                throw new InvalidArgumentException($section.' signal duplicated.');
            }
            $seen[$signal['id']] = true;
            $out[] = $signal;
        }
        usort($out, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

        return $out;
    }

    private static function signal(
        mixed $raw,
        string $section,
        string $authority,
        int $now,
    ): array {
        self::fields(
            $raw,
            ['id','authority','state','source_ref','observed_at','freshness','data'],
            $section.' signal',
        );
        if ($raw['authority'] !== $authority) {
            throw new InvalidArgumentException($section.' authority mismatch.');
        }

        $id = self::id($raw['id'], $section.'.id');
        $state = self::choice($raw['state'], self::STATES, $section.'.state');
        $freshness = self::choice(
            $raw['freshness'],
            self::FRESHNESS,
            $section.'.freshness',
        );
        $sourceRef = self::nullableRef($raw['source_ref'], $section.'.source_ref');
        $observedAt = self::nullableTime(
            $raw['observed_at'],
            $section.'.observed_at',
            $now,
        );

        if ($freshness === 'unknown') {
            if ($sourceRef !== null || $observedAt !== null || $state !== 'unknown') {
                throw new InvalidArgumentException($section.' unknown signal incoherent.');
            }
        } elseif ($sourceRef === null || $observedAt === null) {
            throw new InvalidArgumentException($section.' observed signal lacks provenance.');
        }
        if ($freshness === 'stale' && $state === 'healthy') {
            throw new InvalidArgumentException($section.' stale signal cannot be healthy.');
        }

        $data = self::data($raw['data'], $section.'.data', 0);
        if ($section === 'owner_decisions' && $freshness !== 'unknown') {
            self::decisionIssueRef($data['issue_ref'] ?? null);
        }

        return [
            'id' => $id,
            'authority' => $authority,
            'state' => $state,
            'source_ref' => $sourceRef,
            'observed_at' => $observedAt,
            'freshness' => $freshness,
            'age_seconds' => $observedAt === null ? null : $now - $observedAt,
            'data' => $data,
        ];
    }

    private static function unknown(string $section, string $authority): array
    {
        return [
            'id' => $section.':unknown',
            'authority' => $authority,
            'state' => 'unknown',
            'source_ref' => null,
            'observed_at' => null,
            'freshness' => 'unknown',
            'age_seconds' => null,
            'data' => [],
        ];
    }

    private static function data(mixed $value, string $label, int $depth): mixed
    {
        if ($depth > 4) {
            throw new InvalidArgumentException($label.' too deep.');
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException($label.' float invalid.');
            }
            return $value;
        }
        if (is_string($value)) {
            return self::safeText($value, $label, 500);
        }
        if (!is_array($value) || count($value) > self::MAX_SIGNALS) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        if (array_is_list($value)) {
            return array_map(
                fn(mixed $item): mixed => self::data($item, $label, $depth + 1),
                $value,
            );
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)
                || preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $key) !== 1
                || preg_match(self::SENSITIVE, $key) === 1) {
                throw new InvalidArgumentException($label.' key invalid.');
            }
            $out[$key] = self::data($item, $label, $depth + 1);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private static function decisionIssueRef(mixed $value): void
    {
        if (!is_string($value)
            || preg_match(
                '~^(?:https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*|github:[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#[1-9][0-9]*)$~D',
                $value,
            ) !== 1) {
            throw new InvalidArgumentException('Owner decision issue_ref invalid.');
        }
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9._:\/#-]{2,160}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return self::safeText($value, $label, 180);
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::safeText($value, $label, 240);
    }

    private static function nullableTime(mixed $value, string $label, int $now): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < 1 || $value > $now) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function safeText(mixed $value, string $label, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/u', $value) === 1
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match(self::DIRECT_PII, $value) === 1) {
            throw new InvalidArgumentException($label.' unsafe.');
        }
        return $value;
    }

    private static function choice(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $value;
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label.' fields invalid.');
        }
    }

    private static function secretFree(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::secretFree($item);
            }
            return;
        }
        if (is_string($value)
            && (preg_match(self::SENSITIVE, $value) === 1
                || preg_match(self::DIRECT_PII, $value) === 1)) {
            throw new InvalidArgumentException('Factory live snapshot contains sensitive material.');
        }
    }
}

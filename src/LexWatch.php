<?php
declare(strict_types=1);

namespace ControlBot\Legal;

use InvalidArgumentException;

final class LexWatch
{
    private const SOURCE_STATES = ['verified', 'rumor', 'unknown'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const IMPACT_STATES = ['plausible', 'none', 'unknown'];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code|session|credential)/i';

    public static function normalize(array $input, int $now): array
    {
        self::fields($input, ['version', 'signals'], 'legal watch');
        if (($input['version'] ?? null) !== 1 || $now < 1) {
            throw new InvalidArgumentException('legal watch version/now invalid.');
        }

        $signals = self::signals($input['signals'] ?? null, $now);
        $candidates = [];
        foreach ($signals as $signal) {
            if (!$signal['review_candidate']) {
                continue;
            }
            $candidates[] = [
                'kind' => 'legal_review',
                'jurisdiction' => $signal['jurisdiction'],
                'regulation_ref' => $signal['regulation_ref'],
                'change_ref' => $signal['change_ref'],
                'impact_refs' => $signal['impact_refs'],
                'source_state' => $signal['source_state'],
                'source_refs' => $signal['source_refs'],
                'evidence_refs' => $signal['evidence_refs'],
                'published_at' => $signal['published_at'],
                'effective_at' => $signal['effective_at'],
                'observed_at' => $signal['observed_at'],
                'freshness' => $signal['freshness'],
                'publication_state' => $signal['publication_state'],
                'effective_state' => $signal['effective_state'],
            ];
        }

        return [
            'version' => 1,
            'signals' => $signals,
            'review_candidates' => $candidates,
        ];
    }

    private static function signals(mixed $rows, int $now): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 300) {
            throw new InvalidArgumentException('regulatory signals invalid.');
        }

        $byKey = [];
        foreach ($rows as $row) {
            $signal = self::signal($row, $now);
            $key = self::dedupeKey($signal);

            if (!isset($byKey[$key])) {
                $byKey[$key] = $signal;
                continue;
            }

            $existing = $byKey[$key];
            foreach ([
                'jurisdiction',
                'regulation_ref',
                'change_ref',
                'source_state',
                'impact_state',
                'published_at',
                'effective_at',
                'freshness',
                'publication_state',
                'effective_state',
                'review_candidate',
            ] as $field) {
                if ($existing[$field] !== $signal[$field]) {
                    throw new InvalidArgumentException('duplicate regulatory signal conflict.');
                }
            }

            $existing['signal_ids'] = self::merged($existing['signal_ids'], $signal['signal_ids']);
            $existing['source_refs'] = self::merged($existing['source_refs'], $signal['source_refs']);
            $existing['evidence_refs'] = self::merged($existing['evidence_refs'], $signal['evidence_refs']);
            $existing['observed_at'] = max($existing['observed_at'], $signal['observed_at']);
            $byKey[$key] = $existing;
        }

        ksort($byKey, SORT_STRING);
        return array_values($byKey);
    }

    private static function signal(mixed $row, int $now): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('regulatory signal invalid.');
        }
        self::fields($row, [
            'signal_id',
            'jurisdiction',
            'regulation_ref',
            'change_ref',
            'source_state',
            'source_refs',
            'evidence_refs',
            'impact_state',
            'impact_refs',
            'published_at',
            'effective_at',
            'observed_at',
            'freshness',
        ], 'regulatory signal');

        $signalId = self::id($row['signal_id'] ?? null, 'signal_id');
        $jurisdiction = self::jurisdiction($row['jurisdiction'] ?? null);
        $regulationRef = self::ref($row['regulation_ref'] ?? null, 'regulation_ref');
        $changeRef = self::ref($row['change_ref'] ?? null, 'change_ref');
        $sourceState = self::enum($row['source_state'] ?? null, self::SOURCE_STATES, 'source_state');
        $sourceRefs = self::refs($row['source_refs'] ?? null, 'source_refs', false);
        $evidenceRefs = self::refs($row['evidence_refs'] ?? null, 'evidence_refs', true);
        $impactState = self::enum($row['impact_state'] ?? null, self::IMPACT_STATES, 'impact_state');
        $impactRefs = self::refs($row['impact_refs'] ?? null, 'impact_refs', true);
        $publishedAt = self::nullableTime($row['published_at'] ?? null, 'published_at');
        $effectiveAt = self::nullableTime($row['effective_at'] ?? null, 'effective_at');
        $observedAt = self::time($row['observed_at'] ?? null, 'observed_at');
        $freshness = self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness');

        if ($observedAt > $now) {
            throw new InvalidArgumentException('observed_at in the future.');
        }
        if ($publishedAt !== null && ($publishedAt > $observedAt || $publishedAt > $now)) {
            throw new InvalidArgumentException('published_at after observation.');
        }
        if ($effectiveAt !== null && $publishedAt !== null && $effectiveAt < $publishedAt) {
            throw new InvalidArgumentException('effective_at before published_at.');
        }

        if ($sourceState === 'verified') {
            if ($publishedAt === null || $evidenceRefs === []) {
                throw new InvalidArgumentException('verified source requires publication and evidence.');
            }
        } elseif ($publishedAt !== null || $effectiveAt !== null) {
            throw new InvalidArgumentException('unverified source cannot assert publication dates.');
        }

        if ($impactState === 'plausible' && $impactRefs === []) {
            throw new InvalidArgumentException('plausible impact requires impact refs.');
        }
        if ($impactState === 'none' && $impactRefs !== []) {
            throw new InvalidArgumentException('no impact cannot carry impact refs.');
        }

        $trusted = $sourceState === 'verified' && $freshness === 'fresh';
        $publicationState = $trusted ? 'published' : 'unknown';
        if (!$trusted || $publishedAt === null || $effectiveAt === null) {
            $effectiveState = 'unknown';
        } elseif ($effectiveAt <= $now) {
            $effectiveState = 'effective';
        } else {
            $effectiveState = 'not_yet_effective';
        }

        $reviewCandidate = $trusted
            && $publicationState === 'published'
            && $impactState === 'plausible'
            && $impactRefs !== [];

        return [
            'signal_ids' => [$signalId],
            'jurisdiction' => $jurisdiction,
            'regulation_ref' => $regulationRef,
            'change_ref' => $changeRef,
            'source_state' => $sourceState,
            'source_refs' => $sourceRefs,
            'evidence_refs' => $evidenceRefs,
            'impact_state' => $impactState,
            'impact_refs' => $impactRefs,
            'published_at' => $publishedAt,
            'effective_at' => $effectiveAt,
            'observed_at' => $observedAt,
            'freshness' => $freshness,
            'publication_state' => $publicationState,
            'effective_state' => $effectiveState,
            'review_candidate' => $reviewCandidate,
        ];
    }

    private static function dedupeKey(array $signal): string
    {
        return implode('|', [
            $signal['jurisdiction'],
            $signal['regulation_ref'],
            $signal['change_ref'],
            $signal['impact_state'],
            implode(',', $signal['impact_refs']),
        ]);
    }

    private static function merged(array $left, array $right): array
    {
        $values = array_values(array_unique(array_merge($left, $right)));
        sort($values, SORT_STRING);
        return $values;
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        $actual = array_keys($row);
        if (array_diff($expected, $actual) !== [] || array_diff($actual, $expected) !== []) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function jurisdiction(mixed $value): string
    {
        return self::checkedString(
            $value,
            'jurisdiction',
            2,
            12,
            '/^[A-Z][A-Z0-9-]{1,11}$/D',
        );
    }

    private static function id(mixed $value, string $label): string
    {
        return self::checkedString(
            $value,
            $label,
            1,
            120,
            '/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D',
        );
    }

    private static function refs(mixed $value, string $label, bool $allowEmpty): array
    {
        if (!is_array($value)
            || !array_is_list($value)
            || count($value) > 50
            || (!$allowEmpty && $value === [])) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $out = [];
        foreach ($value as $ref) {
            $out[self::ref($ref, $label)] = true;
        }
        $refs = array_keys($out);
        sort($refs, SORT_STRING);
        return $refs;
    }

    private static function ref(mixed $value, string $label): string
    {
        $ref = self::checkedString(
            $value,
            $label,
            8,
            220,
            '#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\\#-]+$#D',
        );
        if (str_contains($ref, '@') || str_contains($ref, '..')) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $ref;
    }

    private static function checkedString(
        mixed $value,
        string $label,
        int $minLength,
        int $maxLength,
        string $pattern,
    ): string {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $length = strlen($value);
        if ($length < $minLength
            || $length > $maxLength
            || preg_match($pattern, $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function time(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableTime(mixed $value, string $label): ?int
    {
        return $value === null ? null : self::time($value, $label);
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
}

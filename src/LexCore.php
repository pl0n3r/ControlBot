<?php
declare(strict_types=1);

namespace ControlBot\Legal;

use InvalidArgumentException;

final class LexCore
{
    private const STATES = ['compliant', 'gap', 'unknown', 'not_applicable'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];
    private const DOMAINS = [
        'privacy', 'consumer', 'contracts', 'marketing', 'intellectual_property',
        'saas_subscriptions', 'tax_signal', 'labor', 'ai_automation', 'providers',
        'retention_portability', 'international_transfers', 'sector_specific',
    ];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code|session|credential)/i';

    public static function registry(array $input, int $now): array
    {
        self::fields($input, ['version', 'scope', 'obligations'], 'lex registry');
        if (($input['version'] ?? null) !== 1 || $now < 1) {
            throw new InvalidArgumentException('lex registry version/now invalid.');
        }

        $scope = self::scope($input['scope'] ?? null);
        $obligations = self::obligations($input['obligations'] ?? null, $scope, $now);

        return [
            'version' => 1,
            'scope' => $scope,
            'obligations' => $obligations,
            'summary' => self::summary($obligations),
        ];
    }

    private static function obligations(mixed $rows, string $scope, int $now): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 500) {
            throw new InvalidArgumentException('legal obligations invalid.');
        }

        $byId = [];
        foreach ($rows as $row) {
            $item = self::obligation($row, $scope, $now);
            $id = $item['obligation_id'];
            if (isset($byId[$id]) && $byId[$id] !== $item) {
                throw new InvalidArgumentException('duplicate legal obligation conflict.');
            }
            $byId[$id] = $item;
        }

        ksort($byId, SORT_STRING);
        return array_values($byId);
    }

    private static function obligation(mixed $row, string $scope, int $now): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('legal obligation invalid.');
        }
        self::fields($row, [
            'obligation_id', 'scope', 'market_id', 'jurisdiction_pack_id', 'domain',
            'requirement_ref', 'reported_status', 'source_refs', 'evidence_refs',
            'observed_at', 'freshness', 'responsible_ref', 'reviewed_at', 'expires_at',
            'next_review_at', 'severity', 'human_review_required', 'justification_ref',
            'assumption_refs',
        ], 'legal obligation');

        $itemScope = self::scope($row['scope'] ?? null);
        if ($itemScope !== $scope) {
            throw new InvalidArgumentException('legal obligation scope mismatch.');
        }

        $reported = self::enum($row['reported_status'] ?? null, self::STATES, 'legal status');
        $freshness = self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness');
        $sourceRefs = self::refs($row['source_refs'] ?? null, 'source_refs', false);
        $evidenceRefs = self::refs($row['evidence_refs'] ?? null, 'evidence_refs', true);
        $assumptions = self::refs($row['assumption_refs'] ?? null, 'assumption_refs', true);
        $observedAt = self::time($row['observed_at'] ?? null, 'observed_at');
        $reviewedAt = self::time($row['reviewed_at'] ?? null, 'reviewed_at');
        $expiresAt = self::nullableTime($row['expires_at'] ?? null, 'expires_at');
        $nextReviewAt = self::nullableTime($row['next_review_at'] ?? null, 'next_review_at');
        $justificationRef = self::nullableRef($row['justification_ref'] ?? null, 'justification_ref');
        $humanReview = self::boolean($row['human_review_required'] ?? null, 'human_review_required');

        if ($reviewedAt < $observedAt) {
            throw new InvalidArgumentException('reviewed_at before observed_at.');
        }
        if ($expiresAt !== null && $expiresAt < $reviewedAt) {
            throw new InvalidArgumentException('expires_at before reviewed_at.');
        }
        if ($nextReviewAt !== null && $nextReviewAt < $reviewedAt) {
            throw new InvalidArgumentException('next_review_at before reviewed_at.');
        }
        if ($reported === 'compliant' && $evidenceRefs === []) {
            throw new InvalidArgumentException('compliant status requires evidence.');
        }
        if ($reported === 'not_applicable' && $justificationRef === null) {
            throw new InvalidArgumentException('not_applicable requires justification.');
        }

        $effectiveFreshness = $freshness;
        if ($expiresAt !== null && $expiresAt <= $now) {
            $effectiveFreshness = 'stale';
        }
        $status = $effectiveFreshness === 'fresh' ? $reported : 'unknown';

        return [
            'obligation_id' => self::id($row['obligation_id'] ?? null, 'obligation_id'),
            'scope' => $itemScope,
            'market_id' => self::nullableId($row['market_id'] ?? null, 'market_id'),
            'jurisdiction_pack_id' => self::nullableId($row['jurisdiction_pack_id'] ?? null, 'jurisdiction_pack_id'),
            'domain' => self::enum($row['domain'] ?? null, self::DOMAINS, 'domain'),
            'requirement_ref' => self::ref($row['requirement_ref'] ?? null, 'requirement_ref'),
            'status' => $status,
            'reported_status' => $reported,
            'source_refs' => $sourceRefs,
            'evidence_refs' => $evidenceRefs,
            'observed_at' => $observedAt,
            'freshness' => $effectiveFreshness,
            'reported_freshness' => $freshness,
            'responsible_ref' => self::ref($row['responsible_ref'] ?? null, 'responsible_ref'),
            'reviewed_at' => $reviewedAt,
            'expires_at' => $expiresAt,
            'next_review_at' => $nextReviewAt,
            'severity' => self::enum($row['severity'] ?? null, self::SEVERITIES, 'severity'),
            'human_review_required' => $humanReview,
            'justification_ref' => $justificationRef,
            'assumption_refs' => $assumptions,
        ];
    }

    private static function summary(array $obligations): array
    {
        $counts = array_fill_keys(self::STATES, 0);
        $humanReviewRequired = 0;
        foreach ($obligations as $item) {
            $counts[$item['status']]++;
            if ($item['human_review_required']) {
                $humanReviewRequired++;
            }
        }

        return [
            'counts' => $counts,
            'human_review_required' => $humanReviewRequired,
            'safe_to_claim_compliant' => $obligations !== []
                && $counts['unknown'] === 0
                && $counts['gap'] === 0
                && $humanReviewRequired === 0,
        ];
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        if (array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function scope(mixed $value): string
    {
        return self::validatedText(
            $value,
            'scope',
            '/^(?:group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D',
            80,
        );
    }

    private static function id(mixed $value, string $label): string
    {
        return self::validatedText(
            $value,
            $label,
            '/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,9}$/D',
            140,
        );
    }

    private static function nullableId(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::id($value, $label);
    }

    private static function validatedText(mixed $value, string $label, string $pattern, int $maxLength): string
    {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > $maxLength
            || preg_match($pattern, $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function refs(mixed $value, string $label, bool $allowEmpty): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50
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
        if (!is_string($value)
            || strlen($value) < 8
            || strlen($value) > 220
            || str_contains($value, '@')
            || str_contains($value, '..')
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\\#-]+$#D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::ref($value, $label);
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

    private static function boolean(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
}

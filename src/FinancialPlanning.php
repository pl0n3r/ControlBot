<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FinancialPlanning
{
    private const KINDS = ['actual', 'budget', 'target', 'forecast'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const CONFIDENCE = ['verified', 'estimated', 'unknown'];
    private const ATTRIBUTION_KINDS = ['revenue', 'shared_cost'];
    private const MAX_MINOR_UNITS = 9_000_000_000_000_000;
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|iban|account[_ -]?number)/i';

    public static function compare(array $input): array
    {
        self::fields($input, [
            'version', 'venture_id', 'period', 'currency',
            'actual', 'budget', 'target', 'forecast', 'attributions',
        ], 'planning');

        if (($input['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('planning version invalid.');
        }

        $venture = self::id($input['venture_id'] ?? null, 'venture_id');
        $period = self::period($input['period'] ?? null);
        $currency = self::currency($input['currency'] ?? null);

        $series = [];
        foreach (self::KINDS as $kind) {
            $series[$kind] = self::series($input[$kind] ?? null, $kind);
        }

        $actual = $series['actual'];
        $actualCompatible = self::compatible($actual, $venture, $period, $currency);

        $status = 'available';
        $reason = 'actual_available';
        if ($actual === null) {
            $status = 'unavailable';
            $reason = 'actual_missing';
        } elseif (!$actualCompatible) {
            $status = 'unavailable';
            $reason = 'actual_incompatible';
        } elseif ($actual['freshness'] === 'unknown') {
            $status = 'unknown';
            $reason = 'actual_freshness_unknown';
        }

        $variances = [];
        foreach (['budget', 'target', 'forecast'] as $kind) {
            $variances[$kind] = self::variance(
                $actual,
                $series[$kind],
                $kind,
                $venture,
                $period,
                $currency,
            );
        }

        return [
            'version' => 1,
            'venture_id' => $venture,
            'period' => $period,
            'currency' => $currency,
            'status' => $status,
            'reason' => $reason,
            'series' => $series,
            'variances' => $variances,
            'attributions' => self::attributions($input['attributions'] ?? null, $currency),
        ];
    }

    private static function variance(
        ?array $actual,
        ?array $other,
        string $kind,
        string $venture,
        string $period,
        string $currency,
    ): array {
        if ($actual === null) {
            return self::unavailableVariance($kind, 'actual_missing');
        }
        if (!self::compatible($actual, $venture, $period, $currency)) {
            return self::unavailableVariance($kind, 'actual_incompatible');
        }
        if ($actual['freshness'] === 'unknown') {
            return self::unavailableVariance($kind, 'actual_unknown');
        }
        if ($other === null) {
            return self::unavailableVariance($kind, $kind . '_missing');
        }
        if (!self::compatible($other, $venture, $period, $currency)) {
            return self::unavailableVariance($kind, $kind . '_incompatible');
        }
        if ($other['freshness'] === 'unknown') {
            return self::unavailableVariance($kind, $kind . '_unknown');
        }

        return [
            'status' => 'available',
            'base_kind' => 'actual',
            'compare_kind' => $kind,
            'delta_minor' => $actual['amount_minor'] - $other['amount_minor'],
        ];
    }

    private static function unavailableVariance(string $kind, string $reason): array
    {
        return [
            'status' => 'unavailable',
            'base_kind' => 'actual',
            'compare_kind' => $kind,
            'reason' => $reason,
            'delta_minor' => null,
        ];
    }

    private static function series(mixed $value, string $expectedKind): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException($expectedKind . ' series invalid.');
        }
        self::fields($value, [
            'kind', 'venture_id', 'period', 'currency', 'amount_minor',
            'source_ref', 'observed_at', 'as_of', 'freshness', 'confidence',
        ], $expectedKind);

        $kind = self::enum($value['kind'] ?? null, self::KINDS, 'series.kind');
        if ($kind !== $expectedKind) {
            throw new InvalidArgumentException('series kind mismatch.');
        }

        $observedAt = self::timestamp($value['observed_at'] ?? null, 'series.observed_at');
        $asOf = self::timestamp($value['as_of'] ?? null, 'series.as_of');
        if ($asOf > $observedAt) {
            throw new InvalidArgumentException('series as_of after observed_at.');
        }

        $freshness = self::enum($value['freshness'] ?? null, self::FRESHNESS, 'series.freshness');
        $confidence = self::enum($value['confidence'] ?? null, self::CONFIDENCE, 'series.confidence');
        if ($freshness !== 'fresh' && $confidence === 'verified') {
            throw new InvalidArgumentException('non-fresh series cannot be verified.');
        }

        return [
            'kind' => $kind,
            'venture_id' => self::id($value['venture_id'] ?? null, 'series.venture_id'),
            'period' => self::period($value['period'] ?? null),
            'currency' => self::currency($value['currency'] ?? null),
            'amount_minor' => self::money($value['amount_minor'] ?? null, 'series.amount_minor'),
            'source_ref' => self::sourceRef($value['source_ref'] ?? null),
            'observed_at' => $observedAt,
            'as_of' => $asOf,
            'freshness' => $freshness,
            'confidence' => $confidence,
        ];
    }

    private static function compatible(?array $series, string $venture, string $period, string $currency): bool
    {
        return $series !== null
            && $series['venture_id'] === $venture
            && $series['period'] === $period
            && $series['currency'] === $currency;
    }

    private static function attributions(mixed $value, string $currency): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new InvalidArgumentException('attributions invalid.');
        }

        $seen = [];
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('attribution invalid.');
            }
            self::fields($row, [
                'attribution_id', 'kind', 'currency', 'amount_minor',
                'target_scope', 'rule_ref', 'provenance_ref',
            ], 'attribution');

            $id = self::id($row['attribution_id'] ?? null, 'attribution_id');
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('attribution duplicated.');
            }
            $seen[$id] = true;

            $rowCurrency = self::currency($row['currency'] ?? null);
            if ($rowCurrency !== $currency) {
                throw new InvalidArgumentException('attribution currency mismatch.');
            }

            $target = self::nullableScope($row['target_scope'] ?? null);
            $rule = self::nullableRef($row['rule_ref'] ?? null, 'attribution.rule_ref');
            $provenance = self::nullableRef($row['provenance_ref'] ?? null, 'attribution.provenance_ref');
            $attributed = $target !== null && $rule !== null && $provenance !== null;

            $out[] = [
                'attribution_id' => $id,
                'kind' => self::enum($row['kind'] ?? null, self::ATTRIBUTION_KINDS, 'attribution.kind'),
                'currency' => $rowCurrency,
                'amount_minor' => self::money($row['amount_minor'] ?? null, 'attribution.amount_minor'),
                'status' => $attributed ? 'attributed' : 'unattributed',
                'target_scope' => $attributed ? $target : null,
                'rule_ref' => $attributed ? $rule : null,
                'provenance_ref' => $attributed ? $provenance : null,
            ];
        }

        usort($out, static fn(array $a, array $b): int => $a['attribution_id'] <=> $b['attribution_id']);
        return $out;
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || strlen($value) > 120
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function period(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $value, $m) !== 1) {
            throw new InvalidArgumentException('period invalid.');
        }
        $year = (int) $m[1];
        if ($year < 2000 || $year > 2200) {
            throw new InvalidArgumentException('period invalid.');
        }
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new InvalidArgumentException('currency invalid.');
        }
        return $value;
    }

    private static function money(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX_MINOR_UNITS) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function sourceRef(mixed $value): string
    {
        return self::ref($value, 'source_ref');
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value)
            || strlen($value) < 8
            || strlen($value) > 180
            || str_contains($value, '@')
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\#-]{1,178}$#D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::ref($value, $label);
    }

    private static function nullableScope(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
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

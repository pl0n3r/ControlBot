<?php
declare(strict_types=1);

namespace ControlBot\Business;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class VentureFinancialSnapshot
{
    private const INPUT_FIELDS = [
        'version', 'venture_id', 'period', 'currency',
        'revenue', 'refunds', 'direct_costs', 'operating_costs',
        'infrastructure_costs', 'ai_costs', 'cash_in', 'cash_out',
        'customers', 'transactions', 'revenue_streams',
        'source_ref', 'observed_at', 'freshness', 'confidence',
    ];

    private const STREAM_FIELDS = [
        'stream_id', 'revenue', 'refunds', 'customers', 'transactions',
    ];

    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const CONFIDENCE = ['verified', 'estimated', 'unknown'];

    private const CURRENCIES = [
        'AED','AFN','ALL','AMD','ANG','AOA','ARS','AUD','AWG','AZN',
        'BAM','BBD','BDT','BGN','BHD','BIF','BMD','BND','BOB','BOV','BRL','BSD','BTN','BWP','BYN','BZD',
        'CAD','CDF','CHE','CHF','CHW','CLF','CLP','CNY','COP','COU','CRC','CUC','CUP','CVE','CZK',
        'DJF','DKK','DOP','DZD','EGP','ERN','ETB','EUR','FJD','FKP',
        'GBP','GEL','GHS','GIP','GMD','GNF','GTQ','GYD','HKD','HNL','HTG','HUF',
        'IDR','ILS','INR','IQD','IRR','ISK','JMD','JOD','JPY','KES','KGS','KHR','KMF','KPW','KRW','KWD','KYD','KZT',
        'LAK','LBP','LKR','LRD','LSL','LYD','MAD','MDL','MGA','MKD','MMK','MNT','MOP','MRU','MUR','MVR','MWK','MXN','MXV','MYR','MZN',
        'NAD','NGN','NIO','NOK','NPR','NZD','OMR','PAB','PEN','PGK','PHP','PKR','PLN','PYG','QAR',
        'RON','RSD','RUB','RWF','SAR','SBD','SCR','SDG','SEK','SGD','SHP','SLE','SOS','SRD','SSP','STN','SVC','SYP','SZL',
        'THB','TJS','TMT','TND','TOP','TRY','TTD','TWD','TZS','UAH','UGX','USD','USN','UYI','UYU','UYW','UZS',
        'VED','VES','VND','VUV','WST','XAF','XAG','XAU','XBA','XBB','XBC','XBD','XCD','XDR','XOF','XPD','XPF','XPT','XSU','XTS','XUA','XXX',
        'YER','ZAR','ZMW','ZWL',
    ];

    private const MAX_MINOR_UNITS = 9_000_000_000_000_000;
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn|customer[_ -]?id|order[_ -]?id|payment[_ -]?id|iban|account[_ -]?number)/i';

    public static function normalize(array $input): array
    {
        self::exactKeys($input, self::INPUT_FIELDS, 'snapshot');

        if (($input['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Versión financiera no soportada.');
        }

        $venture = self::slug($input['venture_id'] ?? null, 'venture_id', 64);
        $period = self::period($input['period'] ?? null);
        $currency = self::currency($input['currency'] ?? null);

        $money = [];
        foreach ([
            'revenue', 'refunds', 'direct_costs', 'operating_costs',
            'infrastructure_costs', 'ai_costs', 'cash_in', 'cash_out',
        ] as $field) {
            $money[$field] = self::nonNegativeInteger($input[$field] ?? null, $field, self::MAX_MINOR_UNITS);
        }

        $customers = self::nonNegativeInteger($input['customers'] ?? null, 'customers', 1_000_000_000);
        $transactions = self::nonNegativeInteger($input['transactions'] ?? null, 'transactions', 10_000_000_000);

        $streams = self::streams($input['revenue_streams'] ?? null);
        if ($streams !== []) {
            $streamRevenue = array_sum(array_column($streams, 'revenue'));
            $streamRefunds = array_sum(array_column($streams, 'refunds'));
            if ($streamRevenue !== $money['revenue'] || $streamRefunds !== $money['refunds']) {
                throw new InvalidArgumentException('Revenue streams inconsistentes con snapshot.');
            }
        }

        $sourceRef = self::sourceRef($input['source_ref'] ?? null);
        $observedAt = self::timestamp($input['observed_at'] ?? null, 'observed_at');
        $freshness = self::enum($input['freshness'] ?? null, self::FRESHNESS, 'freshness');
        $confidence = self::enum($input['confidence'] ?? null, self::CONFIDENCE, 'confidence');

        if ($freshness !== 'fresh' && $confidence === 'verified') {
            throw new InvalidArgumentException('Snapshot stale/unknown no puede ser verified.');
        }

        $netRevenue = $money['revenue'] - $money['refunds'];
        $grossProfit = $netRevenue - $money['direct_costs'];
        $operatingResult = $grossProfit
            - $money['operating_costs']
            - $money['infrastructure_costs']
            - $money['ai_costs'];

        return [
            'version' => 1,
            'venture_id' => $venture,
            'period' => $period,
            'currency' => $currency,
            ...$money,
            'net_revenue' => $netRevenue,
            'gross_profit' => $grossProfit,
            'operating_result' => $operatingResult,
            'customers' => $customers,
            'transactions' => $transactions,
            'revenue_streams' => $streams,
            'source_ref' => $sourceRef,
            'observed_at' => $observedAt,
            'freshness' => $freshness,
            'confidence' => $confidence,
        ];
    }

    private static function streams(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 64) {
            throw new InvalidArgumentException('revenue_streams inválido.');
        }

        $seen = [];
        $streams = [];
        foreach ($value as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException('Revenue stream inválido.');
            }
            self::exactKeys($item, self::STREAM_FIELDS, 'revenue_stream');

            $id = self::slug($item['stream_id'] ?? null, 'stream_id', 80);
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('stream_id duplicado.');
            }
            $seen[$id] = true;

            $streams[] = [
                'stream_id' => $id,
                'revenue' => self::nonNegativeInteger($item['revenue'] ?? null, 'stream.revenue', self::MAX_MINOR_UNITS),
                'refunds' => self::nonNegativeInteger($item['refunds'] ?? null, 'stream.refunds', self::MAX_MINOR_UNITS),
                'customers' => self::nonNegativeInteger($item['customers'] ?? null, 'stream.customers', 1_000_000_000),
                'transactions' => self::nonNegativeInteger($item['transactions'] ?? null, 'stream.transactions', 10_000_000_000),
            ];
        }

        usort($streams, static fn(array $a, array $b): int => strcmp($a['stream_id'], $b['stream_id']));
        return $streams;
    }

    private static function exactKeys(array $record, array $expected, string $label): void
    {
        $actual = array_keys($record);
        sort($actual, SORT_STRING);
        $wanted = $expected;
        sort($wanted, SORT_STRING);
        if ($actual !== $wanted) {
            throw new InvalidArgumentException($label . ' contiene campos inválidos.');
        }
    }

    private static function slug(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' inválido.');
        }
        return $value;
    }

    private static function period(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $value, $m) !== 1) {
            throw new InvalidArgumentException('period inválido.');
        }
        $year = (int)$m[1];
        if ($year < 2000 || $year > 2200) {
            throw new InvalidArgumentException('period fuera de rango.');
        }
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::CURRENCIES, true)) {
            throw new InvalidArgumentException('currency ISO-4217 inválida.');
        }
        return $value;
    }

    private static function nonNegativeInteger(mixed $value, string $label, int $max): int
    {
        if (!is_int($value) || $value < 0 || $value > $max) {
            throw new InvalidArgumentException($label . ' debe ser integer minor-units no negativo.');
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' inválido.');
        }
        return $value;
    }

    private static function sourceRef(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 8 || strlen($value) > 180
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]+$/D', $value) !== 1
            || str_contains($value, '..')
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException('source_ref inválido.');
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $label): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' inválido.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException($label . ' inválido.');
        }
        return $value;
    }
}

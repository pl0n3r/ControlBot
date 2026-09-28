<?php
declare(strict_types=1);

namespace ControlBot\Business;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class FinanceCostCenter
{
    private const KINDS = ['venture', 'institution_cost_center'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const MAX_MINOR_UNITS = 9_000_000_000_000_000;
    private const INSTITUTIONS = [
        'factory' => ['title' => 'Factory', 'scope' => 'institution:factory'],
        'controlbot' => ['title' => 'ControlBot', 'scope' => 'institution:controlbot'],
        'runner' => ['title' => 'Runner', 'scope' => 'institution:runner'],
        'aegis' => ['title' => 'AEGIS', 'scope' => 'institution:aegis'],
        'momentum' => ['title' => 'MOMENTUM', 'scope' => 'institution:momentum'],
        'capital' => ['title' => 'CAPITAL', 'scope' => 'institution:capital'],
    ];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|iban|account[_ -]?number)/i';

    public static function normalize(array $raw): array
    {
        self::exact($raw, [
            'version', 'kind', 'id', 'scope', 'title',
            'source_ref', 'observed_at', 'freshness',
        ], 'finance_entity');

        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('finance_entity version invalid.');
        }

        $kind = self::enum($raw['kind'] ?? null, self::KINDS, 'kind');
        $id = self::slug($raw['id'] ?? null, 'id');
        $scope = self::scope($raw['scope'] ?? null);
        $title = self::text($raw['title'] ?? null, 'title', 120);

        if ($kind === 'institution_cost_center') {
            $canonical = self::INSTITUTIONS[$id] ?? null;
            if ($canonical === null
                || $scope !== $canonical['scope']
                || $title !== $canonical['title']) {
                throw new InvalidArgumentException('institution cost center mismatch.');
            }
        } else {
            if (isset(self::INSTITUTIONS[$id]) || $scope !== 'venture:' . $id) {
                throw new InvalidArgumentException('venture identity mismatch.');
            }
        }

        return [
            'version' => 1,
            'kind' => $kind,
            'id' => $id,
            'scope' => $scope,
            'title' => $title,
            'source_ref' => self::ref($raw['source_ref'] ?? null, 'source_ref'),
            'observed_at' => self::timestamp($raw['observed_at'] ?? null),
            'freshness' => self::enum($raw['freshness'] ?? null, self::FRESHNESS, 'freshness'),
        ];
    }

    public static function canonicalInstitutions(): array
    {
        $out = [];
        foreach (self::INSTITUTIONS as $id => $entry) {
            $out[] = [
                'kind' => 'institution_cost_center',
                'id' => $id,
                'scope' => $entry['scope'],
                'title' => $entry['title'],
            ];
        }
        return $out;
    }

    public static function normalizeList(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) > 100) {
            throw new InvalidArgumentException('finance entity list invalid.');
        }

        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('finance entity invalid.');
            }
            $entity = self::normalize($row);
            $key = $entity['kind'] . ':' . $entity['id'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('finance entity duplicated.');
            }
            $seen[$key] = true;
            $out[] = $entity;
        }

        usort($out, static function (array $a, array $b): int {
            $byKind = strcmp($a['kind'], $b['kind']);
            return $byKind !== 0 ? $byKind : strcmp($a['id'], $b['id']);
        });
        return $out;
    }

    public static function normalizeAttribution(array $raw): array
    {
        self::exact($raw, [
            'version', 'attribution_id', 'target', 'currency', 'amount_minor',
            'provenance_ref', 'source_ref', 'observed_at', 'freshness',
        ], 'finance_attribution');

        if (($raw['version'] ?? null) !== 1 || !is_array($raw['target'] ?? null)) {
            throw new InvalidArgumentException('finance_attribution invalid.');
        }

        $target = self::normalize($raw['target']);
        $amount = $raw['amount_minor'] ?? null;
        if (!is_int($amount) || $amount < 0 || $amount > self::MAX_MINOR_UNITS) {
            throw new InvalidArgumentException('amount_minor invalid.');
        }

        $currency = $raw['currency'] ?? null;
        if (!is_string($currency) || strlen($currency) !== 3
            || !ctype_alpha($currency) || strtoupper($currency) !== $currency) {
            throw new InvalidArgumentException('currency invalid.');
        }

        return [
            'version' => 1,
            'attribution_id' => self::slug($raw['attribution_id'] ?? null, 'attribution_id'),
            'target_kind' => $target['kind'],
            'target_id' => $target['id'],
            'target_scope' => $target['scope'],
            'currency' => $currency,
            'amount_minor' => $amount,
            'provenance_ref' => self::ref($raw['provenance_ref'] ?? null, 'provenance_ref'),
            'source_ref' => self::ref($raw['source_ref'] ?? null, 'source_ref'),
            'observed_at' => self::timestamp($raw['observed_at'] ?? null),
            'freshness' => self::enum($raw['freshness'] ?? null, self::FRESHNESS, 'freshness'),
        ];
    }

    private static function exact(array $row, array $expected, string $label): void
    {
        $allowed = array_fill_keys($expected, true);
        if (count($row) !== count($allowed) || array_diff_key($row, $allowed) !== []) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function scope(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:venture|institution):[a-z][a-z0-9-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
    }

    private static function slug(mixed $value, string $label): string
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\r\n\x00]/', $value) === 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value) || strlen($value) < 8 || strlen($value) > 180
            || str_contains($value, '@')
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\#-]+$#D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function timestamp(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException('observed_at invalid.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('observed_at invalid.');
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

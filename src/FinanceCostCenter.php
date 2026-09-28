<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FinanceCostCenter
{
    private const KINDS = ['venture', 'institution_cost_center'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const MAX_MINOR_UNITS = 9_000_000_000_000_000;
    private const INSTITUTIONS = [
        'factory' => ['Factory', 'institution:factory'],
        'controlbot' => ['ControlBot', 'institution:controlbot'],
        'runner' => ['Runner', 'institution:runner'],
        'aegis' => ['AEGIS', 'institution:aegis'],
        'momentum' => ['MOMENTUM', 'institution:momentum'],
        'capital' => ['CAPITAL', 'institution:capital'],
    ];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|iban|account[_ -]?number)/i';

    public static function normalize(array $raw): array
    {
        self::requireFields(
            $raw,
            ['version', 'kind', 'id', 'scope', 'title', 'source_ref', 'observed_at', 'freshness'],
            'finance_entity',
        );
        self::requireVersion($raw['version'] ?? null, 'finance_entity');

        $kind = self::catalogValue($raw['kind'] ?? null, self::KINDS, 'kind');
        $id = self::entityId($raw['id'] ?? null, 'id');
        $scope = self::entityScope($raw['scope'] ?? null);
        $title = self::plainText($raw['title'] ?? null, 'title', 120);

        self::assertIdentity($kind, $id, $scope, $title);

        return [
            'version' => 1,
            'kind' => $kind,
            'id' => $id,
            'scope' => $scope,
            'title' => $title,
            'source_ref' => self::evidenceRef($raw['source_ref'] ?? null, 'source_ref'),
            'observed_at' => self::utcTimestamp($raw['observed_at'] ?? null),
            'freshness' => self::catalogValue($raw['freshness'] ?? null, self::FRESHNESS, 'freshness'),
        ];
    }

    public static function canonicalInstitutions(): array
    {
        return array_map(
            static fn(string $id, array $entry): array => [
                'kind' => 'institution_cost_center',
                'id' => $id,
                'scope' => $entry[1],
                'title' => $entry[0],
            ],
            array_keys(self::INSTITUTIONS),
            array_values(self::INSTITUTIONS),
        );
    }

    public static function normalizeList(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) > 100) {
            throw new InvalidArgumentException('finance entity list invalid.');
        }

        $out = array_map(
            static fn(mixed $row): array => is_array($row)
                ? self::normalize($row)
                : throw new InvalidArgumentException('finance entity invalid.'),
            $rows,
        );

        $keys = array_map(
            static fn(array $entity): string => $entity['kind'] . ':' . $entity['id'],
            $out,
        );
        if (count($keys) !== count(array_unique($keys, SORT_STRING))) {
            throw new InvalidArgumentException('finance entity duplicated.');
        }

        usort(
            $out,
            static fn(array $a, array $b): int =>
                [$a['kind'], $a['id']] <=> [$b['kind'], $b['id']],
        );
        return $out;
    }

    public static function normalizeAttribution(array $raw): array
    {
        self::requireFields(
            $raw,
            [
                'version', 'attribution_id', 'target', 'currency', 'amount_minor',
                'provenance_ref', 'source_ref', 'observed_at', 'freshness',
            ],
            'finance_attribution',
        );
        self::requireVersion($raw['version'] ?? null, 'finance_attribution');

        if (!is_array($raw['target'] ?? null)) {
            throw new InvalidArgumentException('finance_attribution target invalid.');
        }

        $target = self::normalize($raw['target']);
        return [
            'version' => 1,
            'attribution_id' => self::entityId($raw['attribution_id'] ?? null, 'attribution_id'),
            'target_kind' => $target['kind'],
            'target_id' => $target['id'],
            'target_scope' => $target['scope'],
            'currency' => self::currency($raw['currency'] ?? null),
            'amount_minor' => self::minorUnits($raw['amount_minor'] ?? null),
            'provenance_ref' => self::evidenceRef($raw['provenance_ref'] ?? null, 'provenance_ref'),
            'source_ref' => self::evidenceRef($raw['source_ref'] ?? null, 'source_ref'),
            'observed_at' => self::utcTimestamp($raw['observed_at'] ?? null),
            'freshness' => self::catalogValue($raw['freshness'] ?? null, self::FRESHNESS, 'freshness'),
        ];
    }

    private static function assertIdentity(string $kind, string $id, string $scope, string $title): void
    {
        $institution = self::INSTITUTIONS[$id] ?? null;

        if ($kind === 'institution_cost_center') {
            if ($institution === null || [$title, $scope] !== $institution) {
                throw new InvalidArgumentException('institution cost center mismatch.');
            }
            return;
        }

        if ($institution !== null || $scope !== 'venture:' . $id) {
            throw new InvalidArgumentException('venture identity mismatch.');
        }
    }

    private static function requireFields(array $row, array $fields, string $label): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($fields, SORT_STRING);
        if ($actual !== $fields) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function requireVersion(mixed $value, string $label): void
    {
        if ($value !== 1) {
            throw new InvalidArgumentException($label . ' version invalid.');
        }
    }

    private static function entityScope(mixed $value): string
    {
        return self::matchingString(
            $value,
            '/^(?:venture|institution):[a-z][a-z0-9-]{0,63}$/D',
            'scope',
        );
    }

    private static function entityId(mixed $value, string $label): string
    {
        $id = self::matchingString($value, '/^[a-z][a-z0-9-]{0,63}$/D', $label);
        self::rejectSensitive($id, $label);
        return $id;
    }

    private static function plainText(mixed $value, string $label, int $limit): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $text = trim($value);
        if ($text === '' || strlen($text) > $limit || strpbrk($text, "\r\n\0") !== false) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        self::rejectSensitive($text, $label);
        return $text;
    }

    private static function evidenceRef(mixed $value, string $label): string
    {
        $ref = self::matchingString(
            $value,
            '#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\\#-]{6,168}$#D',
            $label,
        );
        if (str_contains($ref, '@')) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        self::rejectSensitive($ref, $label);
        return $ref;
    }

    private static function matchingString(mixed $value, string $pattern, string $label): string
    {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function rejectSensitive(string $value, string $label): void
    {
        if (preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
    }

    private static function utcTimestamp(mixed $value): string
    {
        if (!is_string($value)
            || strlen($value) !== 20
            || $value[4] !== '-'
            || $value[7] !== '-'
            || $value[10] !== 'T'
            || $value[13] !== ':'
            || $value[16] !== ':'
            || $value[19] !== 'Z') {
            throw new InvalidArgumentException('observed_at invalid.');
        }

        $epoch = strtotime($value);
        if ($epoch === false || gmdate('Y-m-d\TH:i:s\Z', $epoch) !== $value) {
            throw new InvalidArgumentException('observed_at invalid.');
        }
        return $value;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || strlen($value) !== 3
            || !ctype_alpha($value) || strtoupper($value) !== $value) {
            throw new InvalidArgumentException('currency invalid.');
        }
        return $value;
    }

    private static function minorUnits(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX_MINOR_UNITS) {
            throw new InvalidArgumentException('amount_minor invalid.');
        }
        return $value;
    }

    private static function catalogValue(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $index = array_search($value, $allowed, true);
        if ($index === false) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $allowed[$index];
    }
}

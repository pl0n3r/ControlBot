<?php
declare(strict_types=1);

namespace ControlBot\Legal;

use InvalidArgumentException;

final class LexJurisdiction
{
    private const KINDS = ['country', 'region', 'supranational'];
    private const PACK_STATES = ['active', 'deprecated'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const DOMAINS = [
        'privacy', 'consumer', 'contracts', 'marketing', 'intellectual_property',
        'saas_subscriptions', 'tax_signal', 'labor', 'ai_automation', 'providers',
        'retention_portability', 'international_transfers', 'sector_specific',
    ];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code|session|credential)/i';

    /** Normalize a versioned jurisdiction pack without interpreting legal effect. */
    public static function normalize(array $input, int $now): array
    {
        self::fields($input, [
            'schema_version', 'pack_id', 'jurisdiction', 'kind', 'pack_version',
            'state', 'freshness', 'reviewed_at', 'expires_at', 'responsible_refs',
            'sources', 'controls', 'assumption_refs', 'exclusion_refs', 'compatibility',
        ], 'jurisdiction pack');

        if (($input['schema_version'] ?? null) !== 1 || $now < 1) {
            throw new InvalidArgumentException('jurisdiction pack version/now invalid.');
        }

        $reviewedAt = self::time($input['reviewed_at'] ?? null, 'reviewed_at');
        $expiresAt = self::time($input['expires_at'] ?? null, 'expires_at');
        if ($reviewedAt > $now) {
            throw new InvalidArgumentException('jurisdiction pack reviewed_at in the future.');
        }
        if ($expiresAt <= $reviewedAt) {
            throw new InvalidArgumentException('jurisdiction pack expires_at invalid.');
        }

        $sources = self::sources($input['sources'] ?? null, $now);
        $sourceIds = array_column($sources, 'source_id');
        $controls = self::controls($input['controls'] ?? null, $sourceIds);
        $state = self::enum($input['state'] ?? null, self::PACK_STATES, 'pack state');
        $freshness = self::enum($input['freshness'] ?? null, self::FRESHNESS, 'freshness');
        $compatibility = self::compatibility($input['compatibility'] ?? null);

        $reasons = [];
        if ($state !== 'active') {
            $reasons[] = 'pack_deprecated';
        }
        if ($freshness !== 'fresh') {
            $reasons[] = 'freshness_' . $freshness;
        }
        if ($expiresAt <= $now) {
            $reasons[] = 'pack_expired';
        }
        if ($compatibility['lex_core_contract'] !== 1) {
            $reasons[] = 'lex_core_contract_incompatible';
        }
        sort($reasons, SORT_STRING);

        return [
            'schema_version' => 1,
            'pack_id' => self::id($input['pack_id'] ?? null, 'pack_id'),
            'jurisdiction' => self::jurisdiction($input['jurisdiction'] ?? null),
            'kind' => self::enum($input['kind'] ?? null, self::KINDS, 'jurisdiction kind'),
            'pack_version' => self::semver($input['pack_version'] ?? null),
            'state' => $state,
            'freshness' => $freshness,
            'reviewed_at' => $reviewedAt,
            'expires_at' => $expiresAt,
            'evidence_state' => $reasons === [] ? 'current' : 'not_current',
            'reasons' => $reasons,
            'responsible_refs' => self::refs($input['responsible_refs'] ?? null, 'responsible_refs', false),
            'sources' => $sources,
            'controls' => $controls,
            'assumption_refs' => self::refs($input['assumption_refs'] ?? null, 'assumption_refs', true),
            'exclusion_refs' => self::refs($input['exclusion_refs'] ?? null, 'exclusion_refs', true),
            'compatibility' => $compatibility,
        ];
    }

    private static function sources(mixed $rows, int $now): array
    {
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 50) {
            throw new InvalidArgumentException('sources invalid.');
        }
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('source invalid.');
            }
            self::fields($row, ['source_id', 'authority', 'title', 'uri', 'reviewed_at'], 'source');
            $sourceId = self::id($row['source_id'] ?? null, 'source_id');
            $reviewedAt = self::time($row['reviewed_at'] ?? null, 'source reviewed_at');
            if ($reviewedAt > $now) {
                throw new InvalidArgumentException('source reviewed_at in the future.');
            }
            $source = [
                'source_id' => $sourceId,
                'authority' => self::text($row['authority'] ?? null, 'authority', 160),
                'title' => self::text($row['title'] ?? null, 'title', 220),
                'uri' => self::httpsUri($row['uri'] ?? null),
                'reviewed_at' => $reviewedAt,
            ];
            if (isset($byId[$sourceId]) && $byId[$sourceId] !== $source) {
                throw new InvalidArgumentException('duplicate source conflict.');
            }
            $byId[$sourceId] = $source;
        }
        ksort($byId, SORT_STRING);
        return array_values($byId);
    }

    private static function controls(mixed $rows, array $sourceIds): array
    {
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 100) {
            throw new InvalidArgumentException('controls invalid.');
        }
        $knownSources = array_fill_keys($sourceIds, true);
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('control invalid.');
            }
            self::fields($row, [
                'control_id', 'domain', 'requirement_ref', 'source_refs', 'human_review_required',
            ], 'control');
            $controlId = self::id($row['control_id'] ?? null, 'control_id');
            $refs = self::ids($row['source_refs'] ?? null, 'control source_refs', false);
            foreach ($refs as $ref) {
                if (!isset($knownSources[$ref])) {
                    throw new InvalidArgumentException('control source_ref unknown.');
                }
            }
            $control = [
                'control_id' => $controlId,
                'domain' => self::enum($row['domain'] ?? null, self::DOMAINS, 'control domain'),
                'requirement_ref' => self::ref($row['requirement_ref'] ?? null, 'requirement_ref'),
                'source_refs' => $refs,
                'human_review_required' => self::boolean($row['human_review_required'] ?? null, 'human_review_required'),
            ];
            if (isset($byId[$controlId]) && $byId[$controlId] !== $control) {
                throw new InvalidArgumentException('duplicate control conflict.');
            }
            $byId[$controlId] = $control;
        }
        ksort($byId, SORT_STRING);
        return array_values($byId);
    }

    private static function compatibility(mixed $row): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('compatibility invalid.');
        }
        self::fields($row, ['lex_core_contract', 'deprecated_by'], 'compatibility');
        $contract = $row['lex_core_contract'] ?? null;
        if (!is_int($contract) || $contract < 1 || $contract > 1000) {
            throw new InvalidArgumentException('lex_core_contract invalid.');
        }
        return [
            'lex_core_contract' => $contract,
            'deprecated_by' => ($row['deprecated_by'] ?? null) === null
                ? null
                : self::id($row['deprecated_by'], 'deprecated_by'),
        ];
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        if (array_is_list($row) || count($row) !== count($expected)) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
        foreach ($expected as $field) {
            if (!array_key_exists($field, $row)) {
                throw new InvalidArgumentException($label . ' fields invalid.');
            }
        }
    }

    private static function jurisdiction(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:country:[A-Z]{2}|region:[A-Z]{2,12}|supranational:[A-Z]{2,12})$/D', $value) !== 1) {
            throw new InvalidArgumentException('jurisdiction invalid.');
        }
        return $value;
    }

    private static function semver(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('pack_version invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value)
            || strlen($value) > 140
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,10}$/D', $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max
            || str_contains($value, "\n") || str_contains($value, "\r")
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function httpsUri(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 500 || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException('source uri invalid.');
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidArgumentException('source uri invalid.');
        }
        return $value;
    }

    private static function ids(mixed $value, string $label, bool $allowEmpty): array
    {
        return self::normalizedList(
            $value,
            $label,
            $allowEmpty,
            static fn(mixed $item): string => self::id($item, $label),
        );
    }

    private static function refs(mixed $value, string $label, bool $allowEmpty): array
    {
        return self::normalizedList(
            $value,
            $label,
            $allowEmpty,
            static fn(mixed $item): string => self::ref($item, $label),
        );
    }

    private static function normalizedList(
        mixed $value,
        string $label,
        bool $allowEmpty,
        callable $normalize,
    ): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50
            || (!$allowEmpty && $value === [])) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $unique = [];
        foreach ($value as $item) {
            $unique[$normalize($item)] = true;
        }
        $items = array_keys($unique);
        sort($items, SORT_STRING);
        return $items;
    }

    private static function ref(mixed $value, string $label): string
    {
        $invalid = !is_string($value)
            || strlen($value) < 8
            || strlen($value) > 220
            || str_contains($value, '@')
            || str_contains($value, '..')
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\\#-]+$#D', $value) !== 1;
        if ($invalid) {
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

<?php
declare(strict_types=1);

namespace ControlBot\Legal;

use InvalidArgumentException;

final class LexRuntime
{
    private const MODES = ['country', 'multi_country', 'global'];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code|session|credential)/i';

    public static function evaluate(array $input, int $now): array
    {
        self::fields($input, [
            'version', 'scope', 'market', 'packs', 'registry_before',
            'gate', 'registry_after', 'watch',
        ], 'lex runtime');
        if (($input['version'] ?? null) !== 1 || $now < 1) {
            throw new InvalidArgumentException('lex runtime version/now invalid.');
        }

        $scope = self::scope($input['scope'] ?? null);
        $market = self::market($input['market'] ?? null);
        [$packs, $missing] = self::packs($input['packs'] ?? null, $market, $now);

        $before = LexCore::registry(self::arrayValue($input['registry_before'] ?? null, 'registry_before'), $now);
        $after = LexCore::registry(self::arrayValue($input['registry_after'] ?? null, 'registry_after'), $now);
        if ($before['scope'] !== $scope || $after['scope'] !== $scope) {
            throw new InvalidArgumentException('lex runtime scope mismatch.');
        }

        $gate = LexGate::evaluate(self::arrayValue($input['gate'] ?? null, 'gate'), $now);
        if ($gate['scope'] !== $scope) {
            throw new InvalidArgumentException('lex gate scope mismatch.');
        }

        $watch = LexWatch::normalize(self::arrayValue($input['watch'] ?? null, 'watch'), $now);

        return [
            'version' => 1,
            'scope' => $scope,
            'market' => $market,
            'selected_packs' => $packs,
            'missing_jurisdictions' => $missing,
            'jurisdiction_state' => $missing === [] ? 'covered' : 'unknown',
            'before' => $before,
            'gate' => $gate,
            'watch' => $watch,
            'after' => $after,
            'reevaluation_state' => self::state($after, $missing, $gate),
            'provider_executed' => false,
            'factory_authority' => 'unchanged',
            'authority_effect' => 'none',
        ];
    }

    private static function market(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('market invalid.');
        }
        self::fields($value, ['market_id', 'mode', 'jurisdictions', 'source_ref'], 'market');

        $mode = self::enum($value['mode'] ?? null, self::MODES, 'market mode');
        $jurisdictions = self::jurisdictions($value['jurisdictions'] ?? null);
        if ($mode === 'country' && count($jurisdictions) !== 1) {
            throw new InvalidArgumentException('country market requires one jurisdiction.');
        }

        return [
            'market_id' => self::id($value['market_id'] ?? null, 'market_id'),
            'mode' => $mode,
            'jurisdictions' => $jurisdictions,
            'source_ref' => self::ref($value['source_ref'] ?? null, 'market source_ref'),
        ];
    }

    private static function packs(mixed $rows, array $market, int $now): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 50) {
            throw new InvalidArgumentException('packs invalid.');
        }

        $wanted = array_fill_keys($market['jurisdictions'], true);
        $selected = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('pack invalid.');
            }
            $pack = LexJurisdiction::normalize($row, $now);
            if (!isset($wanted[$pack['jurisdiction']])) {
                continue;
            }
            $id = $pack['pack_id'];
            if (isset($selected[$id]) && $selected[$id] !== $pack) {
                throw new InvalidArgumentException('duplicate pack conflict.');
            }
            $selected[$id] = $pack;
        }
        ksort($selected, SORT_STRING);

        $covered = [];
        foreach ($selected as $pack) {
            if ($pack['evidence_state'] === 'current') {
                $covered[$pack['jurisdiction']] = true;
            }
        }
        $missing = array_values(array_diff($market['jurisdictions'], array_keys($covered)));
        sort($missing, SORT_STRING);

        return [array_values($selected), $missing];
    }

    private static function state(array $registry, array $missing, array $gate): string
    {
        if ($missing !== [] || $gate['human_gate'] !== null) {
            return 'unknown';
        }
        $counts = $registry['summary']['counts'];
        if (($counts['unknown'] ?? 0) > 0) {
            return 'unknown';
        }
        if (($counts['gap'] ?? 0) > 0) {
            return 'gap';
        }
        return 'compliant';
    }

    private static function jurisdictions(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 20) {
            throw new InvalidArgumentException('market jurisdictions invalid.');
        }
        $out = [];
        foreach ($value as $jurisdiction) {
            if (!is_string($jurisdiction)
                || preg_match('/^(?:country:[A-Z]{2}|region:[A-Z]{2,12}|supranational:[A-Z]{2,12})$/D', $jurisdiction) !== 1) {
                throw new InvalidArgumentException('market jurisdiction invalid.');
            }
            $out[$jurisdiction] = true;
        }
        $result = array_keys($out);
        sort($result, SORT_STRING);
        return $result;
    }

    private static function scope(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value)
            || strlen($value) > 140
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,9}$/D', $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
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

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function arrayValue(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
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
}

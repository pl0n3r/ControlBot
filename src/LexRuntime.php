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
        self::fields($input, ['version','scope','market','packs','registry_before','gate','registry_after','watch'], 'lex runtime');
        if (($input['version'] ?? null) !== 1 || $now < 1) {
            throw new InvalidArgumentException('lex runtime version/now invalid.');
        }

        $scope = self::scope($input['scope'] ?? null);
        $market = self::market($input['market'] ?? null);
        [$packs, $missing] = self::packs($input['packs'] ?? null, $market, $now);
        $before = LexCore::registry(self::arr($input['registry_before'] ?? null, 'registry_before'), $now);
        $after = LexCore::registry(self::arr($input['registry_after'] ?? null, 'registry_after'), $now);
        $gate = LexGate::evaluate(self::arr($input['gate'] ?? null, 'gate'), $now);
        $watch = LexWatch::normalize(self::arr($input['watch'] ?? null, 'watch'), $now);

        if ($before['scope'] !== $scope || $after['scope'] !== $scope || $gate['scope'] !== $scope) {
            throw new InvalidArgumentException('lex runtime scope mismatch.');
        }

        return [
            'version' => 1, 'scope' => $scope, 'market' => $market,
            'selected_packs' => $packs, 'missing_jurisdictions' => $missing,
            'jurisdiction_state' => $missing === [] ? 'covered' : 'unknown',
            'before' => $before, 'gate' => $gate, 'watch' => $watch, 'after' => $after,
            'reevaluation_state' => self::state($after, $missing, $gate),
            'provider_executed' => false, 'factory_authority' => 'unchanged',
            'authority_effect' => 'none',
        ];
    }

    private static function market(mixed $raw): array
    {
        $row = self::arr($raw, 'market');
        self::fields($row, ['market_id','mode','jurisdictions','source_ref'], 'market');
        $mode = self::enum($row['mode'] ?? null, self::MODES, 'market mode');
        $jurisdictions = self::jurisdictions($row['jurisdictions'] ?? null);
        if ($mode === 'country' && count($jurisdictions) !== 1) {
            throw new InvalidArgumentException('country market requires one jurisdiction.');
        }
        return [
            'market_id' => self::id($row['market_id'] ?? null, 'market_id'),
            'mode' => $mode, 'jurisdictions' => $jurisdictions,
            'source_ref' => self::ref($row['source_ref'] ?? null, 'market source_ref'),
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
            if (!is_array($row)) throw new InvalidArgumentException('pack invalid.');
            $pack = LexJurisdiction::normalize($row, $now);
            if (!isset($wanted[$pack['jurisdiction']])) continue;
            if (isset($selected[$pack['pack_id']]) && $selected[$pack['pack_id']] !== $pack) {
                throw new InvalidArgumentException('duplicate pack conflict.');
            }
            $selected[$pack['pack_id']] = $pack;
        }
        ksort($selected, SORT_STRING);
        $covered = [];
        foreach ($selected as $pack) {
            if ($pack['evidence_state'] === 'current') $covered[$pack['jurisdiction']] = true;
        }
        $missing = array_values(array_diff($market['jurisdictions'], array_keys($covered)));
        sort($missing, SORT_STRING);
        return [array_values($selected), $missing];
    }

    private static function state(array $registry, array $missing, array $gate): string
    {
        if ($missing !== [] || $gate['human_gate'] !== null || $registry['obligations'] === []) return 'unknown';
        $counts = $registry['summary']['counts'];
        if (($counts['unknown'] ?? 0) > 0) return 'unknown';
        if (($counts['gap'] ?? 0) > 0) return 'gap';
        return 'compliant';
    }

    private static function jurisdictions(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 20) {
            throw new InvalidArgumentException('market jurisdictions invalid.');
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)
                || preg_match('/^(?:country:[A-Z]{2}|region:[A-Z]{2,12}|supranational:[A-Z]{2,12})$/D', $item) !== 1) {
                throw new InvalidArgumentException('market jurisdiction invalid.');
            }
            $out[$item] = true;
        }
        $result = array_keys($out);
        sort($result, SORT_STRING);
        return $result;
    }

    private static function scope(mixed $value): string
    {
        return self::text($value, 'scope', '/^(?:group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D', 80);
    }

    private static function id(mixed $value, string $label): string
    {
        return self::text($value, $label, '/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,9}$/D', 140);
    }

    private static function ref(mixed $value, string $label): string
    {
        $ref = self::text($value, $label, '#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\\#-]+$#D', 220);
        if (strlen($ref) < 8 || str_contains($ref, '@') || str_contains($ref, '..')) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $ref;
    }

    private static function text(mixed $value, string $label, string $pattern, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max
            || preg_match($pattern, $value) !== 1 || preg_match(self::SENSITIVE, $value) === 1) {
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

    private static function arr(mixed $value, string $label): array
    {
        if (!is_array($value)) throw new InvalidArgumentException($label . ' invalid.');
        return $value;
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        if ($actual !== $expected) throw new InvalidArgumentException($label . ' fields invalid.');
    }
}

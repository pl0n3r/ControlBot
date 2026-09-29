<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use InvalidArgumentException;

final class InfrastructureProvider
{
    private const PROVIDER_KINDS = ['hosting', 'cloud', 'saas', 'storage', 'network', 'other'];
    private const CAPABILITIES = [
        'inventory.read', 'health.read', 'deploy.read', 'backup.read', 'backup.write',
        'database.read', 'storage.read', 'dns.read', 'dns.write', 'certificate.read',
        'network.read', 'cost.read',
    ];
    private const SCOPES = ['provider', 'project', 'environment', 'resource'];

    public static function normalizeProvider(array $raw): array
    {
        self::fields($raw, [
            'version', 'provider_id', 'kind', 'vendor', 'adapter_ref',
            'capabilities', 'source_ref', 'observed_at',
        ], 'Provider');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Provider version invalid.');
        }

        return [
            'version' => 1,
            'provider_id' => self::id($raw['provider_id'], 'provider_id'),
            'kind' => self::enum($raw['kind'], self::PROVIDER_KINDS, 'provider.kind'),
            'vendor' => self::slug($raw['vendor'], 'provider.vendor'),
            'adapter_ref' => self::controlRef($raw['adapter_ref'], 'provider.adapter_ref'),
            'capabilities' => self::capabilities($raw['capabilities']),
            'source_ref' => self::reference($raw['source_ref'], 'provider.source_ref'),
            'observed_at' => self::timestamp($raw['observed_at'], 'provider.observed_at'),
        ];
    }

    public static function normalizeAccount(array $raw): array
    {
        self::fields($raw, [
            'version', 'account_id', 'provider_id', 'alias', 'source_ref', 'observed_at',
        ], 'ProviderAccount');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('ProviderAccount version invalid.');
        }

        return [
            'version' => 1,
            'account_id' => self::id($raw['account_id'], 'account_id'),
            'provider_id' => self::id($raw['provider_id'], 'account.provider_id'),
            'alias' => self::slug($raw['alias'], 'account.alias'),
            'source_ref' => self::reference($raw['source_ref'], 'account.source_ref'),
            'observed_at' => self::timestamp($raw['observed_at'], 'account.observed_at'),
        ];
    }

    public static function normalizeInventory(array $providers, array $accounts): array
    {
        $providers = self::rows($providers, 100, 'providers');
        $accounts = self::rows($accounts, 500, 'accounts');
        $providerById = [];
        $normalizedProviders = [];
        foreach ($providers as $row) {
            $provider = self::normalizeProvider($row);
            if (isset($providerById[$provider['provider_id']])) {
                throw new InvalidArgumentException('Provider duplicated.');
            }
            $providerById[$provider['provider_id']] = true;
            $normalizedProviders[] = $provider;
        }

        $seen = [];
        $normalizedAccounts = [];
        foreach ($accounts as $row) {
            $account = self::normalizeAccount($row);
            if (!isset($providerById[$account['provider_id']])) {
                throw new InvalidArgumentException('ProviderAccount provider unknown.');
            }
            if (isset($seen[$account['account_id']])) {
                throw new InvalidArgumentException('ProviderAccount duplicated.');
            }
            $seen[$account['account_id']] = true;
            $normalizedAccounts[] = $account;
        }

        usort($normalizedProviders, static fn(array $a, array $b): int => $a['provider_id'] <=> $b['provider_id']);
        usort($normalizedAccounts, static fn(array $a, array $b): int => $a['account_id'] <=> $b['account_id']);
        return ['providers' => $normalizedProviders, 'accounts' => $normalizedAccounts];
    }

    private static function capabilities(mixed $rows): array
    {
        $rows = self::rows($rows, 30, 'capabilities');
        $out = [];
        foreach ($rows as $row) {
            self::fields($row, ['capability', 'scopes'], 'ProviderCapability');
            $capability = self::enum($row['capability'], self::CAPABILITIES, 'capability');
            if (isset($out[$capability])) {
                throw new InvalidArgumentException('ProviderCapability duplicated.');
            }
            $scopes = self::rows($row['scopes'], 4, 'capability.scopes');
            $normalizedScopes = [];
            foreach ($scopes as $scope) {
                $scope = self::enum($scope, self::SCOPES, 'capability.scope');
                $normalizedScopes[$scope] = true;
            }
            $scopes = array_keys($normalizedScopes);
            sort($scopes);
            $out[$capability] = ['capability' => $capability, 'scopes' => $scopes];
        }
        ksort($out);
        return array_values($out);
    }

    private static function reference(mixed $value, string $label): string
    {
        if (is_string($value) && str_starts_with($value, 'controlbot:')) {
            return self::controlRef($value, $label);
        }
        if (!is_string($value)
            || preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/(?:issues|pull)/[1-9][0-9]*)?$#D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function controlRef(mixed $value, string $label): string
    {
        if (!is_string($value)
            || strlen($value) > 240
            || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\#@-]*$#D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function rows(mixed $rows, int $max, string $label): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > $max) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $rows;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function slug(mixed $value, string $label): string
    {
        return self::id($value, $label);
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
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

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
}

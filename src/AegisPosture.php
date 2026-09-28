<?php
declare(strict_types=1);

namespace ControlBot\Security;

use InvalidArgumentException;

final class AegisPosture
{
    private const CATEGORIES = [
        'identity_access', 'secrets_posture', 'application_security',
        'infrastructure_security', 'ai_security', 'vulnerability',
        'backup', 'monitoring', 'compliance',
    ];
    private const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];
    private const FINDING_STATES = ['open', 'mitigated', 'resolved', 'unknown'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const IDENTITY_METRICS = ['mfa_coverage', 'privileged_identities', 'orphan_accounts'];
    private const SOURCE_TYPES = ['authoritative', 'derived', 'unknown'];
    private const BACKUP_STATES = ['available', 'failed', 'unknown'];
    private const RESTORE_STATES = ['verified', 'failed', 'unknown'];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code)/i';

    public static function summarize(array $input): array
    {
        self::fields($input, [
            'version', 'scope', 'findings', 'identity_signals',
            'vulnerability_signals', 'backup_signal', 'restore_signal',
        ], 'posture');

        if (($input['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('posture version invalid.');
        }

        $scope = self::scope($input['scope'] ?? null);
        $findings = self::findings($input['findings'] ?? null, $scope);
        $identity = self::identitySignals($input['identity_signals'] ?? null, $scope);
        $vulnerabilities = self::vulnerabilitySignals($input['vulnerability_signals'] ?? null, $scope);
        $backup = self::continuitySignal($input['backup_signal'] ?? null, $scope, 'backup');
        $restore = self::continuitySignal($input['restore_signal'] ?? null, $scope, 'restore');

        $unknown = self::hasUnknown($findings, $identity, $vulnerabilities, $backup, $restore);
        $attention = self::needsAttention($findings, $vulnerabilities, $backup, $restore);

        return [
            'version' => 1,
            'scope' => $scope,
            'posture_state' => $attention ? 'attention_required' : ($unknown ? 'unknown' : 'observed'),
            'findings' => $findings,
            'identity_signals' => $identity,
            'vulnerability_signals' => $vulnerabilities,
            'backup_signal' => $backup,
            'restore_signal' => $restore,
        ];
    }

    private static function findings(mixed $rows, string $scope): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 200) {
            throw new InvalidArgumentException('findings invalid.');
        }

        $byId = [];
        foreach ($rows as $row) {
            $finding = self::finding($row, $scope);
            $id = $finding['finding_id'];

            if (!isset($byId[$id])) {
                $byId[$id] = $finding;
                continue;
            }

            $prior = $byId[$id];
            foreach (['category', 'severity', 'scope', 'state', 'source_ref', 'observed_at', 'freshness'] as $field) {
                if ($prior[$field] !== $finding[$field]) {
                    throw new InvalidArgumentException('duplicate finding conflict.');
                }
            }
            $evidence = array_values(array_unique([
                ...$prior['evidence_refs'],
                ...$finding['evidence_refs'],
            ]));
            sort($evidence, SORT_STRING);
            $byId[$id]['evidence_refs'] = $evidence;
        }

        ksort($byId, SORT_STRING);
        return array_values($byId);
    }

    private static function finding(mixed $row, string $scope): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('finding invalid.');
        }
        self::fields($row, [
            'finding_id', 'category', 'severity', 'scope', 'state',
            'evidence_refs', 'source_ref', 'observed_at', 'freshness',
        ], 'finding');

        $findingScope = self::scope($row['scope'] ?? null);
        if ($findingScope !== $scope) {
            throw new InvalidArgumentException('finding scope mismatch.');
        }

        return [
            'finding_id' => self::id($row['finding_id'] ?? null, 'finding_id'),
            'category' => self::enum($row['category'] ?? null, self::CATEGORIES, 'category'),
            'severity' => self::enum($row['severity'] ?? null, self::SEVERITIES, 'severity'),
            'scope' => $findingScope,
            'state' => self::enum($row['state'] ?? null, self::FINDING_STATES, 'state'),
            'evidence_refs' => self::refs($row['evidence_refs'] ?? null, 'evidence_refs'),
            'source_ref' => self::ref($row['source_ref'] ?? null, 'source_ref'),
            'observed_at' => self::time($row['observed_at'] ?? null, 'observed_at'),
            'freshness' => self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness'),
        ];
    }

    private static function identitySignals(mixed $rows, string $scope): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 30) {
            throw new InvalidArgumentException('identity_signals invalid.');
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('identity signal invalid.');
            }
            self::fields($row, [
                'metric', 'scope', 'value', 'source_type',
                'source_ref', 'observed_at', 'freshness',
            ], 'identity_signal');

            $metric = self::enum($row['metric'] ?? null, self::IDENTITY_METRICS, 'identity metric');
            if (isset($seen[$metric])) {
                throw new InvalidArgumentException('identity metric duplicated.');
            }
            $seen[$metric] = true;

            $signalScope = self::scope($row['scope'] ?? null);
            if ($signalScope !== $scope) {
                throw new InvalidArgumentException('identity signal scope mismatch.');
            }

            $sourceType = self::enum($row['source_type'] ?? null, self::SOURCE_TYPES, 'source_type');
            $freshness = self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness');
            $known = $sourceType === 'authoritative' && $freshness === 'fresh';
            $value = $row['value'] ?? null;
            if (!is_int($value) || $value < 0 || $value > 1_000_000_000) {
                throw new InvalidArgumentException('identity signal value invalid.');
            }

            $out[] = [
                'metric' => $metric,
                'scope' => $signalScope,
                'state' => $known ? 'known' : 'unknown',
                'value' => $known ? $value : null,
                'source_type' => $sourceType,
                'source_ref' => self::ref($row['source_ref'] ?? null, 'source_ref'),
                'observed_at' => self::time($row['observed_at'] ?? null, 'observed_at'),
                'freshness' => $freshness,
            ];
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['metric'], $b['metric']));
        return $out;
    }

    private static function vulnerabilitySignals(mixed $rows, string $scope): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > count(self::SEVERITIES)) {
            throw new InvalidArgumentException('vulnerability_signals invalid.');
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('vulnerability signal invalid.');
            }
            self::fields($row, [
                'severity', 'scope', 'count', 'source_ref', 'observed_at', 'freshness',
            ], 'vulnerability_signal');

            $severity = self::enum($row['severity'] ?? null, self::SEVERITIES, 'severity');
            if (isset($seen[$severity])) {
                throw new InvalidArgumentException('vulnerability severity duplicated.');
            }
            $seen[$severity] = true;

            $signalScope = self::scope($row['scope'] ?? null);
            if ($signalScope !== $scope) {
                throw new InvalidArgumentException('vulnerability scope mismatch.');
            }

            $count = $row['count'] ?? null;
            if (!is_int($count) || $count < 0 || $count > 1_000_000_000) {
                throw new InvalidArgumentException('vulnerability count invalid.');
            }
            $freshness = self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness');

            $out[] = [
                'severity' => $severity,
                'scope' => $signalScope,
                'count' => $count,
                'state' => $freshness !== 'fresh' ? 'unknown' : ($count === 0 ? 'clear' : 'exposed'),
                'source_ref' => self::ref($row['source_ref'] ?? null, 'source_ref'),
                'observed_at' => self::time($row['observed_at'] ?? null, 'observed_at'),
                'freshness' => $freshness,
            ];
        }

        usort(
            $out,
            static fn(array $a, array $b): int =>
                array_search($a['severity'], self::SEVERITIES, true)
                <=> array_search($b['severity'], self::SEVERITIES, true),
        );
        return $out;
    }

    private static function continuitySignal(mixed $row, string $scope, string $kind): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($kind . ' signal invalid.');
        }
        self::fields($row, ['scope', 'state', 'source_ref', 'observed_at', 'freshness'], $kind . '_signal');

        $signalScope = self::scope($row['scope'] ?? null);
        if ($signalScope !== $scope) {
            throw new InvalidArgumentException($kind . ' scope mismatch.');
        }
        $states = $kind === 'backup' ? self::BACKUP_STATES : self::RESTORE_STATES;

        return [
            'scope' => $signalScope,
            'state' => self::enum($row['state'] ?? null, $states, $kind . ' state'),
            'source_ref' => self::ref($row['source_ref'] ?? null, 'source_ref'),
            'observed_at' => self::time($row['observed_at'] ?? null, 'observed_at'),
            'freshness' => self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness'),
        ];
    }

    private static function hasUnknown(
        array $findings,
        array $identity,
        array $vulnerabilities,
        array $backup,
        array $restore,
    ): bool {
        if ($backup['freshness'] !== 'fresh' || $backup['state'] === 'unknown'
            || $restore['freshness'] !== 'fresh' || $restore['state'] === 'unknown') {
            return true;
        }
        foreach ($findings as $finding) {
            if ($finding['freshness'] !== 'fresh' || $finding['state'] === 'unknown') return true;
        }
        $identityMetrics = array_column($identity, 'metric');
        foreach (self::IDENTITY_METRICS as $metric) {
            if (!in_array($metric, $identityMetrics, true)) return true;
        }
        foreach ($identity as $signal) {
            if ($signal['state'] === 'unknown') return true;
        }

        $vulnerabilitySeverities = array_column($vulnerabilities, 'severity');
        foreach (self::SEVERITIES as $severity) {
            if (!in_array($severity, $vulnerabilitySeverities, true)) return true;
        }
        foreach ($vulnerabilities as $signal) {
            if ($signal['state'] === 'unknown') return true;
        }
        return false;
    }

    private static function needsAttention(
        array $findings,
        array $vulnerabilities,
        array $backup,
        array $restore,
    ): bool {
        foreach ($findings as $finding) {
            if (in_array($finding['severity'], ['high', 'critical'], true)
                && in_array($finding['state'], ['open', 'unknown'], true)) {
                return true;
            }
        }
        foreach ($vulnerabilities as $signal) {
            if ($signal['state'] === 'exposed'
                && in_array($signal['severity'], ['high', 'critical'], true)) {
                return true;
            }
        }
        return $backup['state'] === 'failed' || $restore['state'] === 'failed';
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

    private static function scope(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:venture|project|institution):[a-z][a-z0-9-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
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

    private static function refs(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 50) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $out = [];
        foreach ($value as $ref) {
            $normalized = self::ref($ref, $label);
            $out[$normalized] = true;
        }
        $refs = array_keys($out);
        sort($refs, SORT_STRING);
        return $refs;
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

    private static function time(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
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

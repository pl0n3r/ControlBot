<?php
declare(strict_types=1);

namespace ControlBot\Security;

use InvalidArgumentException;

final class AegisEvidence
{
    private const COMPLIANCE_STATES = ['compliant', 'gap', 'unknown', 'not_applicable'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const BACKUP_STATES = ['healthy', 'degraded', 'failed', 'unknown'];
    private const RESTORE_STATES = ['verified', 'failed', 'unknown'];
    private const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code|session|credential)/i';

    public static function normalize(array $input): array
    {
        self::fields($input, [
            'version', 'scope', 'compliance', 'backup_evidence',
            'restore_evidence', 'incidents',
        ], 'evidence');

        if (($input['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('evidence version invalid.');
        }

        $scope = self::scope($input['scope'] ?? null);

        return [
            'version' => 1,
            'scope' => $scope,
            'compliance' => self::compliance($input['compliance'] ?? null, $scope),
            'backup_evidence' => self::continuity($input['backup_evidence'] ?? null, $scope, 'backup'),
            'restore_evidence' => self::continuity($input['restore_evidence'] ?? null, $scope, 'restore'),
            'incidents' => self::incidents($input['incidents'] ?? null),
        ];
    }

    private static function compliance(mixed $rows, string $scope): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 200) {
            throw new InvalidArgumentException('compliance invalid.');
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('compliance obligation invalid.');
            }
            self::fields($row, [
                'obligation_id', 'state', 'scope', 'evidence_refs', 'source_ref',
                'observed_at', 'freshness', 'version', 'owner_ref',
                'review_at', 'expires_at',
            ], 'compliance obligation');

            $id = self::id($row['obligation_id'] ?? null, 'obligation_id');
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('obligation_id duplicated.');
            }
            $seen[$id] = true;

            $rowScope = self::scope($row['scope'] ?? null);
            if ($rowScope !== $scope) {
                throw new InvalidArgumentException('compliance scope mismatch.');
            }

            $reported = self::enum($row['state'] ?? null, self::COMPLIANCE_STATES, 'compliance state');
            $freshness = self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness');
            $reviewAt = self::time($row['review_at'] ?? null, 'review_at');
            $expiresAt = self::time($row['expires_at'] ?? null, 'expires_at');
            if ($expiresAt < $reviewAt) {
                throw new InvalidArgumentException('compliance expiry before review.');
            }

            $out[] = [
                'obligation_id' => $id,
                'state' => $freshness === 'fresh' ? $reported : 'unknown',
                'reported_state' => $reported,
                'scope' => $rowScope,
                'evidence_refs' => self::refs($row['evidence_refs'] ?? null, 'evidence_refs'),
                'source_ref' => self::ref($row['source_ref'] ?? null, 'source_ref'),
                'observed_at' => self::time($row['observed_at'] ?? null, 'observed_at'),
                'freshness' => $freshness,
                'version' => self::id($row['version'] ?? null, 'version'),
                'owner_ref' => self::ref($row['owner_ref'] ?? null, 'owner_ref'),
                'review_at' => $reviewAt,
                'expires_at' => $expiresAt,
            ];
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['obligation_id'], $b['obligation_id']));
        return $out;
    }

    private static function continuity(mixed $row, string $scope, string $kind): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($kind . ' evidence invalid.');
        }
        self::fields($row, [
            'scope', 'state', 'evidence_refs', 'source_ref', 'observed_at', 'freshness',
        ], $kind . ' evidence');

        $rowScope = self::scope($row['scope'] ?? null);
        if ($rowScope !== $scope) {
            throw new InvalidArgumentException($kind . ' scope mismatch.');
        }

        $allowed = $kind === 'backup' ? self::BACKUP_STATES : self::RESTORE_STATES;
        $reported = self::enum($row['state'] ?? null, $allowed, $kind . ' state');
        $freshness = self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness');

        return [
            'scope' => $rowScope,
            'state' => $freshness === 'fresh' ? $reported : 'unknown',
            'reported_state' => $reported,
            'evidence_refs' => self::refs($row['evidence_refs'] ?? null, 'evidence_refs'),
            'source_ref' => self::ref($row['source_ref'] ?? null, 'source_ref'),
            'observed_at' => self::time($row['observed_at'] ?? null, 'observed_at'),
            'freshness' => $freshness,
        ];
    }

    private static function incidents(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 200) {
            throw new InvalidArgumentException('incidents invalid.');
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('incident invalid.');
            }
            self::fields($row, [
                'incident_id', 'severity', 'capabilities', 'affected_scopes',
                'evidence_refs', 'source_ref', 'observed_at', 'freshness',
            ], 'incident');

            $id = self::id($row['incident_id'] ?? null, 'incident_id');
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('incident_id duplicated.');
            }
            $seen[$id] = true;

            $out[] = [
                'incident_id' => $id,
                'severity' => self::enum($row['severity'] ?? null, self::SEVERITIES, 'severity'),
                'capabilities' => self::ids($row['capabilities'] ?? null, 'capabilities'),
                'affected_scopes' => self::scopes($row['affected_scopes'] ?? null),
                'evidence_refs' => self::refs($row['evidence_refs'] ?? null, 'evidence_refs'),
                'source_ref' => self::ref($row['source_ref'] ?? null, 'source_ref'),
                'observed_at' => self::time($row['observed_at'] ?? null, 'observed_at'),
                'freshness' => self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness'),
            ];
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['incident_id'], $b['incident_id']));
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

    private static function scope(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:venture|project|institution):[a-z][a-z0-9-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
    }

    private static function scopes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 50) {
            throw new InvalidArgumentException('affected_scopes invalid.');
        }
        $out = [];
        foreach ($value as $scope) {
            $out[self::scope($scope)] = true;
        }
        $scopes = array_keys($out);
        sort($scopes, SORT_STRING);
        return $scopes;
    }

    private static function ids(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 50) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $out = [];
        foreach ($value as $id) {
            $out[self::id($id, $label)] = true;
        }
        $ids = array_keys($out);
        sort($ids, SORT_STRING);
        return $ids;
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
            $out[self::ref($ref, $label)] = true;
        }
        $refs = array_keys($out);
        sort($refs, SORT_STRING);
        return $refs;
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value) || strlen($value) < 8 || strlen($value) > 180
            || str_contains($value, '@')
            || str_contains($value, '..')
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

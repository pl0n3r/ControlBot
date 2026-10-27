<?php
declare(strict_types=1);

namespace ControlBot\Recovery;

/**
 * Comprobación puramente local de una muestra de backup sintética.
 * OK significa coherencia del fixture, NO backup real, autenticidad ni restore.
 */
final class BackupIntegrityVerifier
{
    private const FIELDS = [
        'version', 'artifact_ref', 'environment', 'algorithm', 'checksum',
        'size_bytes', 'created_at', 'verified_at',
    ];
    private const MAX_AGE_SECONDS = 86400;
    private const MAX_SAMPLE_BYTES = 2097152;

    /** @return array{status:string,reason:string} */
    public static function verify(array $manifest, string $sample, int $checkedAt): array
    {
        if ($manifest === [] || array_is_list($manifest)) {
            return self::result('INCOMPLETO', 'manifest_missing');
        }
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $manifest) || $manifest[$field] === null) {
                return self::result('INCOMPLETO', 'manifest_missing_field');
            }
        }
        $keys = array_keys($manifest);
        $expected = self::FIELDS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            return self::result('UNKNOWN', 'manifest_ambiguous');
        }
        if ($manifest['version'] !== 1 || $manifest['environment'] !== 'testing'
            || !is_string($manifest['artifact_ref'])
            || preg_match('/^artifact:synthetic:[a-z0-9._-]{3,80}$/D', $manifest['artifact_ref']) !== 1
            || $manifest['algorithm'] !== 'sha256') {
            return self::result('UNKNOWN', 'manifest_invalid');
        }
        if (!is_string($manifest['checksum'])
            || preg_match('/^[a-f0-9]{64}$/iD', $manifest['checksum']) !== 1) {
            return self::result('UNKNOWN', 'checksum_invalid');
        }
        if (!is_int($manifest['size_bytes']) || $manifest['size_bytes'] < 1
            || $manifest['size_bytes'] > self::MAX_SAMPLE_BYTES) {
            return self::result('UNKNOWN', 'size_invalid');
        }
        if (!is_int($manifest['created_at']) || !is_int($manifest['verified_at'])
            || $manifest['created_at'] < 1 || $manifest['verified_at'] < $manifest['created_at']
            || $checkedAt < 1 || $manifest['verified_at'] > $checkedAt) {
            return self::result('UNKNOWN', 'time_invalid');
        }
        if ($checkedAt - $manifest['verified_at'] > self::MAX_AGE_SECONDS) {
            return self::result('UNKNOWN', 'evidence_stale');
        }
        if ($sample === '') {
            return self::result('INCOMPLETO', 'sample_missing');
        }
        if (strlen($sample) > self::MAX_SAMPLE_BYTES) {
            return self::result('UNKNOWN', 'sample_oversized');
        }
        if (strlen($sample) !== $manifest['size_bytes']) {
            return self::result('CORRUPTO', 'size_mismatch');
        }
        if (!hash_equals(strtolower($manifest['checksum']), hash('sha256', $sample))) {
            return self::result('CORRUPTO', 'checksum_mismatch');
        }
        return self::result('OK', 'synthetic_integrity_only');
    }

    /** @return array{status:string,reason:string} */
    private static function result(string $status, string $reason): array
    {
        return ['status' => $status, 'reason' => $reason];
    }
}

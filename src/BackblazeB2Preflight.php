<?php
declare(strict_types=1);
namespace ControlBot\Infrastructure;

use InvalidArgumentException;

/**
 * Pure structural check of an explicitly sanitized b2_list_buckets projection.
 * It cannot attest the caller, provider, uploaded objects, backups or restore.
 */
final class BackblazeB2Preflight
{
    /** @return array{version:int,configuration_status:string,operational_status:string,reasons:list<string>} */
    public static function assess(array $bucket): array
    {
        self::allow($bucket, ['bucketType', 'fileLockConfiguration', 'defaultServerSideEncryption']);
        $reasons = [];

        if (!array_key_exists('bucketType', $bucket)) {
            $reasons[] = 'missing_metadata';
        } elseif (!is_string($bucket['bucketType'])) {
            throw new InvalidArgumentException('Invalid bucket metadata.');
        } elseif ($bucket['bucketType'] !== 'allPrivate') {
            $reasons[] = 'private_bucket_required';
        }

        if (!array_key_exists('fileLockConfiguration', $bucket)) {
            $reasons[] = 'missing_metadata';
        } else {
            $lock = self::reading($bucket['fileLockConfiguration']);
            if ($lock === null) {
                $reasons[] = 'retention_unreadable';
            } else {
                self::allow($lock, ['isFileLockEnabled', 'defaultRetention']);
                if (!isset($lock['isFileLockEnabled']) || !is_bool($lock['isFileLockEnabled'])) {
                    throw new InvalidArgumentException('Invalid lock metadata.');
                }
                if (!$lock['isFileLockEnabled']) {
                    $reasons[] = 'object_lock_disabled';
                }
                if (!array_key_exists('defaultRetention', $lock)) {
                    $reasons[] = 'default_retention_missing';
                } else {
                    $retention = self::object($lock['defaultRetention']);
                    self::allow($retention, ['mode', 'period']);
                    if (!array_key_exists('mode', $retention)
                        || !array_key_exists('period', $retention)) {
                        throw new InvalidArgumentException('Invalid retention metadata.');
                    }
                    if ($retention['mode'] === null && $retention['period'] === null) {
                        $reasons[] = 'default_retention_missing';
                    } elseif (!in_array($retention['mode'], ['governance', 'compliance'], true)) {
                        throw new InvalidArgumentException('Invalid retention metadata.');
                    } else {
                        $period = self::object($retention['period']);
                        self::allow($period, ['duration', 'unit']);
                        if (!isset($period['duration'], $period['unit'])
                            || !is_int($period['duration'])
                            || $period['duration'] < 1 || $period['duration'] > 3000
                            || !in_array($period['unit'], ['days', 'months', 'years'], true)) {
                            throw new InvalidArgumentException('Invalid retention metadata.');
                        }
                    }
                }
            }
        }

        if (!array_key_exists('defaultServerSideEncryption', $bucket)) {
            $reasons[] = 'missing_metadata';
        } else {
            $encryption = self::reading($bucket['defaultServerSideEncryption']);
            if ($encryption === null) {
                $reasons[] = 'encryption_unreadable';
            } else {
                self::allow($encryption, ['mode']);
                if (!array_key_exists('mode', $encryption)
                    || (!is_string($encryption['mode']) && $encryption['mode'] !== null)) {
                    throw new InvalidArgumentException('Invalid encryption metadata.');
                }
                if ($encryption['mode'] !== 'SSE-B2') {
                    $reasons[] = 'encryption_missing';
                }
            }
        }
        return [
            'version' => 1,
            'configuration_status' => $reasons === [] ? 'consistent' : 'incomplete',
            'operational_status' => 'UNKNOWN',
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function reading(mixed $raw): ?array
    {
        $read = self::object($raw);
        self::allow($read, ['isClientAuthorizedToRead', 'value']);
        if (!array_key_exists('isClientAuthorizedToRead', $read)
            || !is_bool($read['isClientAuthorizedToRead'])
            || !array_key_exists('value', $read)) {
            throw new InvalidArgumentException('Invalid permission metadata.');
        }
        if (!$read['isClientAuthorizedToRead']) {
            if ($read['value'] !== null) {
                throw new InvalidArgumentException('Invalid permission metadata.');
            }
            return null;
        }
        return self::object($read['value']);
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('Invalid metadata shape.');
        }
        return $value;
    }

    /** @param list<string> $fields */
    private static function allow(array $input, array $fields): void
    {
        foreach (array_keys($input) as $name) {
            if (!is_string($name) || !in_array($name, $fields, true)) {
                // Never interpolate untrusted field values or names into diagnostics.
                throw new InvalidArgumentException('Unrecognized metadata field.');
            }
        }
    }
}

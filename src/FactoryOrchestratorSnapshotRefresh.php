<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__ . '/FactoryOrchestratorSnapshotSource.php';

use RuntimeException;
use Throwable;

final class FactoryOrchestratorSnapshotRefreshFailure extends RuntimeException
{
    private const CODES = [
        'snapshot_target_invalid', 'snapshot_directory_unwritable', 'evidence_invalid',
        'snapshot_build_failed', 'snapshot_size_invalid', 'temp_write_failed',
        'atomic_rename_failed', 'clock_invalid', 'internal_error',
    ];
    private string $failureCode;

    public function __construct(string $failureCode, ?Throwable $previous = null)
    {
        $this->failureCode = in_array($failureCode, self::CODES, true) ? $failureCode : 'internal_error';
        parent::__construct('Snapshot refresh failed.', 0, $previous);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}

final class FactoryOrchestratorSnapshotRefresh
{
    private const MAX_BYTES = 2_000_000;
    private const TEMP_PREFIX = '.orchestrator-live-';
    private const SAFE_MODES = [0600, 0640];

    public static function refresh(
        callable $collector,
        string $snapshotPath,
        int $now,
        int $maxBytes = self::MAX_BYTES,
        array $io = []
    ): array {
        $temporary = null;

        try {
            $directory = self::targetDirectory($snapshotPath, $now, $maxBytes);
            try {
                $evidence = $collector();
            } catch (Throwable $exception) {
                throw self::failure('evidence_invalid', $exception);
            }
            if (!is_array($evidence)) {
                throw self::failure('evidence_invalid');
            }

            try {
                $snapshot = FactoryOrchestratorSnapshotSource::canonicalFromInjectedEvidence($evidence, $now);
                $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            } catch (Throwable $exception) {
                throw self::failure('snapshot_build_failed', $exception);
            }
            $bytes = strlen($encoded);
            if ($bytes < 2 || $bytes > $maxBytes) {
                throw self::failure('snapshot_size_invalid');
            }

            $tempnam = $io['tempnam'] ?? static fn (string $path, string $prefix): string|false => tempnam($path, $prefix);
            $writeTemp = $io['write_temp'] ?? static fn (string $path, string $data): int|false => file_put_contents($path, $data, LOCK_EX);
            $chmod = $io['chmod'] ?? static fn (string $path, int $mode): bool => chmod($path, $mode);
            $rename = $io['rename'] ?? static fn (string $from, string $to): bool => rename($from, $to);
            if (!is_callable($tempnam) || !is_callable($writeTemp) || !is_callable($chmod) || !is_callable($rename)) {
                throw self::failure('internal_error');
            }

            $temporary = $tempnam($directory, self::TEMP_PREFIX);
            if (!is_string($temporary) || $temporary === '' || $writeTemp($temporary, $encoded) !== $bytes) {
                throw self::failure('temp_write_failed');
            }
            if (!$chmod($temporary, 0640) && !self::hasSafeMode($temporary)) {
                throw self::failure('temp_write_failed');
            }
            clearstatcache(true, $temporary);
            if (filesize($temporary) !== $bytes) {
                throw self::failure('temp_write_failed');
            }

            if (!$rename($temporary, $snapshotPath)) {
                if (!self::replaceWithLock($snapshotPath, $encoded, $bytes, $io)) {
                    throw self::failure('atomic_rename_failed');
                }
                self::cleanup($temporary);
            }
            $temporary = null;
            clearstatcache(true, $snapshotPath);
            if (filesize($snapshotPath) !== $bytes) {
                throw self::failure('atomic_rename_failed');
            }

            return [
                'written' => true,
                'bytes' => $bytes,
                'observed_at' => $snapshot['observed_at'],
                'fingerprint' => $snapshot['fingerprint'],
            ];
        } catch (FactoryOrchestratorSnapshotRefreshFailure $exception) {
            self::cleanup($temporary);
            throw $exception;
        } catch (Throwable $exception) {
            self::cleanup($temporary);
            throw self::failure('internal_error', $exception);
        }
    }

    private static function targetDirectory(string $snapshotPath, int $now, int $maxBytes): string
    {
        if ($now < 1) {
            throw self::failure('clock_invalid');
        }
        if (
            $snapshotPath === ''
            || !str_starts_with($snapshotPath, DIRECTORY_SEPARATOR)
            || $maxBytes < 2
            || $maxBytes > self::MAX_BYTES
            || is_link($snapshotPath)
            || (file_exists($snapshotPath) && !is_file($snapshotPath))
        ) {
            throw self::failure('snapshot_target_invalid');
        }

        $directory = dirname($snapshotPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw self::failure('snapshot_directory_unwritable');
        }
        return $directory;
    }

    private static function replaceWithLock(string $snapshotPath, string $encoded, int $bytes, array $io): bool
    {
        if (is_link($snapshotPath)) {
            return false;
        }
        $writeTarget = $io['write_target'] ?? static fn (string $path, string $data): int|false => file_put_contents($path, $data, LOCK_EX);
        if (!is_callable($writeTarget)) {
            throw self::failure('internal_error');
        }

        $previous = is_file($snapshotPath) ? file_get_contents($snapshotPath) : null;
        $previous = is_string($previous) && strlen($previous) <= self::MAX_BYTES ? $previous : null;
        $written = $writeTarget($snapshotPath, $encoded);
        clearstatcache(true, $snapshotPath);
        $verified = $written === $bytes
            && is_file($snapshotPath)
            && filesize($snapshotPath) === $bytes
            && file_get_contents($snapshotPath) === $encoded;

        if ($verified) {
            @chmod($snapshotPath, 0640);
            return self::hasSafeMode($snapshotPath);
        }
        if ($previous !== null) {
            @file_put_contents($snapshotPath, $previous, LOCK_EX);
        } elseif (file_exists($snapshotPath)) {
            @unlink($snapshotPath);
        }
        return false;
    }

    private static function hasSafeMode(string $path): bool
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        return is_int($permissions) && in_array($permissions & 0777, self::SAFE_MODES, true);
    }

    private static function failure(string $code, ?Throwable $previous = null): FactoryOrchestratorSnapshotRefreshFailure
    {
        return new FactoryOrchestratorSnapshotRefreshFailure($code, $previous);
    }

    private static function cleanup(?string $temporary): void
    {
        if ($temporary !== null && file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

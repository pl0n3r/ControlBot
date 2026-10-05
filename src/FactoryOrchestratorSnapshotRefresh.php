<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__ . '/FactoryOrchestratorSnapshotSource.php';

use RuntimeException;
use Throwable;

final class FactoryOrchestratorSnapshotRefreshFailure extends RuntimeException
{
    public function __construct(private readonly string $failureCode)
    {
        parent::__construct($failureCode);
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
                $encoded = json_encode(
                    $snapshot,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                ) . PHP_EOL;
            } catch (FactoryOrchestratorSnapshotRefreshFailure $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw self::failure('snapshot_build_failed', $exception);
            }

            $bytes = strlen($encoded);
            if ($bytes < 2 || $bytes > $maxBytes) {
                throw self::failure('snapshot_size_invalid');
            }

            $tempnam = self::operation(
                $io,
                'tempnam',
                static fn (string $path, string $prefix): string|false => tempnam($path, $prefix)
            );
            $writeTemp = self::operation(
                $io,
                'write_temp',
                static fn (string $path, string $data): int|false => file_put_contents($path, $data, LOCK_EX)
            );
            $chmod = self::operation(
                $io,
                'chmod',
                static fn (string $path, int $mode): bool => chmod($path, $mode)
            );
            $rename = self::operation(
                $io,
                'rename',
                static fn (string $from, string $to): bool => rename($from, $to)
            );

            $temporary = $tempnam($directory, self::TEMP_PREFIX);
            if (!is_string($temporary) || $temporary === '') {
                throw self::failure('temp_write_failed');
            }

            $written = $writeTemp($temporary, $encoded);
            if ($written !== $bytes) {
                throw self::failure('temp_write_failed');
            }

            if (!$chmod($temporary, 0640) && !self::hasSafeMode($temporary)) {
                throw self::failure('temp_write_failed');
            }

            clearstatcache(true, $temporary);
            $size = filesize($temporary);
            if (!is_int($size) || $size !== $bytes) {
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
            $finalSize = filesize($snapshotPath);
            if (!is_int($finalSize) || $finalSize !== $bytes) {
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

    private static function replaceWithLock(
        string $snapshotPath,
        string $encoded,
        int $bytes,
        array $io
    ): bool {
        if (is_link($snapshotPath)) {
            return false;
        }

        $writeTarget = self::operation(
            $io,
            'write_target',
            static fn (string $path, string $data): int|false => file_put_contents($path, $data, LOCK_EX)
        );
        $previous = null;
        if (is_file($snapshotPath)) {
            $existing = file_get_contents($snapshotPath);
            if (is_string($existing) && strlen($existing) <= self::MAX_BYTES) {
                $previous = $existing;
            }
        }

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
        if (!is_int($permissions)) {
            return false;
        }
        return in_array($permissions & 0777, self::SAFE_MODES, true);
    }

    private static function operation(array $io, string $name, callable $default): callable
    {
        $candidate = $io[$name] ?? $default;
        if (!is_callable($candidate)) {
            throw self::failure('internal_error');
        }
        return $candidate;
    }

    private static function failure(
        string $code,
        ?Throwable $previous = null
    ): FactoryOrchestratorSnapshotRefreshFailure {
        return new FactoryOrchestratorSnapshotRefreshFailure($code, $previous);
    }

    private static function cleanup(?string $temporary): void
    {
        if ($temporary !== null && file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

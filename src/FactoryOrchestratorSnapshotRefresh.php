<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__ . '/FactoryOrchestratorSnapshotSource.php';

use RuntimeException;
use Throwable;

final class FactoryOrchestratorSnapshotRefresh
{
    private const MAX_BYTES = 2_000_000;
    private const TEMP_PREFIX = '.orchestrator-live-';

    public static function refresh(
        callable $collector,
        string $snapshotPath,
        int $now,
        int $maxBytes = self::MAX_BYTES
    ): array {
        $temporary = null;

        try {
            $directory = self::targetDirectory($snapshotPath, $now, $maxBytes);
            $evidence = $collector();
            if (!is_array($evidence)) {
                throw new RuntimeException('Injected evidence invalid.');
            }

            $snapshot = FactoryOrchestratorSnapshotSource::canonicalFromInjectedEvidence($evidence, $now);
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            $bytes = strlen($encoded);
            if ($bytes < 2 || $bytes > $maxBytes) {
                throw new RuntimeException('Snapshot size invalid.');
            }

            $temporary = tempnam($directory, self::TEMP_PREFIX);
            if (!is_string($temporary)) {
                throw new RuntimeException('Temporary snapshot unavailable.');
            }

            $written = file_put_contents($temporary, $encoded, LOCK_EX);
            if ($written !== $bytes || !chmod($temporary, 0640)) {
                throw new RuntimeException('Temporary snapshot write failed.');
            }

            clearstatcache(true, $temporary);
            $size = filesize($temporary);
            if (!is_int($size) || $size !== $bytes) {
                throw new RuntimeException('Temporary snapshot verification failed.');
            }

            if (!rename($temporary, $snapshotPath)) {
                throw new RuntimeException('Atomic snapshot replacement failed.');
            }
            $temporary = null;

            return [
                'written' => true,
                'bytes' => $bytes,
                'observed_at' => $snapshot['observed_at'],
                'fingerprint' => $snapshot['fingerprint'],
            ];
        } catch (Throwable) {
            self::cleanup($temporary);
            throw new RuntimeException('Snapshot refresh failed.');
        }
    }

    private static function targetDirectory(string $snapshotPath, int $now, int $maxBytes): string
    {
        if (
            $now < 1
            || $snapshotPath === ''
            || !str_starts_with($snapshotPath, DIRECTORY_SEPARATOR)
            || $maxBytes < 2
            || $maxBytes > self::MAX_BYTES
            || is_link($snapshotPath)
            || (file_exists($snapshotPath) && !is_file($snapshotPath))
        ) {
            throw new RuntimeException('Snapshot target invalid.');
        }

        $directory = dirname($snapshotPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Snapshot directory invalid.');
        }

        return $directory;
    }

    private static function cleanup(?string $temporary): void
    {
        if ($temporary !== null && file_exists($temporary)) {
            @unlink($temporary);
        }
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__ . '/FactoryOrchestratorSnapshotRefresh.php';

final class FactoryOrchestratorSnapshotCron
{
    private const ENABLED_KEY = 'CONTROLBOT_ORCHESTRATOR_CRON_ENABLED';

    public static function run(
        array $serverConfiguration,
        callable $collector,
        string $snapshotPath,
        int $now,
        array $io = []
    ): array {
        if (($serverConfiguration[self::ENABLED_KEY] ?? '') !== '1') {
            return [
                'executed' => false,
                'state' => 'disabled',
            ];
        }

        if ($now < 1) {
            throw new FactoryOrchestratorSnapshotRefreshFailure('clock_invalid');
        }
        if ($snapshotPath === '' || !str_starts_with($snapshotPath, DIRECTORY_SEPARATOR)) {
            throw new FactoryOrchestratorSnapshotRefreshFailure('snapshot_target_invalid');
        }

        try {
            $result = FactoryOrchestratorSnapshotRefresh::refresh(
                $collector, $snapshotPath, $now, 2_000_000, $io
            );
            return ['executed' => true, 'state' => 'refreshed', ...$result];
        } catch (FactoryOrchestratorSnapshotRefreshFailure $failure) {
            // Retain a verified last-success timestamp, never stale success data.
            // The existing atomic writer guarantees a failed stale write cannot
            // pretend to be a refreshed snapshot.
            $lastSuccess = self::lastSuccessAt($snapshotPath, $now);
            $reason = $failure->failureCode();
            $marker = ['collector_failure' => [
                'freshness' => 'stale', 'reason' => $reason,
                'last_success_at' => $lastSuccess, 'detected_at' => $now,
            ]];
            $result = FactoryOrchestratorSnapshotRefresh::refresh(
                static fn (): array => $marker, $snapshotPath, $now, 2_000_000, $io
            );
            return ['executed' => true, 'state' => 'stale', 'reason' => $reason, ...$result];
        }
    }

    private static function lastSuccessAt(string $path,int $now): ?int
    {
        try {
            if(is_link($path)||!is_file($path))return null;
            $size=filesize($path);
            if(!is_int($size)||$size<2||$size>2_000_000)return null;
            $bytes=file_get_contents($path);
            if(!is_string($bytes)||strlen($bytes)!==$size)return null;
            $previous=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($previous)||array_is_list($previous))return null;
            // Recheck content hash and complete projection, not just timestamp.
            FactoryLiveOrchestratorSnapshot::build($previous,$now);
            if(array_key_exists('collector_failure',$previous))
                return $previous['collector_failure']['last_success_at'];
            return $previous['observed_at'];
        } catch(\Throwable) {
            return null;
        }
    }
}

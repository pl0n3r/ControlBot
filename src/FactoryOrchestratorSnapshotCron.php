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

        $result = FactoryOrchestratorSnapshotRefresh::refresh(
            $collector,
            $snapshotPath,
            $now,
            2_000_000,
            $io
        );

        return [
            'executed' => true,
            'state' => 'refreshed',
            ...$result,
        ];
    }
}

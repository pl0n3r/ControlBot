<?php
declare(strict_types=1);

require __DIR__ . '/../src/FactoryOrchestratorSnapshotCron.php';

use ControlBot\Business\FactoryOrchestratorSnapshotCron;
use ControlBot\Business\FactoryOrchestratorSnapshotRefreshFailure;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "orchestrator-snapshot-cron: cli only\n");
    exit(64);
}

$enabled = getenv('CONTROLBOT_ORCHESTRATOR_CRON_ENABLED') ?: '';
if ($enabled !== '1') {
    echo json_encode(
        ['executed' => false, 'state' => 'disabled'],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    ), PHP_EOL;
    exit(0);
}

$evidencePath = getenv('CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH') ?: '';
$snapshotPath = getenv('CONTROLBOT_ORCHESTRATOR_SNAPSHOT_PATH') ?: '';
$diagnosticsEnabled = (getenv('CONTROLBOT_ORCHESTRATOR_SNAPSHOT_DIAGNOSTICS') ?: '') === '1';

$diagnostics = static function (string $path) use ($diagnosticsEnabled): string {
    if (!$diagnosticsEnabled || $path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return '';
    }

    $directory = dirname($path);
    $exists = is_dir($directory);
    $writable = $exists && is_writable($directory);
    $mode = 'unknown';
    if ($exists) {
        $permissions = fileperms($directory);
        if (is_int($permissions)) {
            $mode = sprintf('%04o', $permissions & 0777);
        }
    }

    $ownerMatch = 'unknown';
    if ($exists && function_exists('posix_geteuid')) {
        $owner = fileowner($directory);
        $effective = posix_geteuid();
        if (is_int($owner) && is_int($effective)) {
            $ownerMatch = $owner === $effective ? '1' : '0';
        }
    }

    return sprintf(
        ' dir_exists=%d dir_writable=%d dir_owner_match=%s dir_mode=%s',
        $exists ? 1 : 0,
        $writable ? 1 : 0,
        $ownerMatch,
        $mode
    );
};

try {
    if (
        $evidencePath === ''
        || !str_starts_with($evidencePath, DIRECTORY_SEPARATOR)
        || is_link($evidencePath)
        || !is_file($evidencePath)
    ) {
        throw new FactoryOrchestratorSnapshotRefreshFailure('evidence_invalid');
    }
    if ($snapshotPath === '' || !str_starts_with($snapshotPath, DIRECTORY_SEPARATOR)) {
        throw new FactoryOrchestratorSnapshotRefreshFailure('snapshot_target_invalid');
    }

    $collector = static function () use ($evidencePath): array {
        clearstatcache(true, $evidencePath);
        $size = filesize($evidencePath);
        if (!is_int($size) || $size < 2 || $size > 2_000_000) {
            throw new FactoryOrchestratorSnapshotRefreshFailure('evidence_invalid');
        }

        $raw = file_get_contents($evidencePath);
        if (!is_string($raw)) {
            throw new FactoryOrchestratorSnapshotRefreshFailure('evidence_invalid');
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new FactoryOrchestratorSnapshotRefreshFailure('evidence_invalid', $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FactoryOrchestratorSnapshotRefreshFailure('evidence_invalid');
        }

        return $decoded;
    };

    $result = FactoryOrchestratorSnapshotCron::run(
        ['CONTROLBOT_ORCHESTRATOR_CRON_ENABLED' => $enabled],
        $collector,
        $snapshotPath,
        time()
    );
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (FactoryOrchestratorSnapshotRefreshFailure $exception) {
    fwrite(
        STDERR,
        'orchestrator-snapshot-cron: '
        . $exception->failureCode()
        . $diagnostics($snapshotPath)
        . PHP_EOL
    );
    exit(70);
} catch (Throwable) {
    fwrite(
        STDERR,
        'orchestrator-snapshot-cron: internal_error'
        . $diagnostics($snapshotPath)
        . PHP_EOL
    );
    exit(70);
}

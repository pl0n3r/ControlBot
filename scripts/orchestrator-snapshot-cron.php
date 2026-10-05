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
    $mode = 'unknown';
    $ownerMatch = 'unknown';
    if ($exists) {
        $permissions = fileperms($directory);
        $mode = is_int($permissions) ? sprintf('%04o', $permissions & 0777) : 'unknown';
        if (function_exists('posix_geteuid')) {
            $owner = fileowner($directory);
            $effective = posix_geteuid();
            $ownerMatch = is_int($owner) && is_int($effective) ? ($owner === $effective ? '1' : '0') : 'unknown';
        }
    }
    return sprintf(
        ' dir_exists=%d dir_writable=%d dir_owner_match=%s dir_mode=%s',
        $exists ? 1 : 0,
        $exists && is_writable($directory) ? 1 : 0,
        $ownerMatch,
        $mode
    );
};

if (
    $evidencePath === ''
    || !str_starts_with($evidencePath, DIRECTORY_SEPARATOR)
    || is_link($evidencePath)
    || !is_file($evidencePath)
) {
    fwrite(STDERR, 'orchestrator-snapshot-cron: evidence_invalid' . $diagnostics($snapshotPath) . PHP_EOL);
    exit(70);
}
if ($snapshotPath === '' || !str_starts_with($snapshotPath, DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "orchestrator-snapshot-cron: snapshot_target_invalid\n");
    exit(70);
}

$collector = static function () use ($evidencePath): array {
    clearstatcache(true, $evidencePath);
    $size = filesize($evidencePath);
    if (!is_int($size) || $size < 2 || $size > 2_000_000) {
        throw new RuntimeException('Injected evidence invalid.');
    }

    $raw = file_get_contents($evidencePath);
    if (!is_string($raw)) {
        throw new RuntimeException('Injected evidence invalid.');
    }

    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('Injected evidence invalid.');
    }
    return $decoded;
};

try {
    $result = FactoryOrchestratorSnapshotCron::run(
        ['CONTROLBOT_ORCHESTRATOR_CRON_ENABLED' => $enabled],
        $collector,
        $snapshotPath,
        time()
    );
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (FactoryOrchestratorSnapshotRefreshFailure $exception) {
    fwrite(STDERR, 'orchestrator-snapshot-cron: ' . $exception->failureCode() . $diagnostics($snapshotPath) . PHP_EOL);
    exit(70);
} catch (Throwable) {
    fwrite(STDERR, 'orchestrator-snapshot-cron: internal_error' . $diagnostics($snapshotPath) . PHP_EOL);
    exit(70);
}

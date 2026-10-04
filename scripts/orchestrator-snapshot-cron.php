<?php
declare(strict_types=1);

require __DIR__ . '/../src/FactoryOrchestratorSnapshotCron.php';

use ControlBot\Business\FactoryOrchestratorSnapshotCron;

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

if (
    $evidencePath === ''
    || $snapshotPath === ''
    || !str_starts_with($evidencePath, DIRECTORY_SEPARATOR)
    || !str_starts_with($snapshotPath, DIRECTORY_SEPARATOR)
    || is_link($evidencePath)
    || !is_file($evidencePath)
) {
    fwrite(STDERR, "orchestrator-snapshot-cron: invalid server configuration\n");
    exit(64);
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
} catch (Throwable) {
    fwrite(STDERR, "orchestrator-snapshot-cron: execution failed\n");
    exit(70);
}

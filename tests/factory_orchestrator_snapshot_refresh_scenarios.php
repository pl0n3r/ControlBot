<?php
declare(strict_types=1);

require __DIR__ . '/../src/FactoryOrchestratorSnapshotRefresh.php';
require __DIR__ . '/../src/FactoryOrchestratorWebEntrypoint.php';

use ControlBot\Business\FactoryOrchestratorSnapshotRefresh;
use ControlBot\Business\FactoryOrchestratorWebEntrypoint;

$scenario = $argv[1] ?? '';

function evidence(int $observedAt = 210): array
{
    return [
        'work' => [[
            'id' => 'work:controlbot-660',
            'authority' => 'github_project_snapshot',
            'state' => 'pending',
            'source_ref' => 'github:pl0n3r/ControlBot#660',
            'observed_at' => $observedAt,
            'freshness' => 'current',
            'data' => [
                'repository_ref' => 'pl0n3r/ControlBot',
                'issue_ref' => 'github:pl0n3r/ControlBot#660',
                'status' => 'reserved',
                'progress_percent' => 50,
                'progress_evidence' => 'github:pl0n3r/ControlBot#660',
            ],
        ]],
    ];
}

function workspace(): array
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'controlbot-refresh-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('workspace unavailable');
    }
    return [$directory, $directory . DIRECTORY_SEPARATOR . 'orchestrator-live.json'];
}

function cleanupWorkspace(string $directory): void
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
    foreach (glob($directory . DIRECTORY_SEPARATOR . '.*') ?: [] as $path) {
        $name = basename($path);
        if ($name !== '.' && $name !== '..' && (is_file($path) || is_link($path))) {
            unlink($path);
        }
    }
    rmdir($directory);
}

function failure(callable $operation): string
{
    try {
        $operation();
        return 'NO_FAILURE';
    } catch (Throwable $error) {
        return $error->getMessage();
    }
}

if ($scenario === 'success') {
    [$directory, $path] = workspace();
    try {
        file_put_contents($path, "previous\n");
        $result = FactoryOrchestratorSnapshotRefresh::refresh(
            static fn (): array => evidence(),
            $path,
            220
        );
        $raw = file_get_contents($path);
        $snapshot = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $live = FactoryOrchestratorWebEntrypoint::localSnapshot($path, 220);
        $boundary = FactoryOrchestratorWebEntrypoint::localSnapshot($path, 520);
        $expired = FactoryOrchestratorWebEntrypoint::localSnapshot($path, 521);

        echo json_encode([
            'written' => $result['written'],
            'bytes' => $result['bytes'],
            'observed_at' => $snapshot['observed_at'],
            'fingerprint_matches' => hash_equals($snapshot['fingerprint'], $result['fingerprint']),
            'mode' => sprintf('%04o', fileperms($path) & 0777),
            'live_status' => $live['fronts'][0]['status'] ?? null,
            'boundary_status' => $boundary['fronts'][0]['status'] ?? null,
            'expired_state' => $expired['central']['activity_state'],
            'expired_fronts' => $expired['fronts'],
            'temporary_count' => count(glob($directory . DIRECTORY_SEPARATOR . '.orchestrator-live-*') ?: []),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } finally {
        cleanupWorkspace($directory);
    }
    exit;
}

if ($scenario === 'fail_closed') {
    [$directory, $path] = workspace();
    try {
        FactoryOrchestratorSnapshotRefresh::refresh(
            static fn (): array => evidence(),
            $path,
            220
        );
        $baseline = file_get_contents($path);
        $baselineHash = hash('sha256', $baseline);

        $collectionMessage = failure(
            static function () use ($path): array {
                return FactoryOrchestratorSnapshotRefresh::refresh(
                    static function (): array {
                        throw new RuntimeException('Bearer do-not-echo-token');
                    },
                    $path,
                    221
                );
            }
        );
        $collectionPreserved = hash_equals($baselineHash, hash('sha256', file_get_contents($path)));

        $unsafe = evidence();
        $unsafe['work'][0]['data']['api_token'] = 'do-not-echo-secret';
        $sensitiveMessage = failure(
            static fn (): array => FactoryOrchestratorSnapshotRefresh::refresh(
                static fn (): array => $unsafe,
                $path,
                221
            )
        );
        $sensitivePreserved = hash_equals($baselineHash, hash('sha256', file_get_contents($path)));

        $sizeMessage = failure(
            static fn (): array => FactoryOrchestratorSnapshotRefresh::refresh(
                static fn (): array => evidence(),
                $path,
                221,
                64
            )
        );
        $sizePreserved = hash_equals($baselineHash, hash('sha256', file_get_contents($path)));

        $invalidTypeMessage = failure(
            static fn (): array => FactoryOrchestratorSnapshotRefresh::refresh(
                static fn (): string => 'invalid',
                $path,
                221
            )
        );
        $invalidTypePreserved = hash_equals($baselineHash, hash('sha256', file_get_contents($path)));

        $messages = [$collectionMessage, $sensitiveMessage, $sizeMessage, $invalidTypeMessage];
        $live = FactoryOrchestratorWebEntrypoint::localSnapshot($path, 221);

        echo json_encode([
            'collection_preserved' => $collectionPreserved,
            'sensitive_preserved' => $sensitivePreserved,
            'size_preserved' => $sizePreserved,
            'invalid_type_preserved' => $invalidTypePreserved,
            'messages' => $messages,
            'secret_echo' => preg_match('/(?:do-not-echo|bearer|token|secret)/i', implode(' ', $messages)) === 1,
            'temporary_count' => count(glob($directory . DIRECTORY_SEPARATOR . '.orchestrator-live-*') ?: []),
            'live_status' => $live['fronts'][0]['status'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } finally {
        cleanupWorkspace($directory);
    }
    exit;
}

fwrite(STDERR, "scenario invalid\n");
exit(2);

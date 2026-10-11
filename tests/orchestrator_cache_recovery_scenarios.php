<?php
declare(strict_types=1);

require __DIR__ . '/../src/FactoryOrchestratorLiveCache.php';

use ControlBot\Business\FactoryOrchestratorLiveCache;

function view(int $now = 220): array
{
    $body = [
        'version' => 1, 'observed_at' => $now, 'source_snapshot' => str_repeat('a', 64),
        'read_only' => true, 'central' => [
            'activity_state' => 'UNKNOWN', 'available' => null, 'reserved' => null,
            'blocked' => null, 'source_ref' => null, 'observed_at' => null,
            'freshness' => 'unknown', 'age_seconds' => null,
        ],
        'fronts' => [], 'owner_decisions' => [],
    ];
    return $body + ['fingerprint' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
}

function temporaryCache(): array
{
    $dir = sys_get_temp_dir() . '/cb769-' . bin2hex(random_bytes(5));
    if (!mkdir($dir, 0700)) throw new RuntimeException('test temp directory unavailable');
    return [$dir, $dir . '/cache.json'];
}

function clean(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $path) @unlink($path);
    @rmdir($dir);
}

$scenario = $argv[1] ?? '';
[$dir, $path] = temporaryCache();
try {
    if ($scenario === 'corrupt-json') {
        file_put_contents($path, '{invalid-json');
        $calls = 0;
        $load = static function () use (&$calls): array { $calls++; return view(); };
        $a = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, $load);
        $b = FactoryOrchestratorLiveCache::remember($path, 222, 10, 60, 20, $load);
        echo json_encode(['statuses' => [$a['status'], $b['status']], 'calls' => $calls,
            'payload' => $b['payload']['central']['activity_state'],
            'persisted' => json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR)['payload']['fingerprint'] === view()['fingerprint']], JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }
    if ($scenario === 'invalid-sources') {
        $calls = 0; $rejects = [];
        foreach (['invalid-json', 'bad-shape', 'forged-fingerprint',
            'consistent-partial', 'impossible-central'] as $case) {
            if ($case === 'invalid-json') file_put_contents($path, '{invalid-json');
            else {
                $payload = view();
                if ($case === 'bad-shape') $payload['version'] = 2;
                elseif ($case === 'forged-fingerprint') $payload['fingerprint'] = str_repeat('f', 64);
                else {
                    if ($case === 'consistent-partial') $payload['central'] = ['activity_state' => 'ACTIVE'];
                    else {
                        $payload['central'] = [
                            'activity_state' => 'ACTIVE', 'available' => 0, 'reserved' => 0,
                            'blocked' => 0, 'source_ref' => 'synthetic', 'observed_at' => 220,
                            'freshness' => 'current', 'age_seconds' => 0,
                        ];
                    }
                    $body = $payload;
                    unset($body['fingerprint']);
                    $payload['fingerprint'] = hash(
                        'sha256', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    );
                }
                file_put_contents($path, json_encode(['cached_at' => 210, 'next_refresh_at' => 230, 'payload' => $payload], JSON_THROW_ON_ERROR));
            }
            try {
                FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20,
                    static function () use (&$calls): array { $calls++; throw new RuntimeException('source down'); });
                $rejects[$case] = false;
            } catch (RuntimeException $e) {
                $rejects[$case] = $e->getMessage() === 'snapshot unavailable';
            }
        }
        $payload = view(); $payload['fingerprint'] = str_repeat('f', 64);
        file_put_contents($path, json_encode(['cached_at' => 210, 'next_refresh_at' => 230, 'payload' => $payload], JSON_THROW_ON_ERROR));
        $recovered = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20,
            static function () use (&$calls): array { $calls++; return view(); });
        file_put_contents($path, json_encode(['cached_at' => 225, 'next_refresh_at' => 240, 'payload' => view()], JSON_THROW_ON_ERROR));
        $future = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20,
            static function () use (&$calls): array { $calls++; return view(); });
        echo json_encode(['rejected' => $rejects, 'calls' => $calls,
            'recovered' => $recovered['status'], 'future' => $future['status'],
            'fingerprint' => $recovered['payload']['fingerprint']], JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }
    if ($scenario === 'ttl-stale') {
        $calls = 0;
        $load = static function () use (&$calls): array { $calls++; if ($calls > 1) throw new RuntimeException('offline'); return view(); };
        $times = [220, 225, 231, 241, 242]; $rows = [];
        foreach ($times as $now) $rows[] = FactoryOrchestratorLiveCache::remember($path, $now, 10, 60, 20, $load);
        echo json_encode(['statuses' => array_column($rows, 'status'), 'calls' => $calls,
            'age' => $rows[3]['age_seconds'], 'temp' => count(glob($dir . '/.orchestrator-*') ?: []),
            'read_only' => $rows[4]['payload']['read_only']], JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }
    if ($scenario === 'two-requests') {
        $malformed = view();
        $malformed['central'] = ['activity_state' => 'ACTIVE'];
        $body = $malformed;
        unset($body['fingerprint']);
        $malformed['fingerprint'] = hash(
            'sha256', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        file_put_contents($path, json_encode(['cached_at' => 210, 'next_refresh_at' => 230,
            'payload' => $malformed], JSON_THROW_ON_ERROR));
        $calls = 0;
        $load = static function () use (&$calls): array { $calls++; return view(); };
        $first = FactoryOrchestratorLiveCache::remember($path, 220, 10, 60, 20, $load);
        $second = FactoryOrchestratorLiveCache::remember($path, 223, 10, 60, 20, $load);
        echo json_encode(['statuses' => [$first['status'], $second['status']], 'calls' => $calls,
            'activity' => [$first['payload']['central']['activity_state'], $second['payload']['central']['activity_state']],
            'age' => $second['age_seconds']], JSON_THROW_ON_ERROR), PHP_EOL;
        exit;
    }
    fwrite(STDERR, "unknown case\n"); exit(2);
} finally {
    clean($dir);
}

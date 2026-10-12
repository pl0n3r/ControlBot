<?php
declare(strict_types=1);

require __DIR__ . '/../src/FactoryLiveSnapshot.php';
require __DIR__ . '/../src/FactoryLiveOrchestratorSnapshot.php';
require __DIR__ . '/../src/FactoryOrchestratorLiveCache.php';
require __DIR__ . '/../src/FactoryOrchestratorLiveEndpoint.php';

use ControlBot\Business\FactoryLiveOrchestratorSnapshot;
use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryOrchestratorLiveCache;
use ControlBot\Business\FactoryOrchestratorLiveEndpoint;

function view(): array
{
    return FactoryLiveOrchestratorSnapshot::build(FactoryLiveSnapshot::build(['batches' => []], 200), 220);
}

function dirx(): string
{
    $dir = sys_get_temp_dir() . '/cb-orch-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    return $dir;
}

function endpoint(string $path, string $owner = 'pl0n3r', string $user = 'pl0n3r', ?int &$calls = null): array
{
    $dir = dirx();
    $calls ??= 0;
    try {
        return FactoryOrchestratorLiveEndpoint::handle(
            ['method' => 'GET', 'path' => $path, 'remote_user' => $user],
            ['owner_login' => $owner, 'cache_path' => $dir . '/cache.json',
             'ttl_seconds' => 10, 'stale_seconds' => 60, 'refresh_budget_seconds' => 20],
            220,
            static function () use (&$calls): array { $calls++; return view(); },
        );
    } finally {
        @unlink($dir . '/cache.json'); @rmdir($dir);
    }
}

$scenario = $argv[1] ?? '';
if ($scenario === 'html') { echo json_encode(endpoint('/'), JSON_THROW_ON_ERROR), PHP_EOL; exit; }
if ($scenario === 'json') { echo json_encode(endpoint('/api/orchestrator-live'), JSON_THROW_ON_ERROR), PHP_EOL; exit; }
if ($scenario === 'auth') {
    $calls = 0;
    $missing = endpoint('/', '', 'pl0n3r', $calls);
    $denied = endpoint('/', 'pl0n3r', 'other', $calls);
    echo json_encode(['missing'=>$missing,'denied'=>$denied,'calls'=>$calls], JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
if ($scenario === 'cache') {
    $dir = dirx(); $path = $dir . '/cache.json'; $calls = 0;
    $load = static function () use (&$calls): array { $calls++; if ($calls > 1) throw new RuntimeException('down'); return view(); };
    $rows = [
        FactoryOrchestratorLiveCache::remember($path,220,10,60,20,$load),
        FactoryOrchestratorLiveCache::remember($path,225,10,60,20,$load),
        FactoryOrchestratorLiveCache::remember($path,231,10,60,20,$load),
        FactoryOrchestratorLiveCache::remember($path,241,10,60,20,$load),
        FactoryOrchestratorLiveCache::remember($path,242,10,60,20,$load),
    ];
    @unlink($path); @rmdir($dir);
    echo json_encode(['statuses'=>array_column($rows,'status'),'calls'=>$calls,'age'=>$rows[3]['age_seconds']], JSON_THROW_ON_ERROR), PHP_EOL; exit;
}
if ($scenario === 'blocker-schema') {
    // A self-consistent digest is not proof that an injected projection is safe.
    $ref = 'github:pl0n3r/ControlBot#753';
    $unknown = [
        'issue_ref'=>$ref, 'source_ref'=>$ref, 'observed_at'=>200,
        'freshness'=>'current', 'cause'=>'UNKNOWN', 'actor'=>'UNKNOWN',
        'next_action'=>'UNKNOWN', 'blocked_at'=>null, 'age_seconds'=>null,
        'mvp_impact'=>null, 'evidence_status'=>'UNKNOWN',
    ];
    $declared = array_replace($unknown, [
        'cause'=>'Verificacion CI pendiente', 'actor'=>'agent',
        'next_action'=>'Repetir pruebas sintéticas', 'blocked_at'=>180,
        'age_seconds'=>40, 'mvp_impact'=>true, 'evidence_status'=>'DECLARED',
    ]);
    $cases = [
        'empty'=>[], 'unknown'=>[$unknown], 'declared'=>[$declared],
        'short_declared'=>[array_replace($declared, ['cause'=>'CI', 'next_action'=>'QA'])],
        'bad_type'=>'not an array',
        'too_many'=>array_fill(0, 51, $unknown),
        'wrong_source'=>[array_replace($unknown, ['source_ref'=>'github:pl0n3r/Factory#753'])],
        'cross_repo'=>[array_replace($unknown, [
            'issue_ref'=>'github:outsider/repo#753', 'source_ref'=>'github:outsider/repo#753',
        ])],
        'future'=>[array_replace($declared, ['blocked_at'=>221, 'age_seconds'=>-1])],
        'wrong_age'=>[array_replace($declared, ['age_seconds'=>41])],
        'sensitive'=>[array_replace($declared, ['cause'=>'password visible'])],
        'forged_unknown'=>[array_replace($unknown, ['cause'=>'Acceso autorizado'])],
        'extra_key'=>[array_replace($unknown, ['author'=>'untrusted'])],
        'duplicated'=>[$unknown, $unknown],
    ];
    $accepted = [];
    foreach ($cases as $label=>$rows) {
        $projection = view();
        $projection['blocker_explanations'] = $rows;
        $copy = $projection; unset($copy['fingerprint']);
        $projection['fingerprint'] = hash('sha256', json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $dir = dirx(); $path = $dir.'/cache.json';
        try {
            $cached = FactoryOrchestratorLiveCache::remember(
                $path, 220, 10, 60, 20, static fn (): array => $projection
            );
            $accepted[$label] = $cached['status'] === 'refreshed'
                && $cached['payload']['blocker_explanations'] === $rows;
        } catch (Throwable) {
            $accepted[$label] = false;
        } finally {
            @unlink($path); @rmdir($dir);
        }
    }
    echo json_encode($accepted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL; exit;
}
fwrite(STDERR, "scenario invalid\n"); exit(2);

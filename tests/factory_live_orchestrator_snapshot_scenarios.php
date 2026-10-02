<?php
declare(strict_types=1);

require __DIR__.'/../src/FactoryLiveSnapshot.php';
require __DIR__.'/../src/FactoryLiveOrchestratorSnapshot.php';

use ControlBot\Business\FactoryLiveOrchestratorSnapshot;
use ControlBot\Business\FactoryLiveSnapshot;

$scenario = $argv[1] ?? '';

function sig(
    string $id,
    string $authority,
    string $state='healthy',
    string $freshness='current',
    array $data=[],
    int $observedAt=180
): array {
    return [
        'id'=>$id,
        'authority'=>$authority,
        'state'=>$state,
        'source_ref'=>'controlbot:evidence/'.$id,
        'observed_at'=>$observedAt,
        'freshness'=>$freshness,
        'data'=>$data,
    ];
}

function project(string $repo, string $state, int $available=0, int $reserved=0, int $blocked=0): array {
    return [
        'repository_ref'=>$repo,
        'state'=>$state,
        'counts'=>[
            'completed'=>0,
            'available'=>$available,
            'reserved'=>$reserved,
            'blocked'=>$blocked,
            'unmaterialized'=>0,
            'decision_required'=>0,
            'live_only'=>0,
            'future_idea'=>0,
            'already_materialized'=>0,
        ],
        'next_work'=>$state==='READY' ? $repo.'#next' : null,
        'unmaterialized_identities'=>[],
        'parent_progress'=>[],
    ];
}

function inventory(string $freshness='current'): array {
    return [
        'version'=>1,
        'source_ref'=>'github:pl0n3r/Factory@6c6f5f82',
        'observed_at'=>195,
        'freshness'=>$freshness,
        'projects'=>[
            project('pl0n3r/Factory','ALL_BLOCKED',0,0,2),
            project('pl0n3r/Condor','NO_WORK'),
            project('pl0n3r/GrindFlow','NO_WORK'),
            project('pl0n3r/brvtal','NO_WORK'),
            project('pl0n3r/ControlBot','READY',1,1,1),
            project('pl0n3r/AutoFactory','NO_WORK'),
            project('pl0n3r/FactoryRunner','NO_WORK'),
        ],
    ];
}

function rawFixture(string $inventoryFreshness='current'): array {
    return [
        'batches'=>[sig('batch:tanda-3','factory_plan','healthy','current',['source_kind'=>'factory'])],
        'owner_decisions'=>[
            sig(
                'decision:controlbot-700',
                'owner_inbox',
                'pending',
                'current',
                ['issue_ref'=>'github:pl0n3r/ControlBot#700'],
                150
            ),
        ],
        'releases'=>[sig('release:factory-v1','github_project_snapshot','healthy')],
        'blockers'=>[sig('blocker:factory-860','github_project_snapshot','blocked')],
        'production'=>[sig('production:controlbot','observability_project_status','pending')],
        'quality'=>[sig('quality:controlbot','quality_health','healthy')],
        'work'=>[
            sig(
                'work:controlbot-620',
                'github_project_snapshot',
                'pending',
                'current',
                [
                    'repository_ref'=>'pl0n3r/ControlBot',
                    'issue_ref'=>'github:pl0n3r/ControlBot#620',
                    'status'=>'reserved',
                    'progress_percent'=>40,
                    'progress_evidence'=>'github:pl0n3r/ControlBot#620/lease',
                    'ignored_note'=>'presentation must not copy arbitrary payloads',
                ],
                190
            ),
            sig(
                'work:factory-856',
                'github_project_snapshot',
                'pending',
                'current',
                [
                    'repository_ref'=>'pl0n3r/Factory',
                    'issue_ref'=>'github:pl0n3r/Factory#856',
                    'status'=>'in_review',
                ],
                188
            ),
        ],
        'learning'=>[sig('learning:incident-860','incident_lesson','healthy')],
        'tool_usage'=>sig('tool-usage:remote-desktop','tool_usage','healthy'),
        'work_inventory'=>inventory($inventoryFreshness),
    ];
}

function build(array $raw, int $now=220): array {
    $canonical = FactoryLiveSnapshot::build($raw, 200);
    return FactoryLiveOrchestratorSnapshot::build($canonical, $now);
}

function blocked(callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (Throwable) {
        return true;
    }
}

if ($scenario === 'full') {
    echo json_encode(build(rawFixture()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'fail_closed') {
    $stale = rawFixture('stale');
    $stale['work'][0]['state'] = 'degraded';
    $stale['work'][0]['freshness'] = 'stale';

    $missing = rawFixture();
    unset($missing['work']);

    $mismatch = rawFixture();
    $mismatch['work'][0]['data']['repository_ref'] = 'pl0n3r/UnknownRepo';

    echo json_encode([
        'stale'=>build($stale),
        'missing'=>build($missing),
        'mismatch_blocked'=>blocked(fn()=>build($mismatch)),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'safety') {
    $extra = rawFixture();
    $safe = build($extra);
    $many = rawFixture();
    $many['work'] = [];
    for ($i=1; $i<=25; $i++) {
        $many['work'][] = sig(
            'work:controlbot-'.$i,
            'github_project_snapshot',
            'pending',
            'current',
            [
                'repository_ref'=>'pl0n3r/ControlBot',
                'issue_ref'=>'github:pl0n3r/ControlBot#'.(700+$i),
                'status'=>'reserved',
            ],
            180
        );
    }

    $secret = rawFixture();
    $secret['work'][0]['data']['api_key'] = 'do-not-copy';

    echo json_encode([
        'safe'=>$safe,
        'bounded'=>blocked(fn()=>build($many)),
        'secret_blocked'=>blocked(fn()=>build($secret)),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario invalid\n");
exit(2);

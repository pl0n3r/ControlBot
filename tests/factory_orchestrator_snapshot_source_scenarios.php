<?php
declare(strict_types=1);

require __DIR__.'/../src/FactoryOrchestratorSnapshotSource.php';

use ControlBot\Business\FactoryOrchestratorSnapshotSource;

$scenario = $argv[1] ?? '';

function signal(
    string $id,
    string $authority,
    string $state = 'healthy',
    string $freshness = 'current',
    array $data = [],
    int $observedAt = 180
): array {
    return [
        'id' => $id,
        'authority' => $authority,
        'state' => $state,
        'source_ref' => 'controlbot:evidence/' . $id,
        'observed_at' => $observedAt,
        'freshness' => $freshness,
        'data' => $data,
    ];
}

function project(string $repository, string $state, int $available = 0, int $reserved = 0, int $blocked = 0): array
{
    return [
        'repository_ref' => $repository,
        'state' => $state,
        'counts' => [
            'completed' => 0,
            'available' => $available,
            'reserved' => $reserved,
            'blocked' => $blocked,
            'unmaterialized' => 0,
            'decision_required' => 0,
            'live_only' => 0,
            'future_idea' => 0,
            'already_materialized' => 0,
        ],
        'next_work' => $state === 'READY' ? $repository . '#next' : null,
        'unmaterialized_identities' => [],
        'parent_progress' => [],
    ];
}

function inventory(string $freshness = 'current'): array
{
    return [
        'version' => 1,
        'source_ref' => 'github:pl0n3r/Factory@89f53a4b',
        'observed_at' => 195,
        'freshness' => $freshness,
        'projects' => [
            project('pl0n3r/Factory', 'ALL_BLOCKED', 0, 0, 2),
            project('pl0n3r/Condor', 'READY', 1),
            project('pl0n3r/GrindFlow', 'ALL_BLOCKED', 0, 0, 1),
            project('pl0n3r/brvtal', 'NO_WORK'),
            project('pl0n3r/ControlBot', 'READY', 1, 1, 2),
            project('pl0n3r/AutoFactory', 'NO_WORK'),
            project('pl0n3r/FactoryRunner', 'NO_WORK'),
        ],
    ];
}

function evidence(): array
{
    return [
        'batches' => [signal('batch:tanda-3', 'factory_plan')],
        'owner_decisions' => [
            signal(
                'decision:controlbot-625',
                'owner_inbox',
                'pending',
                'current',
                ['issue_ref' => 'github:pl0n3r/ControlBot#625'],
                175
            ),
        ],
        'releases' => [signal('release:controlbot-main', 'github_project_snapshot')],
        'blockers' => [
            signal(
                'blocker:controlbot-625',
                'github_project_snapshot',
                'blocked',
                'current',
                ['issue_ref' => 'github:pl0n3r/ControlBot#625']
            ),
        ],
        'production' => [signal('production:controlbot', 'observability_project_status', 'degraded')],
        'quality' => [signal('quality:controlbot', 'quality_health')],
        'work' => [
            signal(
                'work:controlbot-659',
                'github_project_snapshot',
                'pending',
                'current',
                [
                    'repository_ref' => 'pl0n3r/ControlBot',
                    'issue_ref' => 'github:pl0n3r/ControlBot#659',
                    'status' => 'reserved',
                    'progress_percent' => 25,
                    'progress_evidence' => 'github:pl0n3r/ControlBot#659',
                ],
                190
            ),
        ],
        'learning' => [signal('learning:controlbot-625', 'incident_lesson')],
        'tool_usage' => signal('tool-usage:github', 'tool_usage'),
        'work_inventory' => inventory(),
    ];
}

function blocked(callable $operation): bool
{
    try {
        $operation();
        return false;
    } catch (Throwable) {
        return true;
    }
}

if ($scenario === 'compose') {
    echo json_encode(
        FactoryOrchestratorSnapshotSource::fromInjectedEvidence(evidence(), 220),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    ), PHP_EOL;
    exit;
}

if ($scenario === 'fail_closed') {
    $missing = evidence();
    unset($missing['work'], $missing['work_inventory']);
    $missingResult = FactoryOrchestratorSnapshotSource::fromInjectedEvidence($missing, 220);

    $stale = evidence();
    $stale['work_inventory']['freshness'] = 'stale';
    $staleResult = FactoryOrchestratorSnapshotSource::fromInjectedEvidence($stale, 220);

    $mismatch = evidence();
    $mismatch['work'][0]['data']['repository_ref'] = 'pl0n3r/UnknownRepo';

    $sensitive = evidence();
    $sensitive['work'][0]['data']['api_token'] = 'not-allowed';

    $authority = evidence();
    $authority['work'][0]['authority'] = 'quality_health';

    echo json_encode([
        'missing' => [
            'activity_state' => $missingResult['central']['activity_state'],
            'fronts' => $missingResult['fronts'],
        ],
        'stale' => $staleResult['central']['activity_state'],
        'mismatch_blocked' => blocked(
            fn (): array => FactoryOrchestratorSnapshotSource::fromInjectedEvidence($mismatch, 220)
        ),
        'sensitive_blocked' => blocked(
            fn (): array => FactoryOrchestratorSnapshotSource::fromInjectedEvidence($sensitive, 220)
        ),
        'authority_blocked' => blocked(
            fn (): array => FactoryOrchestratorSnapshotSource::fromInjectedEvidence($authority, 220)
        ),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario invalid\n");
exit(2);

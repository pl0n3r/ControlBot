<?php
declare(strict_types=1);

require __DIR__.'/../src/FactoryLiveSnapshot.php';

use ControlBot\Business\FactoryLiveSnapshot;

$scenario = $argv[1] ?? '';

function signal(
    string $id,
    string $authority,
    string $state = 'healthy',
    string $freshness = 'current',
    ?string $sourceRef = null,
    ?int $observedAt = 180,
    array $data = [],
): array {
    return [
        'id' => $id,
        'authority' => $authority,
        'state' => $state,
        'source_ref' => $sourceRef ?? 'controlbot:evidence/'.$id,
        'observed_at' => $observedAt,
        'freshness' => $freshness,
        'data' => $data,
    ];
}

function fixture(): array
{
    return [
        'batches' => [signal(
            'batch:tanda-1',
            'factory_plan',
            'healthy',
            'current',
            'factory:plan-agentes',
            180,
            ['source_kind' => 'factory', 'tanda' => 1],
        )],
        'owner_decisions' => [signal(
            'decision:controlbot-100',
            'owner_inbox',
            'pending',
            'current',
            'controlbot:owner-inbox/decision-100',
            181,
            [
                'issue_ref' => 'https://github.com/pl0n3r/ControlBot/issues/100',
                'recommended_option' => 'A',
                'source_kind' => 'github',
            ],
        )],
        'releases' => [signal(
            'release:factory-v1',
            'github_project_snapshot',
            'healthy',
            'current',
            'controlbot:github/project-snapshot',
            182,
            ['source_kind' => 'github', 'tag' => 'v1'],
        )],
        'blockers' => [signal(
            'blocker:controlbot-65',
            'github_project_snapshot',
            'blocked',
            'current',
            'controlbot:github/project-snapshot',
            183,
            ['source_kind' => 'github', 'issue' => 65],
        )],
        'production' => [signal(
            'production:condor',
            'observability_project_status',
            'healthy',
            'current',
            'controlbot:observability/project-status',
            184,
            ['source_kind' => 'observability', 'project' => 'condor'],
        )],
        'quality' => [signal(
            'quality:condor',
            'quality_health',
            'healthy',
            'current',
            'factory:quality-health/condor',
            185,
            ['source_kind' => 'sonar', 'quality_gate' => 'PASS'],
        )],
        'work' => [
            signal(
                'work:controlbot-562',
                'github_project_snapshot',
                'pending',
                'current',
                'controlbot:github/project-snapshot',
                186,
                ['source_kind' => 'github', 'issue' => 562],
            ),
            signal(
                'work:grindflow-201',
                'github_project_snapshot',
                'healthy',
                'current',
                'controlbot:github/project-snapshot',
                187,
                ['source_kind' => 'github', 'issue' => 201],
            ),
        ],
        'learning' => [signal(
            'learning:incident-739',
            'incident_lesson',
            'healthy',
            'current',
            'controlbot:learning/incident-739',
            188,
            ['source_kind' => 'learning', 'preventive_rules' => 2],
        )],
        'tool_usage' => signal(
            'tool-usage:remote-desktop',
            'tool_usage',
            'degraded',
            'current',
            'controlbot:tool-usage/remote-desktop',
            189,
            ['limit' => 10000, 'source_kind' => 'tool_usage', 'used' => 4200],
        ),
    ];
}

function blocked(callable $callback): bool
{
    try {
        $callback();
        return false;
    } catch (Throwable) {
        return true;
    }
}

if ($scenario === 'full') {
    echo json_encode(
        FactoryLiveSnapshot::build(fixture(), 200),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ), PHP_EOL;
    exit;
}

if ($scenario === 'fail_closed') {
    $missing = fixture();
    unset($missing['quality']);
    $missingSnapshot = FactoryLiveSnapshot::build($missing, 200);

    $stale = fixture();
    $stale['production'][0]['freshness'] = 'stale';

    $duplicate = fixture();
    $duplicate['work'][] = $duplicate['work'][0];

    $authority = fixture();
    $authority['quality'][0]['authority'] = 'github_project_snapshot';

    echo json_encode([
        'missing_quality' => $missingSnapshot['sections']['quality'][0],
        'blocked' => [
            'stale_healthy' => blocked(
                static fn() => FactoryLiveSnapshot::build($stale, 200),
            ),
            'duplicate' => blocked(
                static fn() => FactoryLiveSnapshot::build($duplicate, 200),
            ),
            'wrong_authority' => blocked(
                static fn() => FactoryLiveSnapshot::build($authority, 200),
            ),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

if ($scenario === 'safety') {
    $secret = fixture();
    $secret['quality'][0]['data']['api_key'] = 'should-not-pass';

    $bounded = fixture();
    $bounded['work'] = [];
    for ($i = 1; $i <= 51; $i++) {
        $bounded['work'][] = signal(
            'work:item-'.$i,
            'github_project_snapshot',
            'pending',
            'current',
            'controlbot:github/project-snapshot',
            190,
            ['source_kind' => 'github', 'issue' => $i],
        );
    }

    $first = fixture();
    $second = fixture();
    $second['work'] = array_reverse($second['work']);

    echo json_encode([
        'secret_blocked' => blocked(
            static fn() => FactoryLiveSnapshot::build($secret, 200),
        ),
        'bounded_blocked' => blocked(
            static fn() => FactoryLiveSnapshot::build($bounded, 200),
        ),
        'deterministic' => (
            FactoryLiveSnapshot::build($first, 200)
            === FactoryLiveSnapshot::build($second, 200)
        ),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if ($scenario === 'simulated') {
    $snapshot = FactoryLiveSnapshot::build(fixture(), 200);
    $authorities = [];
    $sourceKinds = [];
    foreach ($snapshot['sections'] as $section => $signals) {
        $authorities[$section] = $signals[0]['authority'];
        foreach ($signals as $row) {
            $kind = $row['data']['source_kind'] ?? null;
            if (is_string($kind)) {
                $sourceKinds[$kind] = true;
            }
        }
    }
    $sourceKinds[$snapshot['tool_usage']['data']['source_kind']] = true;
    ksort($authorities, SORT_STRING);
    ksort($sourceKinds, SORT_STRING);

    echo json_encode([
        'authorities' => $authorities,
        'source_kinds' => array_keys($sourceKinds),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

fwrite(STDERR, "scenario inválido\n");
exit(2);

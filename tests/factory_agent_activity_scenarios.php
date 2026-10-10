<?php
declare(strict_types=1);
require __DIR__ . '/../src/FactoryAgentActivity.php';
require __DIR__ . '/../src/FactoryAgentActivityUi.php';
use ControlBot\Business\FactoryAgentActivity;
use ControlBot\Business\FactoryAgentActivityUi;
$repos = ['Factory','Condor','GrindFlow','brvtal','ControlBot','AutoFactory','FactoryRunner'];
$rows = [];
foreach ($repos as $name) {
    $repo = 'pl0n3r/' . $name;
    $rows[] = [
        'repository_ref' => $repo,
        'source_ref' => 'https://api.github.com/repos/' . $repo . '/issues',
        'observed_at' => 190, 'freshness' => 'current',
        'issues' => [['number' => 1, 'status' => 'reserved', 'updated_at' => 160],
                     ['number' => 2, 'status' => 'available', 'updated_at' => 180]],
        'pull_requests' => [['number' => 3, 'updated_at' => 185]],
        'coordination_runs' => [['id' => 10, 'status' => 'completed', 'updated_at' => 188]],
        'planned_unlockable' => 0,
    ];
}
$scenario = $argv[1] ?? 'full';
function expectReject(callable $cb): bool {
    try { $cb(); return false; } catch (Throwable) { return true; }
}
if ($scenario === 'full') {
    $result = FactoryAgentActivity::build($rows, 200);
    echo json_encode(['view' => $result, 'html' => FactoryAgentActivityUi::renderSection($result)], JSON_THROW_ON_ERROR), "\n";
} elseif ($scenario === 'queue') {
    foreach ($rows as &$row) {
        $row['issues'] = [['number' => 1, 'status' => 'blocked', 'updated_at' => 180]];
        $row['planned_unlockable'] = 0;
    }
    unset($row);
    $rows[2]['planned_unlockable'] = 2;
    $valid = FactoryAgentActivity::build($rows, 200);
    $rows[3]['issues'] = null;
    $unknown = FactoryAgentActivity::build($rows, 200);
    $rows[3]['issues'] = [['number' => 1, 'status' => 'blocked', 'updated_at' => 180]];
    $rows[3]['freshness'] = 'stale';
    $stale = FactoryAgentActivity::build($rows, 200);
    echo json_encode(['valid' => $valid, 'unknown' => $unknown, 'stale' => $stale], JSON_THROW_ON_ERROR), "\n";
} elseif ($scenario === 'safety') {
    $mut = $rows; $mut[0]['repository_ref'] = 'pl0n3r/<script>alert(1)</script>';
    $badRepo = expectReject(fn () => FactoryAgentActivity::build($mut, 200));
    $mut = $rows; $mut[0]['source_ref'] = 'https://evil.example/?token=secret'; $badSource = expectReject(fn () => FactoryAgentActivity::build($mut, 200));
    $mut = $rows; $mut[0]['issues'][] = ['number' => 1, 'status' => 'reserved', 'updated_at' => 195]; $dupe = expectReject(fn () => FactoryAgentActivity::build($mut, 200));
    $mut = $rows; $mut[0]['coordination_runs'] = null; $partial = FactoryAgentActivity::build($mut, 200);
    $mut = $rows; $mut[0]['freshness'] = 'unknown';
    $mut[0]['source_ref'] = null; $mut[0]['observed_at'] = null; $mut[0]['issues'] = null;
    $mut[0]['pull_requests'] = null; $mut[0]['coordination_runs'] = null; $mut[0]['planned_unlockable'] = null;
    $unknown = FactoryAgentActivity::build($mut, 200);
    require __DIR__ . '/../src/FactoryOrchestratorEvidenceCollector.php';
    $utcMethod = new ReflectionMethod(\ControlBot\Business\FactoryOrchestratorEvidenceCollector::class, 'activityTimestamp');
    $utcNow = 1790000000;
    $utc = ['valid' => $utcMethod->invoke(null, '2024-02-29T12:00:00Z', $utcNow) !== null,
        'invalid_date' => $utcMethod->invoke(null, '2026-02-31T12:00:00Z', $utcNow) === null,
        'invalid_leap' => $utcMethod->invoke(null, '2025-02-29T12:00:00Z', $utcNow) === null,
        'future' => $utcMethod->invoke(null, '2030-02-28T12:00:00Z', $utcNow) === null];
    echo json_encode(['bad_repo' => $badRepo, 'bad_source' => $badSource, 'duplicate' => $dupe,
        'partial' => $partial, 'unknown' => $unknown, 'utc' => $utc, 'html' => FactoryAgentActivityUi::renderSection($unknown)],
        JSON_THROW_ON_ERROR), "\n";
}
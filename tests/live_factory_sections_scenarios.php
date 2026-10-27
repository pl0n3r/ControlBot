<?php
declare(strict_types=1);

require_once __DIR__.'/../src/LiveFactorySections.php';

use ControlBot\Business\LiveFactorySections;

function signal(string $id, string $state, string $source, int $at, array $data = []): array
{
    return ['id' => $id, 'state' => $state, 'source_ref' => $source,
        'observed_at' => $at, 'data' => $data];
}
function fixtures(): array
{
    return [
        'factory' => ['batches' => [signal('batch:tanda-1', 'pending', 'factory:plan/tanda1', 980, ['tanda' => 1])]],
        'github' => [
            'owner_decisions' => [signal('decision:controlbot-561', 'pending', 'github:ControlBot/issues/561', 995,
                ['issue_ref' => 'https://github.com/pl0n3r/ControlBot/issues/561', 'recommended_option' => 'B'])],
            'releases' => [signal('release:factory-v1', 'healthy', 'github:Factory/tags/v1', 985, ['tag' => 'v1'])],
            'blockers' => [signal('blocker:condor-1033', 'blocked', 'github:Condor/issues/1033', 990,
                ['issue_ref' => 'https://github.com/pl0n3r/Condor/issues/1033'])],
            'work' => [signal('work:controlbot-975', 'pending', 'github:ControlBot/issues/975', 993,
                ['issue_ref' => 'https://github.com/pl0n3r/ControlBot/issues/975'])],
        ],
        'observability' => ['production' => [signal('production:condor', 'healthy', 'observability:condor/health', 978,
            ['project' => 'condor'])]],
        'sonar' => ['quality' => [signal('quality:condor', 'healthy', 'sonar:condor/quality-gate', 996,
            ['quality_gate' => 'PASS', 'bugs' => 0])]],
        'learning' => ['learning' => [signal('learning:incident-739', 'degraded', 'learning:incident/739', 977,
            ['preventive_rules' => 2])]],
    ];
}
function denied(callable $action): bool
{
    try { $action(); return false; } catch (\Throwable) { return true; }
}

$scenario = $argv[1] ?? '';
$now = 1000;
if ($scenario === 'full') {
    $a = LiveFactorySections::build(fixtures(), $now);
    $b = fixtures(); $b['github']['work'][] = signal('work:grindflow-77', 'pending', 'github:GrindFlow/issues/77', 998);
    $b['github']['work'] = array_reverse($b['github']['work']);
    $c = $b; $c['github']['work'] = array_reverse($c['github']['work']);
    echo json_encode(['view' => $a, 'deterministic' => LiveFactorySections::build($b, $now) === LiveFactorySections::build($c, $now)], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
if ($scenario === 'missing') {
    $missing = fixtures(); unset($missing['sonar']);
    $old = fixtures(); $old['sonar']['quality'][0]['observed_at'] = 1;
    $invalid = fixtures(); $invalid['github']['owner_decisions'][0]['data']['issue_ref'] = 'not-an-issue';
    $duplicate = fixtures(); $duplicate['github']['work'][] = $duplicate['github']['work'][0];
    $foreign = fixtures(); $foreign['github']['quality'] = $foreign['sonar']['quality'];
    $future = fixtures(); $future['sonar']['quality'][0]['observed_at'] = 1001;
    echo json_encode([
        'missing' => LiveFactorySections::build($missing, $now)['sections']['quality'][0],
        'stale' => LiveFactorySections::build($old, $now)['sections']['quality'][0],
        'bad_link' => denied(fn() => LiveFactorySections::build($invalid, $now)),
        'duplicate' => denied(fn() => LiveFactorySections::build($duplicate, $now)),
        'wrong_source' => denied(fn() => LiveFactorySections::build($foreign, $now)),
        'future' => denied(fn() => LiveFactorySections::build($future, $now)),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
if ($scenario === 'privacy') {
    $secret = fixtures(); $secret['sonar']['quality'][0]['data']['api_key'] = 'synthetic-secret';
    $mail = fixtures(); $mail['github']['owner_decisions'][0]['data']['mail'] = 'user@example.test';
    $token = fixtures(); $token['github']['work'][0]['source_ref'] = 'github:Bearer accidental';
    $payload = LiveFactorySections::build(fixtures(), $now);
    echo json_encode([
        'secret_rejected' => denied(fn() => LiveFactorySections::build($secret, $now)),
        'email_rejected' => denied(fn() => LiveFactorySections::build($mail, $now)),
        'token_rejected' => denied(fn() => LiveFactorySections::build($token, $now)),
        'source' => file_get_contents(__DIR__.'/../src/LiveFactorySections.php'),
        'serialized' => json_encode($payload, JSON_THROW_ON_ERROR),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
fwrite(STDERR, 'Scenario inválido'.PHP_EOL); exit(2);

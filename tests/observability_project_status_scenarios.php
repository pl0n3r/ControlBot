<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ObservabilityEvent.php';
require_once __DIR__ . '/../src/ObservabilityProjectStatus.php';

use ControlBot\Observability\ObservabilityProjectStatus;

function rawEvent(
    string $project,
    string $source,
    int $occurred = 100,
    string $severity = 'info',
    ?array $payload = null
): array {
    $types = ['health'=>'health_probe', 'ci'=>'ci_run', 'deploy'=>'deploy', 'agent'=>'heartbeat'];
    $defaults = [
        'health'=>['status'=>'ok'],
        'ci'=>['conclusion'=>'success'],
        'deploy'=>['status'=>'success'],
        'agent'=>['status'=>'idle'],
    ];
    return [
        'version'=>1, 'source'=>$source, 'project_id'=>$project,
        'environment_id'=>'production', 'repository_id'=>null,
        'severity'=>$severity, 'type'=>$types[$source], 'payload'=>$payload ?? $defaults[$source],
        'occurred_at'=>$occurred, 'received_at'=>$occurred + 1,
        'correlation_keys'=>['project:' . $project],
    ];
}

function blocked(callable $fn): bool
{
    try {$fn(); return false;} catch (\InvalidArgumentException) {return true;}
}

$projects = ['project-controlbot', 'project-condor', 'project-grindflow', 'project-brvtal'];
$ttl = ['health'=>120, 'ci'=>120, 'deploy'=>120, 'agent'=>120];
$case = $argv[1] ?? '';

$out = match ($case) {
    'current' => (function() use ($projects, $ttl): array {
        $events = [];
        foreach ($projects as $project) {
            foreach (['health','ci','deploy','agent'] as $source) {
                $events[] = rawEvent($project, $source);
            }
        }
        return ObservabilityProjectStatus::build($projects, $events, 150, $ttl);
    })(),
    'missing-stale' => (function() use ($ttl): array {
        $catalog = ['project-controlbot', 'project-condor'];
        $events = [
            rawEvent('project-controlbot', 'health', 10, 'warning'),
            array_replace(rawEvent('project-condor', 'agent', 180, 'warning'), [
                'type'=>'capacity',
                'payload'=>['status'=>'degraded','capacity_total'=>1,'capacity_used'=>1],
            ]),
        ];
        return ObservabilityProjectStatus::build($catalog, $events, 200, $ttl);
    })(),
    'latest' => ObservabilityProjectStatus::build(
        ['project-controlbot'],
        [rawEvent('project-controlbot','health',100,'warning'), rawEvent('project-controlbot','health',120,'critical')],
        150,
        $ttl
    ),
    'ambiguous' => blocked(fn()=>ObservabilityProjectStatus::build(
        ['project-controlbot'],
        [rawEvent('project-controlbot','health',100,'warning'), rawEvent('project-controlbot','health',100,'critical')],
        150,
        $ttl
    )),
    'invalid' => (function() use ($ttl): array {
        $extra = rawEvent('project-controlbot','ci'); $extra['raw_dump'] = 'x';
        $sensitive = rawEvent('project-controlbot','ci',100,'error',['reason_code'=>'owner@example.com']);
        return [
            'outside'=>blocked(fn()=>ObservabilityProjectStatus::build(
                ['project-controlbot'], [rawEvent('project-rogue','health')], 150, $ttl
            )),
            'duplicate'=>blocked(fn()=>ObservabilityProjectStatus::build(
                ['project-controlbot','project-controlbot'], [], 150, $ttl
            )),
            'extra'=>blocked(fn()=>ObservabilityProjectStatus::build(
                ['project-controlbot'], [$extra], 150, $ttl
            )),
            'sensitive'=>blocked(fn()=>ObservabilityProjectStatus::build(
                ['project-controlbot'], [$sensitive], 150, $ttl
            )),
            'bad_ttl'=>blocked(fn()=>ObservabilityProjectStatus::build(
                ['project-controlbot'], [], 150, ['health'=>0]
            )),
            'bad_catalog'=>blocked(fn()=>ObservabilityProjectStatus::build(
                ['github_pat_secret'], [], 150, $ttl
            )),
        ];
    })(),
    'order' => (function() use ($projects, $ttl): array {
        $events = [
            rawEvent('project-controlbot','health',100,'warning'),
            rawEvent('project-condor','ci',110,'error'),
            rawEvent('project-brvtal','deploy',120,'info'),
            rawEvent('project-grindflow','agent',130,'warning'),
        ];
        $a = ObservabilityProjectStatus::build($projects, $events, 160, $ttl);
        $b = ObservabilityProjectStatus::build(array_reverse($projects), array_reverse($events), 160, $ttl);
        return ['a'=>$a, 'b'=>$b];
    })(),
    default => throw new \InvalidArgumentException('Unknown scenario.'),
};

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/ObservabilityEvent.php';

use ControlBot\Observability\ObservabilityEvent;

function event(string $source='health', string $type='health_probe', array $payload=[]): array
{
    return [
        'version'=>1, 'source'=>$source, 'project_id'=>'controlbot',
        'environment_id'=>'production', 'repository_id'=>'pl0n3r/ControlBot',
        'severity'=>'warning', 'type'=>$type, 'payload'=>$payload,
        'occurred_at'=>100, 'received_at'=>105,
        'correlation_keys'=>['project:controlbot','issue:78'],
    ];
}
function bad(callable $fn): bool
{
    try {$fn(); return false;} catch (\InvalidArgumentException) {return true;}
}
$case=$argv[1]??'';
$ttl=['health'=>60,'ci'=>120,'deploy'=>180,'agent'=>30];
$out=match($case) {
    'sources' => [
        'health'=>ObservabilityEvent::normalize(event('health','health_probe',['status'=>'unknown']),120,$ttl),
        'ci'=>ObservabilityEvent::normalize(event('ci','ci_run',['conclusion'=>'failure']),120,$ttl),
        'deploy'=>ObservabilityEvent::normalize(event('deploy','deploy',['status'=>'success']),120,$ttl),
        'agent'=>ObservabilityEvent::normalize(event('agent','heartbeat',['status'=>'idle']),120,$ttl),
    ],
    'freshness' => [
        'fresh'=>ObservabilityEvent::normalize(event(),120,$ttl)['freshness'],
        'stale'=>ObservabilityEvent::normalize(event(),200,$ttl)['freshness'],
        'unknown'=>ObservabilityEvent::normalize(event(),120,[])['freshness'],
        'delayed'=>ObservabilityEvent::normalize(
            array_replace(event(),['occurred_at'=>10,'received_at'=>119]),120,$ttl
        )['freshness'],
        'future_rejected'=>bad(fn()=>ObservabilityEvent::normalize(
            array_replace(event(),['received_at'=>130]),120,$ttl
        )),
    ],
    'security' => (function() use($ttl): array {
        $secret=event('ci','ci_run',['reason_code'=>'token=github_pat_abcdefghijklmnopqrstuvwxyz123456']);
        $email=event('ci','ci_run',['reason_code'=>'owner@example.com']);
        $bearer=event('ci','ci_run',['reason_code'=>'Bearer abcdefghijklmnop']);
        $nested=event('ci','ci_run',['steps'=>['setup'=>'failed']]);
        $extra=event('ci','ci_run',['raw_dump'=>'x']);
        $wrongType=event('ci','runner_capacity',['startup_failure'=>'true']);
        $dupe=event(); $dupe['correlation_keys']=['issue:78','project:controlbot','issue:78'];
        $secretKey=event(); $secretKey['correlation_keys']=['token:github_pat_abcdefghijklmnopqrstuvwxyz123456'];
        $projectSecret=event(); $projectSecret['project_id']='github_pat_abcdefghijklmnopqrstuvwxyz1234567890';
        $userinfo=event(); $userinfo['repository_id']='https://bot:hunter2@git-server/repo';
        $userinfoEmpty=event(); $userinfoEmpty['repository_id']='https://:hunter2@git-server/repo';
        $userinfoNoScheme=event(); $userinfoNoScheme['repository_id']='bot:hunter2@git-server/repo';
        $numeric=event('ci','ci_run',['sha'=>'abcdef1234567890abcdef1234567890abcdef12']); $numeric['correlation_keys']=['run:1234567890'];
        $dateVersion=event('health','health_probe',['version'=>'2026.09.29.1','schema'=>'2026-09-29']);
        return [
            'secret'=>bad(fn()=>ObservabilityEvent::normalize($secret,120,$ttl)),
            'email'=>bad(fn()=>ObservabilityEvent::normalize($email,120,$ttl)),
            'bearer'=>bad(fn()=>ObservabilityEvent::normalize($bearer,120,$ttl)),
            'nested'=>bad(fn()=>ObservabilityEvent::normalize($nested,120,$ttl)),
            'extra'=>bad(fn()=>ObservabilityEvent::normalize($extra,120,$ttl)),
            'wrong_type'=>bad(fn()=>ObservabilityEvent::normalize($wrongType,120,$ttl)),
            'secret_key'=>bad(fn()=>ObservabilityEvent::normalize($secretKey,120,$ttl)),
            'project_secret'=>bad(fn()=>ObservabilityEvent::normalize($projectSecret,120,$ttl)),
            'uri_userinfo'=>bad(fn()=>ObservabilityEvent::normalize($userinfo,120,$ttl)),
            'uri_userinfo_empty'=>bad(fn()=>ObservabilityEvent::normalize($userinfoEmpty,120,$ttl)),
            'uri_userinfo_no_scheme'=>bad(fn()=>ObservabilityEvent::normalize($userinfoNoScheme,120,$ttl)),
            'numeric'=>ObservabilityEvent::normalize($numeric,120,$ttl),
            'date_version'=>ObservabilityEvent::normalize($dateVersion,120,$ttl),
            'keys'=>ObservabilityEvent::normalize($dupe,120,$ttl)['correlation_keys'],
        ];
    })(),
    'fingerprint' => (function() use($ttl): array {
        $a=event('ci','ci_run',['status'=>'failed','sha'=>'abcdef']);
        $b=array_replace($a,['received_at'=>110]);
        $c=$a; $c['payload']['sha']='fedcba';
        return [
            'a'=>ObservabilityEvent::normalize($a,120,$ttl)['fingerprint'],
            'retry'=>ObservabilityEvent::normalize($b,120,$ttl)['fingerprint'],
            'changed'=>ObservabilityEvent::normalize($c,120,$ttl)['fingerprint'],
        ];
    })(),
    'incident78' => ObservabilityEvent::normalize(event('ci','runner_capacity',[
        'runner_status'=>'unavailable','application_status'=>'unknown',
        'startup_failure'=>true,'steps'=>null,'reason_code'=>'included_minutes_exhausted',
    ]),120,$ttl),
    'pure' => (function(): array {
        $source=file_get_contents(__DIR__.'/../src/ObservabilityEvent.php');
        if($source===false) throw new \RuntimeException('source unavailable');
        return ['source'=>$source];
    })(),
    default => throw new \InvalidArgumentException('Unknown scenario.'),
};
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

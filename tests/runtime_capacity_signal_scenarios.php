<?php
declare(strict_types=1);

require __DIR__ . '/../src/RuntimeCapacitySignal.php';

use ControlBot\Runtime\RuntimeCapacitySignal;

function signal(string $source='autofactory', string $state='idle'): array
{
    return [
        'version'=>1,
        'source'=>$source,
        'provider_id'=>'chatgpt-web',
        'account_ref'=>'account-main',
        'session_ref'=>'session-1',
        'state'=>$state,
        'observed_at'=>1000,
        'heartbeat_at'=>$state==='unknown' ? null : 995,
        'total_capacity'=>$state==='unknown' ? null : 5,
        'occupied_capacity'=>$state==='unknown' ? null : 2,
        'assignment_ref'=>$state==='idle' || $state==='unknown' ? null : 'work-215',
        'generation'=>4,
        'attempt'=>2,
        'capabilities'=>['php','review-code'],
    ];
}

function rejected(callable $fn): bool
{
    try { $fn(); return false; } catch (Throwable) { return true; }
}

$case=$argv[1]??'';
if($case==='sources'){
    $auto=RuntimeCapacitySignal::normalize(signal('autofactory'));
    $runner=RuntimeCapacitySignal::normalize(signal('factoryrunner'));
    echo json_encode(['auto'=>$auto,'runner'=>$runner,'same_keys'=>array_keys($auto)===array_keys($runner)],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='secrets'){
    $base=signal();
    echo json_encode([
        'extra_transcript'=>rejected(fn()=>RuntimeCapacitySignal::normalize($base+['transcript'=>'chat'])),
        'cookie_value'=>rejected(fn()=>RuntimeCapacitySignal::normalize(array_replace($base,['assignment_ref'=>'cookie:abc123']))),
        'token_value'=>rejected(fn()=>RuntimeCapacitySignal::normalize(array_replace($base,['account_ref'=>'token:abc123']))),
        'email'=>rejected(fn()=>RuntimeCapacitySignal::normalize(array_replace($base,['account_ref'=>'owner@example.com']))),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='states'){
    $out=[];
    foreach(['rate_limited','requires_login','offline','stale','unknown'] as $state){
        $out[$state]=RuntimeCapacitySignal::normalize(signal('autofactory',$state));
    }
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='clock'){
    $base=signal();
    echo json_encode([
        'valid'=>RuntimeCapacitySignal::normalize($base),
        'future_heartbeat'=>rejected(fn()=>RuntimeCapacitySignal::normalize(array_replace($base,['heartbeat_at'=>1001]))),
        'bad_generation'=>rejected(fn()=>RuntimeCapacitySignal::normalize(array_replace($base,['generation'=>0]))),
        'bad_attempt'=>rejected(fn()=>RuntimeCapacitySignal::normalize(array_replace($base,['attempt'=>0]))),
        'over_occupied'=>rejected(fn()=>RuntimeCapacitySignal::normalize(array_replace($base,['total_capacity'=>1,'occupied_capacity'=>2]))),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='extra'){
    $base=signal();
    echo json_encode([
        'extra'=>rejected(fn()=>RuntimeCapacitySignal::normalize($base+['plan'=>'plus'])),
        'missing'=>rejected(function() use($base){ unset($base['attempt']); RuntimeCapacitySignal::normalize($base); }),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='contract'){
    $reflection=new ReflectionClass(RuntimeCapacitySignal::class);
    $constants=$reflection->getConstants();
    $source=file_get_contents(__DIR__.'/../src/RuntimeCapacitySignal.php');
    echo json_encode([
        'constant_names'=>array_keys($constants),
        'has_plan_constant'=>preg_match('/(?:PLUS|PRO|TEAM|ENTERPRISE|PLAN_CAPACITY|MAX_SESSIONS)/',$source)===1,
        'output'=>RuntimeCapacitySignal::normalize(signal()),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown runtime capacity signal scenario\n"); exit(2);

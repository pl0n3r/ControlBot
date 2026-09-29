<?php
declare(strict_types=1);

require __DIR__.'/../src/SchedulerCore.php';

use ControlBot\Scheduler\SchedulerCore;

function work(string $id,string $project='project-a',array $deps=[],array $claims=[]): array {
    return [
        'work_item'=>[
            'version'=>1,'work_item_id'=>$id,'project_id'=>$project,'source_ref'=>'pl0n3r/ControlBot#214',
            'type'=>'feature','priority'=>'high','state'=>'queued','dependency_ids'=>array_keys($deps),
            'required_capabilities'=>['php'],'generation'=>1,'attempt'=>1,'reservation_id'=>null,'assigned_session_id'=>null,
        ],
        'dependency_states'=>$deps,'claims'=>$claims,
    ];
}
function presence(int $idle=3,string $state='idle_capacity'): array {
    return [
        'version'=>1,'policy_ref'=>'factory-dispatcher-v2','observed_at'=>1000,
        'presence_state'=>'multi','capacity_state'=>$state,'healthy_sessions'=>3,'idle_capacity'=>$idle,
        'sessions'=>[],'accounts'=>[],
    ];
}
function constraints(array $claims=[],array $projects=[]): array {
    return ['active_claims'=>$claims,'project_concurrency'=>$projects];
}
function known(int $limit=4,int $active=0): array { return ['state'=>'known','limit'=>$limit,'active'=>$active]; }
function unknown(): array { return ['state'=>'unknown','limit'=>null,'active'=>null]; }
function rejected(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }

$case=$argv[1]??'';
if($case==='mixed_capacity'){
    $rows=[work('work-a'),work('work-b')];
    $ctx=constraints([],['project-a'=>known(3,0)]);
    $degradedPresence=presence(2,'degraded');
    $unknownPresence=presence(1,'unknown');
    $zeroPresence=presence(0,'degraded');
    $degraded=SchedulerCore::dispatchableCapacity($degradedPresence,$rows,$ctx);
    $unknownCapacity=SchedulerCore::dispatchableCapacity($unknownPresence,$rows,$ctx);
    $zero=SchedulerCore::dispatchableCapacity($zeroPresence,$rows,$ctx);

    $accountNoiseA=$degradedPresence;
    $accountNoiseA['accounts']=[['provider_id'=>'chatgpt-web','plan'=>'declared-large','free_capacity'=>999]];
    $accountNoiseB=$degradedPresence;
    $accountNoiseB['accounts']=[['provider_id'=>'claude-web','plan'=>'declared-small','free_capacity'=>0]];
    $fromA=SchedulerCore::dispatchableCapacity($accountNoiseA,$rows,$ctx);
    $fromB=SchedulerCore::dispatchableCapacity($accountNoiseB,$rows,$ctx);

    $badPolicy=$degradedPresence; $badPolicy['policy_ref']='other-policy';
    $badIdle=$degradedPresence; $badIdle['idle_capacity']=-1;
    $badShape=$degradedPresence; unset($badShape['accounts']);
    $saturatedPositive=presence(1,'saturated');
    $idleZero=presence(0,'idle_capacity');
    $invalid=[
        'policy'=>rejected(fn()=>SchedulerCore::dispatchableCapacity($badPolicy,$rows,$ctx)),
        'idle'=>rejected(fn()=>SchedulerCore::dispatchableCapacity($badIdle,$rows,$ctx)),
        'shape'=>rejected(fn()=>SchedulerCore::dispatchableCapacity($badShape,$rows,$ctx)),
        'saturated_positive'=>rejected(fn()=>SchedulerCore::dispatchableCapacity($saturatedPositive,$rows,$ctx)),
        'idle_zero'=>rejected(fn()=>SchedulerCore::dispatchableCapacity($idleZero,$rows,$ctx)),
    ];
    echo json_encode([
        'degraded'=>$degraded,
        'unknown'=>$unknownCapacity,
        'unknown_input_state'=>$unknownPresence['capacity_state'],
        'zero'=>$zero,
        'account_noise_same'=>$fromA===$fromB,
        'invalid'=>$invalid,
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='idle_bound'){
    $rows=[work('work-a'),work('work-b'),work('work-c')];
    $ctx=constraints([],['project-a'=>known(5,0)]);
    $small=SchedulerCore::dispatchableCapacity(presence(2),$rows,$ctx);
    $large=SchedulerCore::dispatchableCapacity(presence(50),$rows,$ctx);
    echo json_encode(compact('small','large'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='dependencies'){
    $rows=[work('work-a','project-a',['dep-a'=>'open']),work('work-b','project-a',[])];
    $out=SchedulerCore::dispatchableCapacity(presence(3),$rows,constraints([],['project-a'=>known(3,0)]));
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='claims'){
    $rows=[work('work-a','project-a',[],['src/shared.php']),work('work-b','project-a',[],['src/free.php'])];
    $foreign=[['claim'=>'src/shared.php','owner_work_item_id'=>'work-existing']];
    $active=SchedulerCore::dispatchableCapacity(presence(3),$rows,constraints($foreign,['project-a'=>known(3,0)]));
    $sameOwner=[['claim'=>'src/shared.php','owner_work_item_id'=>'work-a']];
    $owned=SchedulerCore::dispatchableCapacity(presence(3),$rows,constraints($sameOwner,['project-a'=>known(3,0)]));
    $peerRows=[work('work-a','project-a',[],['src/shared.php']),work('work-b','project-a',[],['src/shared.php'])];
    $peer=SchedulerCore::dispatchableCapacity(presence(3),$peerRows,constraints([],['project-a'=>known(3,0)]));
    echo json_encode(compact('active','owned','peer'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='concurrency'){
    $rows=[work('work-a'),work('work-b'),work('work-c')];
    $out=SchedulerCore::dispatchableCapacity(presence(5),$rows,constraints([],['project-a'=>known(2,1)]));
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='joint_bound'){
    $rows=[work('work-a','project-a'),work('work-b','project-a'),
        work('work-c','project-b',[],['shared/x']),work('work-d','project-c',[],['shared/x'])];
    $projects=['project-a'=>known(1,0),'project-b'=>known(1,0),'project-c'=>known(1,0)];
    $out=SchedulerCore::dispatchableCapacity(presence(4),$rows,constraints([],$projects));
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='unknown'){
    $dep=SchedulerCore::dispatchableCapacity(
        presence(4),[work('work-a','project-a',['dep-a'=>'unknown'])],constraints([],['project-a'=>known()])
    );
    $policy=SchedulerCore::dispatchableCapacity(
        presence(4),[work('work-a')],constraints([],['project-a'=>unknown()])
    );
    echo json_encode(compact('dep','policy'),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='contract'){
    $out=SchedulerCore::dispatchableCapacity(
        presence(2),[work('work-b'),work('work-a')],constraints([],['project-a'=>known(2,0)])
    );
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown scheduler scenario\n"); exit(2);

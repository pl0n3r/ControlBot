<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryLiveSnapshot.php';
require __DIR__.'/../src/FactoryLiveUi.php';
use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryLiveUi;

$scenario=$argv[1]??'';
function sig(string $id,string $authority,string $state='healthy',string $fresh='current',array $data=[],int $at=180): array {
    return ['id'=>$id,'authority'=>$authority,'state'=>$state,'source_ref'=>'controlbot:evidence/'.$id,'observed_at'=>$at,'freshness'=>$fresh,'data'=>$data];
}
function fixture(): array {
    return [
        'batches'=>[sig('batch:tanda-1','factory_plan','healthy','current',['title'=>'Tanda 1','repository'=>'Condor'])],
        'owner_decisions'=>[sig('decision:condor-384','owner_inbox','pending','current',['title'=>'Revisión jurídica de privacidad','issue_ref'=>'https://github.com/pl0n3r/Condor/issues/384'],190)],
        'releases'=>[sig('release:factory-v1','github_project_snapshot','healthy','current',['tag'=>'v1','repository'=>'Factory'])],
        'blockers'=>[sig('blocker:controlbot-65','github_project_snapshot','blocked','current',['title'=>'Bloqueo de gobernanza','issue'=>65])],
        'production'=>[sig('production:condor','observability_project_status','healthy','current',['project'=>'Condor','version'=>'0.1.97'])],
        'quality'=>[sig('quality:condor','quality_health','healthy','current',['project'=>'Condor','quality_gate'=>'PASS'])],
        'work'=>[sig('work:controlbot-563','github_project_snapshot','pending','current',['title'=>'Fábrica viva UI','issue'=>563],188)],
        'learning'=>[sig('learning:incident-385','incident_lesson','healthy','current',['title'=>'Fixture de release aislado','preventive_rules'=>1],187)],
        'tool_usage'=>sig('tool-usage:remote-desktop','tool_usage','degraded','current',['title'=>'Remote Desktop Commander','limit'=>10000,'used'=>4200],189),
    ];
}
if($scenario==='full'){echo FactoryLiveUi::render(FactoryLiveSnapshot::build(fixture(),200)),PHP_EOL;exit;}
if($scenario==='empty'){echo FactoryLiveUi::render(FactoryLiveSnapshot::build(['tool_usage'=>null],200)),PHP_EOL;exit;}
if($scenario==='stale'){
    $raw=fixture();$raw['quality'][0]['state']='degraded';$raw['quality'][0]['freshness']='stale';
    echo FactoryLiveUi::render(FactoryLiveSnapshot::build($raw,200)),PHP_EOL;exit;
}
if($scenario==='incoherent'){
    $snapshot=FactoryLiveSnapshot::build(fixture(),200);
    $snapshot['sections']['quality'][0]['freshness']='unknown';
    $snapshot['sections']['quality'][0]['state']='healthy';
    $snapshot['sections']['quality'][0]['source_ref']=null;
    $snapshot['sections']['quality'][0]['observed_at']=null;
    $snapshot['sections']['quality'][0]['age_seconds']=null;
    try{FactoryLiveUi::render($snapshot);echo "accepted\n";}catch(InvalidArgumentException){echo "blocked\n";}exit;
}
if($scenario==='hostile'){
    $raw=fixture();$raw['work'][0]['data']['title']='<script>alert(1)</script>';$raw['work'][0]['data']['detail']='<img src=x onerror=alert(1)>';
    echo FactoryLiveUi::render(FactoryLiveSnapshot::build($raw,200)),PHP_EOL;exit;
}
fwrite(STDERR,"scenario inválido\n");exit(2);

<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryLiveSnapshot.php';
require __DIR__.'/../src/FactoryLiveMatrix.php';
require __DIR__.'/../src/FactoryLiveUi.php';
use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryLiveMatrix;
use ControlBot\Business\FactoryLiveUi;

function sig(string $id,string $authority,string $state='healthy',string $fresh='current',array $data=[],int $at=180,string $source='https://github.com/pl0n3r/ControlBot/issues/564'): array {
    return ['id'=>$id,'authority'=>$authority,'state'=>$state,'source_ref'=>$source,'observed_at'=>$at,'freshness'=>$fresh,'data'=>$data];
}
function fixture(): array {
    $projects=['Condor','GrindFlow','BRVTAL','FactoryRunner','ControlBot','AutoFactory','Factory'];
    $deps=['governance','sre_infra_dba','security','qa_engineering','legal_privacy'];$work=[];
    foreach($projects as $project)foreach($deps as $dep){
        $slug=strtolower($project).'-'.str_replace('_','-',$dep);
        $work[]=sig('work:'.$slug,'github_project_snapshot','healthy','current',['title'=>$project.' '.$dep,'project'=>$project,'department'=>$dep]);
    }
    return [
        'batches'=>[sig('batch:tanda-3','factory_plan','healthy','current',['title'=>'Tanda 3'])],
        'owner_decisions'=>[sig('decision:condor-384','owner_inbox','pending','current',['title'=>'Decisión jurídica','issue_ref'=>'https://github.com/pl0n3r/Condor/issues/384'])],
        'releases'=>[sig('release:factory-v1','github_project_snapshot','healthy','current',['title'=>'Factory v1'])],
        'blockers'=>[
            sig('blocker:controlbot','github_project_snapshot','blocked','current',['title'=>'Bloqueo operativo']),
            sig('incident:controlbot','github_project_snapshot','critical','current',['title'=>'Incidente abierto','kind'=>'incident','open'=>true,'project'=>'ControlBot','department'=>'sre_infra_dba']),
        ],
        'work'=>$work,'tool_usage'=>null,
    ];
}
function mutate(array $raw,string $project,string $department,string $state,string $fresh): array {
    foreach($raw['work'] as &$row)if(($row['data']['project']??null)===$project&&($row['data']['department']??null)===$department){$row['state']=$state;$row['freshness']=$fresh;break;}unset($row);return $raw;
}
$scenario=$argv[1]??'';$mode=$argv[2]??'json';$raw=fixture();
if($scenario==='empty')$raw=['tool_usage'=>null];
elseif($scenario==='stale')$raw=mutate($raw,'Condor','governance','degraded','stale');
elseif($scenario==='error')$raw=mutate($raw,'GrindFlow','security','critical','current');
elseif($scenario==='hostile'){
    foreach($raw['work'] as &$row)
        if(($row['data']['project']??null)==='Factory'&&($row['data']['department']??null)==='qa_engineering'){$row['data']['title']='<img src=x onerror=alert(1)>';break;}
    unset($row);
}elseif($scenario!=='full'){fwrite(STDERR,"scenario inválido\n");exit(2);}
$snapshot=FactoryLiveSnapshot::build($raw,200);$matrix=FactoryLiveMatrix::build($snapshot);
echo $mode==='html'?FactoryLiveUi::render($snapshot,$matrix):json_encode($matrix,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;

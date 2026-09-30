<?php
declare(strict_types=1);

require __DIR__.'/../src/FactoryLiveSnapshot.php';
require __DIR__.'/../src/FactoryLiveCostSnapshot.php';
require __DIR__.'/../src/FactoryLiveUi.php';

use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryLiveCostSnapshot;
use ControlBot\Business\FactoryLiveUi;

function signal(string $id,string $department,int|float $value): array
{
    return [
        'id'=>$id,'authority'=>'github_project_snapshot','state'=>'healthy',
        'source_ref'=>'github:pl0n3r/ControlBot#566','observed_at'=>2990,
        'freshness'=>'current','data'=>[
            'department'=>$department,'project'=>'ControlBot',
            'metric'=>'delivery_score','metric_value'=>$value,
        ],
    ];
}

function baseSnapshot(?int $used=4200,?int $limit=10000,bool $withSignals=true): array
{
    $raw=['tool_usage'=>null];
    if($withSignals)$raw['work']=[
        signal('work:product','product',0),
        signal('work:analytics','data_analytics',42),
    ];
    if($used!==null&&$limit!==null)$raw['tool_usage']=[
        'id'=>'tool:remote-desktop','authority'=>'tool_usage','state'=>'healthy',
        'source_ref'=>'controlbot:tools/remote-desktop','observed_at'=>2990,
        'freshness'=>'current','data'=>[
            'title'=>'Remote Desktop Commander','used'=>$used,'limit'=>$limit,
        ],
    ];
    return FactoryLiveSnapshot::build($raw,3000);
}

function built(?int $used=4200,?int $limit=10000,bool $withSignals=true): array
{
    return FactoryLiveCostSnapshot::build(baseSnapshot($used,$limit,$withSignals));
}

$scenario=$argv[1]??'';
if($scenario==='full'){
    echo json_encode(built(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='missing'){
    echo json_encode(built(null,null,false),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='zero'){
    echo json_encode(built(0,10000),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='secret'){
    $snapshot=baseSnapshot();
    $snapshot['sections']['work'][0]['data']['api_key']='forbidden';
    $canonical=$snapshot;unset($canonical['fingerprint']);
    $snapshot['fingerprint']=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    $blocked=false;try{FactoryLiveCostSnapshot::build($snapshot);}catch(Throwable){$blocked=true;}
    echo json_encode(['blocked'=>$blocked],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='ui'){
    $snapshot=baseSnapshot();
    echo FactoryLiveUi::render($snapshot,null,null,FactoryLiveCostSnapshot::build($snapshot)),PHP_EOL;exit;
}
fwrite(STDERR,"scenario inválido\n");exit(2);

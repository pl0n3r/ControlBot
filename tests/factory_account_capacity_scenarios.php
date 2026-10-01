<?php
declare(strict_types=1);

require __DIR__.'/../src/FactoryAccountCapacitySnapshot.php';
require __DIR__.'/../src/FactoryLiveSnapshot.php';
require __DIR__.'/../src/FactoryLiveUi.php';

use ControlBot\Business\FactoryAccountCapacitySnapshot;
use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryLiveUi;

function cap(string $alias='primary',string $status='FRESH',string $fresh='current',int $at=2990): array
{
    if($status==='UNKNOWN')return [
        'account_alias'=>$alias,'status'=>'UNKNOWN','confidence'=>'none','budget'=>null,
        'sent'=>null,'remaining'=>null,'limit_events'=>null,'source_ref'=>null,
        'observed_at'=>null,'freshness'=>'unknown',
    ];
    $budget=['limit'=>10,'windowMs'=>3600000,'minIntervalMs'=>360000];
    return [
        'account_alias'=>$alias,'status'=>$status,'confidence'=>'high','budget'=>$budget,
        'sent'=>4,'remaining'=>$status==='FRESH'?6:null,'limit_events'=>2,
        'source_ref'=>'github:pl0n3r/Factory#584','observed_at'=>$at,'freshness'=>$fresh,
    ];
}
function live(): array { return FactoryLiveSnapshot::build(['tool_usage'=>null],3000); }
function blocked(array $rows): bool
{
    try{FactoryAccountCapacitySnapshot::build($rows,3000);return false;}
    catch(Throwable){return true;}
}

$scenario=$argv[1]??'';
if($scenario==='full'){
    echo json_encode(FactoryAccountCapacitySnapshot::build([cap('token_bucket'),cap('dsn-monitor')],3000),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='reverse'){
    echo json_encode(FactoryAccountCapacitySnapshot::build([cap('dsn-monitor'),cap('token_bucket')],3000),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='invalid'){
    $cases=[];
    $row=cap();$row['account_alias']='person@example.com';$cases['alias']=blocked([$row]);
    $row=cap();$row['extra']='x';$cases['extra']=blocked([$row]);
    $row=cap();$row['sent']=-1;$cases['negative']=blocked([$row]);
    $row=cap();$row['remaining']=5;$cases['remaining']=blocked([$row]);
    $row=cap();$row['observed_at']=3001;$cases['future']=blocked([$row]);
    $row=cap();$row['sent']=11;$row['remaining']=0;$cases['over_budget']=blocked([$row]);
    echo json_encode($cases,JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='unknown_stale'){
    echo json_encode(FactoryAccountCapacitySnapshot::build([cap('unknown','UNKNOWN','unknown'),cap('stale','STALE','stale')],3000),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='empty'){
    echo json_encode(FactoryAccountCapacitySnapshot::build([],3000),JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='ui'){
    $capacity=FactoryAccountCapacitySnapshot::build([cap('primary')],3000);
    echo FactoryLiveUi::render(live(),null,null,null,$capacity),PHP_EOL;exit;
}
if($scenario==='ui_unknown'){
    echo FactoryLiveUi::render(live(),null,null,null,FactoryAccountCapacitySnapshot::build([],3000)),PHP_EOL;exit;
}
if($scenario==='ui_tamper'){
    $capacity=FactoryAccountCapacitySnapshot::build([cap('stale','STALE','stale')],3000);
    $capacity['accounts'][0]['remaining']=6;
    $blocked=false;try{FactoryLiveUi::render(live(),null,null,null,$capacity);}catch(Throwable){$blocked=true;}
    echo json_encode(['blocked'=>$blocked],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='ui_recomputed_tamper'){
    $out=[];
    foreach(['alias','source','alias_ws','source_ws','duplicate','reverse','cardinality'] as $kind){
        if($kind==='cardinality'){
            $rows=[];for($i=0;$i<50;$i++)$rows[]=cap(sprintf('acct%02d',$i));
        }else{
            $rows=in_array($kind,['duplicate','reverse'],true)?[cap('alpha'),cap('beta')]:[cap('primary')];
        }
        $capacity=FactoryAccountCapacitySnapshot::build($rows,3000);
        if($kind==='alias')$capacity['accounts'][0]['accountAlias']='person@example.com';
        elseif($kind==='source')$capacity['accounts'][0]['source_ref']='not a canonical source with spaces';
        elseif($kind==='alias_ws')$capacity['accounts'][0]['accountAlias']=' primary ';
        elseif($kind==='source_ws')$capacity['accounts'][0]['source_ref']=' github:pl0n3r/Factory#584 ';
        elseif($kind==='duplicate')$capacity['accounts'][1]['accountAlias']=$capacity['accounts'][0]['accountAlias'];
        elseif($kind==='reverse')$capacity['accounts']=array_reverse($capacity['accounts']);
        else{
            $extra=$capacity['accounts'][49];$extra['accountAlias']='acct50';$capacity['accounts'][]=$extra;
        }
        $canonical=$capacity;unset($canonical['fingerprint']);
        $capacity['fingerprint']=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        $blocked=false;try{FactoryLiveUi::render(live(),null,null,null,$capacity);}catch(Throwable){$blocked=true;}
        $out[$kind]=$blocked;
    }
    echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"scenario inválido\n");exit(2);

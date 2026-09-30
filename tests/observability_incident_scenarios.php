<?php
declare(strict_types=1);

require __DIR__.'/../src/ObservabilityEvent.php';
require __DIR__.'/../src/IncidentTimeline.php';
require __DIR__.'/../src/ObservabilityIncident.php';

use ControlBot\Business\IncidentTimeline;
use ControlBot\Observability\ObservabilityIncident;

function obs(string $source,string $type,string $severity,array $payload,int $at,int $received,array $keys): array {
    return ['version'=>1,'source'=>$source,'project_id'=>'controlbot','environment_id'=>'production',
        'repository_id'=>'pl0n3r/ControlBot','severity'=>$severity,'type'=>$type,'payload'=>$payload,
        'occurred_at'=>$at,'received_at'=>$received,'correlation_keys'=>$keys];
}
function health(string $status,int $at,int $received,array $keys,string $severity='warning',array $extra=[]): array
{ return obs('health','health_probe',$severity,array_merge(['status'=>$status],$extra),$at,$received,$keys); }
function correlate(array $events,int $now=140,array $ttl=null): array
{ return ObservabilityIncident::correlate($events,$now,$ttl??['health'=>300,'ci'=>300,'deploy'=>300,'agent'=>300],300,900); }

$case=$argv[1]??'';
if($case==='dedupe'){
    $a=health('down',100,101,['project:controlbot','issue:78'],'error');
    $b=array_replace($a,['received_at'=>102]);
    echo json_encode(correlate([$b,$a]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='deploy'){
    $deploy=obs('deploy','deploy','info',[
        'status'=>'success','deployment_ref'=>'deploy-42','sha'=>'abcdef',
    ],100,101,['project:controlbot','deploy:deploy-42']);
    $linked=health('down',120,121,['project:controlbot','deploy:deploy-42'],'error',['sha'=>'abcdef']);
    $unlinked=health('down',122,123,['project:controlbot','issue:other'],'error');

    echo json_encode([
        'linked'=>correlate([$linked,$deploy]),
        'unlinked'=>correlate([$unlinked,$deploy]),
        'too_late'=>ObservabilityIncident::correlate(
            [health('down',1200,1201,['project:controlbot','deploy:deploy-42'],'error',['sha'=>'abcdef']),$deploy],
            1210,['health'=>300,'ci'=>300,'deploy'=>2000,'agent'=>300],300,900
        ),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='recovery'){
    $failure=health('down',100,101,['project:controlbot','issue:78'],'error');
    $monitor=health('monitoring',110,111,['project:controlbot','issue:78'],'warning');
    $healthy=health('healthy',120,121,['project:controlbot','issue:78'],'info');
    $resolved=correlate([$healthy,$failure,$monitor],130);
    $incident=$resolved['incidents'][0];
    $validated=IncidentTimeline::build([
        'version'=>1,'incident_ref'=>$incident['incident_id'],
        'opened_at'=>$incident['opened_at'],'detected_at'=>$incident['opened_at'],
        'recovered_at'=>$incident['resolved_at'],'events'=>$incident['timeline_events'],
    ]);

    $stale=correlate([$failure,$healthy],500,['health'=>60,'ci'=>300,'deploy'=>300,'agent'=>300]);
    $unknown=correlate([$failure,$healthy],130,['ci'=>300,'deploy'=>300,'agent'=>300]);
    echo json_encode([
        'resolved'=>$resolved,'timeline'=>$validated,
        'stale_status'=>$stale['incidents'][0]['status'],
        'unknown_status'=>$unknown['incidents'][0]['status'],
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='separate'){
    $a=health('down',100,101,['project:controlbot','issue:one'],'error');
    $b=health('down',101,102,['project:controlbot','issue:two'],'error');
    echo json_encode(correlate([$b,$a]),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='severity'){
    $warning=health('degraded',100,101,['project:controlbot','issue:77'],'warning');
    $critical=health('down',110,111,['project:controlbot','issue:77'],'critical');
    echo json_encode([
        'forward'=>correlate([$warning,$critical]),
        'reverse'=>correlate([$critical,$warning]),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='pure'){
    $source=strtolower((string)file_get_contents(__DIR__.'/../src/ObservabilityIncident.php'));
    $forbidden=['new pdo','mysqli','curl_','file_put_contents','fopen(','shell_exec','proc_open','passthru(','system(','exec(','mail(','factoryrunner','scheduler'];
    $hits=[];foreach($forbidden as $needle){if(str_contains($source,$needle))$hits[]=$needle;}
    echo json_encode(['hits'=>$hits],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown observability incident scenario\n");exit(2);

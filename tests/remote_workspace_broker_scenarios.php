<?php
declare(strict_types=1);

require __DIR__.'/../src/RemoteWorkspaceBroker.php';
use ControlBot\Production\RemoteWorkspaceBroker;

const REF_542='workspace:brvtal:prod';
const REF2_542='workspace:brvtal:prod:v2';
const PATH_542='/srv/apps/brvtal/current';

function meta542(string $ref=REF_542,int $generation=1): array
{
    return [
        'version'=>1,'workspace_ref'=>$ref,'provider'=>'hostinger',
        'project'=>'brvtal','environment'=>'production','generation'=>$generation,
        'issued_at'=>'2027-01-15T07:00:00Z','revoked_at'=>null,
    ];
}
function context542(string $ref=REF_542,int $generation=1): array
{
    return [
        'executor_id'=>'hostinger-executor','workspace_ref'=>$ref,'provider'=>'hostinger',
        'project'=>'brvtal','environment'=>'production','generation'=>$generation,
    ];
}
function broker542(string $path=PATH_542): RemoteWorkspaceBroker
{
    $broker=new RemoteWorkspaceBroker(['hostinger-executor']);
    $broker->register(meta542(),$path);
    return $broker;
}
function rejectedPath542(string $path): bool
{
    try { broker542($path); return false; } catch (Throwable) { return true; }
}

$name=$argv[1]??'';
if ($name==='authorized') {
    $broker=broker542(); $calls=0;
    $ok=$broker->execute(context542(),static function(string $path) use (&$calls): array {
        $calls++; return ['seen'=>$path];
    });
    $unauthorized=context542(); $unauthorized['executor_id']='rogue-executor';
    $mismatch=context542(); $mismatch['project']='condor';
    $out=['ok'=>$ok,'calls'=>$calls,
        'unauthorized'=>$broker->execute($unauthorized,static fn()=>['unexpected']),
        'mismatch'=>$broker->execute($mismatch,static fn()=>['unexpected'])];
} elseif ($name==='surface') {
    $out=broker542()->agentSurface(REF_542);
} elseif ($name==='failures') {
    $unknown=broker542()->execute(context542('workspace:missing'),static fn()=>['unexpected']);
    $revoked=broker542(); $revoked->revoke(REF_542,'2027-01-15T08:00:00Z');
    $stale=context542(); $stale['generation']=2;
    $scope=context542(); $scope['environment']='staging';
    $out=[
        'unknown'=>$unknown,
        'revoked'=>$revoked->execute(context542(),static fn()=>['unexpected']),
        'stale'=>broker542()->execute($stale,static fn()=>['unexpected']),
        'scope'=>broker542()->execute($scope,static fn()=>['unexpected']),
    ];
} elseif ($name==='rotation') {
    $broker=broker542();
    $new=$broker->rotate(REF_542,REF2_542,'2027-01-15T08:00:00Z','/srv/apps/brvtal/releases/2');
    $old=$broker->execute(context542(),static fn()=>['unexpected']);
    $ctx=context542(REF2_542,2);
    $resolved=$broker->execute($ctx,static fn(string $path)=>['path'=>$path]);
    $out=['new'=>$new,'old'=>$old,'resolved'=>$resolved];
} elseif ($name==='paths') {
    $out=[];
    foreach ([
        'relative/path','/srv/../secret','/srv//app','/srv/./app',
        "/srv/app\nsecret",'C:\\srv\\app','/srv/app bad',
    ] as $path) $out[$path]=rejectedPath542($path);
    $out['valid']=!rejectedPath542('/home/u123/domains/example.com/public_html');
} elseif ($name==='redaction') {
    $broker=broker542();
    $success=$broker->execute(context542(),static fn(string $path)=>[
        'message'=>'workspace='.$path,'nested'=>['path'=>$path],
    ]);
    $failure=$broker->execute(context542(),static function(string $path): never {
        throw new RuntimeException('failed at '.$path);
    });
    $out=['success'=>$success,'failure'=>$failure];
} else {
    exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

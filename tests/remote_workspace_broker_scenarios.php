<?php
declare(strict_types=1);

require __DIR__.'/../src/RemoteWorkspaceBroker.php';
use ControlBot\Production\RemoteWorkspaceBroker;

const REF_542='workspace:brvtal:prod';
const REF2_542='workspace:brvtal:prod:v2';
const PATH_542='/srv/apps/brvtal/current';

function meta542(array $overrides=[]): array
{
    return array_replace([
        'version'=>1,'workspace_ref'=>REF_542,'provider'=>'hostinger',
        'project'=>'brvtal','environment'=>'production','generation'=>1,
        'issued_at'=>'2027-01-15T07:00:00Z','revoked_at'=>null,
    ],$overrides);
}

function context542(array $overrides=[]): array
{
    return array_replace([
        'executor_id'=>'hostinger-executor','workspace_ref'=>REF_542,'provider'=>'hostinger',
        'project'=>'brvtal','environment'=>'production','generation'=>1,
    ],$overrides);
}

function broker542(string $path=PATH_542): RemoteWorkspaceBroker
{
    $broker=new RemoteWorkspaceBroker(['hostinger-executor']);
    $broker->register(meta542(),$path);
    return $broker;
}

function execute542(RemoteWorkspaceBroker $broker,array $overrides=[]): array
{
    return $broker->execute(
        context542($overrides),
        static fn(string $path): array=>['seen'=>$path],
    );
}

function rejectedPath542(string $path): bool
{
    try {
        broker542($path);
        return false;
    } catch (Throwable) {
        return true;
    }
}

$name=$argv[1]??'';
if ($name==='authorized') {
    $broker=broker542();
    $calls=0;
    $ok=$broker->execute(context542(),static function(string $path) use (&$calls): array {
        $calls++;
        return ['seen'=>$path];
    });
    $out=[
        'ok'=>$ok,
        'calls'=>$calls,
        'unauthorized'=>execute542($broker,['executor_id'=>'rogue-executor']),
        'mismatch'=>execute542($broker,['project'=>'condor']),
    ];
} elseif ($name==='surface') {
    $out=broker542()->agentSurface(REF_542);
} elseif ($name==='failures') {
    $cases=[
        'unknown'=>[broker542(),['workspace_ref'=>'workspace:missing']],
        'stale'=>[broker542(),['generation'=>2]],
        'scope'=>[broker542(),['environment'=>'staging']],
    ];
    $revoked=broker542();
    $revoked->revoke(REF_542,'2027-01-15T08:00:00Z');
    $cases['revoked']=[$revoked,[]];
    $out=[];
    foreach ($cases as $key=>[$broker,$overrides]) {
        $out[$key]=execute542($broker,$overrides);
    }
} elseif ($name==='rotation') {
    $broker=broker542();
    $new=$broker->rotate(
        REF_542,
        REF2_542,
        '2027-01-15T08:00:00Z',
        '/srv/apps/brvtal/releases/2',
    );
    $out=[
        'new'=>$new,
        'old'=>execute542($broker),
        'resolved'=>execute542($broker,['workspace_ref'=>REF2_542,'generation'=>2]),
    ];
} elseif ($name==='paths') {
    $paths=[
        'relative/path','/srv/../secret','/srv//app','/srv/./app',
        "/srv/app\nsecret",'C:\\srv\\app','/srv/app bad',
    ];
    $out=array_combine($paths,array_map('rejectedPath542',$paths));
    $out['valid']=!rejectedPath542('/home/u123/domains/example.com/public_html');
} elseif ($name==='redaction') {
    $broker=broker542();
    $success=$broker->execute(context542(),static fn(string $path): array=>[
        'message'=>'workspace='.$path,
        'nested'=>['path'=>$path],
    ]);
    $failure=$broker->execute(context542(),static function(string $path): never {
        throw new RuntimeException('failed at '.$path);
    });
    $out=['success'=>$success,'failure'=>$failure];
} else {
    exit(2);
}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

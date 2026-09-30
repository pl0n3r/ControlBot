<?php
declare(strict_types=1);

require __DIR__ . '/../src/ConnectionIdentityBroker.php';
use ControlBot\Production\ConnectionIdentityBroker;

const USERNAME = 'deploy_user_528';
const REF = 'vault:user:brvtal';

function identity(array $replace = []): array {
    return array_replace([
        'version'=>1,'username_ref'=>REF,'provider'=>'hostinger','project'=>'brvtal',
        'environment'=>'production','generation'=>1,'issued_at'=>'2027-01-15T07:00:00Z',
        'revoked_at'=>null,
    ], $replace);
}
function context(array $replace = []): array {
    return array_replace([
        'executor_id'=>'hostinger-executor','username_ref'=>REF,'provider'=>'hostinger',
        'project'=>'brvtal','environment'=>'production','generation'=>1,
    ], $replace);
}
function broker(): ConnectionIdentityBroker {
    $broker = new ConnectionIdentityBroker(['hostinger-executor']);
    $broker->register(identity(), USERNAME);
    return $broker;
}

$name = $argv[1] ?? '';
if ($name === 'authorized') {
    $broker=broker(); $calls=0; $seen=null;
    $result=$broker->execute(context(), static function(string $username) use (&$calls,&$seen): string {
        $calls++; $seen=$username; return 'used:'.$username;
    });
    $rogueCalls=0;
    $rogue=$broker->execute(context(['executor_id'=>'rogue-executor']), static function() use (&$rogueCalls): string {
        $rogueCalls++; return 'never';
    });
    $out=['result'=>$result,'calls'=>$calls,'callback_username'=>$seen,'rogue'=>$rogue,'rogue_calls'=>$rogueCalls];
} elseif ($name === 'surface') {
    $broker=broker(); $seen=null; $surface=$broker->agentSurface(REF);
    $result=$broker->execute(context(), static function(string $username) use (&$seen): string {
        $seen=$username; return 'used:'.$username;
    });
    $out=['surface'=>$surface,'callback_username'=>$seen,'result'=>$result,
        'visible_contains_username'=>str_contains(json_encode([$surface,$result],JSON_THROW_ON_ERROR),USERNAME)];
} elseif ($name === 'invalid') {
    $broker=broker(); $calls=0;
    $executor=static function() use (&$calls): string { $calls++; return 'never'; };
    $cases=[
        'executor'=>$broker->execute(context(['executor_id'=>'rogue-executor']),$executor),
        'unknown'=>$broker->execute(context(['username_ref'=>'vault:user:unknown']),$executor),
        'provider'=>$broker->execute(context(['provider'=>'other']),$executor),
        'project'=>$broker->execute(context(['project'=>'condor']),$executor),
        'environment'=>$broker->execute(context(['environment'=>'staging']),$executor),
        'generation'=>$broker->execute(context(['generation'=>2]),$executor),
        'extra'=>$broker->execute(context()+['username'=>USERNAME],$executor),
    ];
    $broker->revoke(REF,'2027-01-15T07:05:00Z');
    $cases['revoked']=$broker->execute(context(),$executor);
    $out=['cases'=>$cases,'calls'=>$calls];
} elseif ($name === 'rotation') {
    $broker=broker(); $newRef='vault:user:brvtal:g2';
    $new=$broker->rotate(REF,$newRef,'2027-01-15T08:00:00Z','deploy_user_rotated');
    $old=$broker->execute(context(),static fn(): string=>'never');
    $seen=null;
    $resolved=$broker->execute(context(['username_ref'=>$newRef,'generation'=>2]),
        static function(string $username) use (&$seen): string { $seen=$username; return 'resolved'; });
    $temporal=[];
    foreach ([
        'invalid_date'=>static fn()=>broker()->register(identity(['issued_at'=>'2027-02-30T07:00:00Z']),USERNAME),
        'revoke_before'=>static fn()=>broker()->revoke(REF,'2027-01-15T06:59:59Z'),
        'rotate_before'=>static fn()=>broker()->rotate(REF,'vault:user:before','2027-01-15T06:59:59Z','deploy_before'),
        'rotate_equal'=>static fn()=>broker()->rotate(REF,'vault:user:equal','2027-01-15T07:00:00Z','deploy_equal'),
    ] as $key=>$operation) {
        try { $operation(); $temporal[$key]=false; } catch (InvalidArgumentException) { $temporal[$key]=true; }
    }
    $out=['new'=>$new,'old'=>$old,'resolved'=>$resolved,'resolved_username'=>$seen,'temporal'=>$temporal,
        'scope_preserved'=>$new['provider']==='hostinger'&&$new['project']==='brvtal'&&$new['environment']==='production'];
} elseif ($name === 'redaction') {
    $broker=broker();
    $result=$broker->execute(context(),static fn(string $username): array=>[
        'message'=>'login='.$username,'nested'=>[$username=>'value','safe'=>$username],
    ]);
    $error=$broker->execute(context(),static function(string $username): never {
        throw new RuntimeException('failed for '.$username);
    });
    $out=['result'=>$result,'error'=>$error,
        'contains_username'=>str_contains(json_encode([$result,$error],JSON_THROW_ON_ERROR),USERNAME)];
} else {
    exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

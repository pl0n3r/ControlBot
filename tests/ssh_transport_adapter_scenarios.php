<?php
declare(strict_types=1);
require __DIR__ . '/../src/ProductionOperation.php';
require __DIR__ . '/../src/SshTransportAdapter.php';
use ControlBot\Production\SshTransportAdapter;

const SECRET = 'fixture-private-key-material-527';

function descriptor(array $overrides = []): array
{
    return array_replace([
        'operation_id'=>'ssh.readonly','capability'=>'ssh.readonly','effect'=>'read','timeout_ms'=>8000,
        'destination'=>'example.internal:22','project'=>'brvtal','environment'=>'production',
        'resource'=>'database:primary','run_id'=>'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        'username_ref'=>'vault:user:brvtal','expected_fingerprint'=>'SHA256:abcdefghijklmnop',
        'secret_kind'=>'private_key',
    ], $overrides);
}

function successResult(string $summary = 'ok'): array
{
    return ['status'=>'success','code'=>'ssh_ok','summary'=>$summary,'artifacts'=>['artifact:ssh:result:1'],'duration_ms'=>25];
}

function adapter(int &$calls, mixed &$request = null, mixed &$secret = null): SshTransportAdapter
{
    return new SshTransportAdapter(
        static fn(string $ref): string => $ref === 'vault:user:brvtal' ? 'deploy_user' : 'unknown',
        static function(array $req, string $value) use (&$calls, &$request, &$secret): array {
            $calls++; $request=$req; $secret=$value; return successResult();
        },
    );
}

function run(array $d): array
{
    $calls=0; $request=null; $secret=null;
    $result=adapter($calls,$request,$secret)($d,SECRET);
    return ['result'=>$result,'calls'=>$calls,'request'=>$request,'secret_separate'=>$secret===SECRET];
}

$name=$argv[1]??'';
if ($name==='valid') {
    $out=run(descriptor());
} elseif ($name==='invalid') {
    $cases=[
        descriptor(['destination'=>'example.internal:0']),
        descriptor(['expected_fingerprint'=>'SHA256:short']),
        descriptor(['secret_kind'=>'api_token']),
        descriptor(['username_ref'=>'bad ref']),
        descriptor(['run_id'=>'00000000-0000-0000-0000-000000000000']),
    ];
    $out=['cases'=>array_map('run',$cases)];
    $resolverCalls=0; $clientCalls=0;
    $bad=new SshTransportAdapter(
        static function() use (&$resolverCalls): string { $resolverCalls++; return 'bad user!'; },
        static function() use (&$clientCalls): array { $clientCalls++; return successResult(); },
    );
    $out['resolved_username']=$bad(descriptor(),SECRET);
    $out['resolver_calls']=$resolverCalls; $out['resolver_client_calls']=$clientCalls;
} elseif ($name==='shell') {
    $command=descriptor(); $command['command']='rm -rf /';
    $out=['command'=>run($command),'unknown'=>run(descriptor(['operation_id'=>'ssh.command']))];
} elseif ($name==='secret') {
    $calls=0; $request=null; $seen=null;
    $leaking=new SshTransportAdapter(
        static fn(): string=>'deploy_user',
        static function(array $req,string $secret) use (&$calls,&$request,&$seen): array {
            $calls++; $request=$req; $seen=$secret; return successResult('echo '.$secret);
        },
    );
    $result=$leaking(descriptor(),SECRET);
    $artifact=new SshTransportAdapter(
        static fn(): string=>'deploy_user',
        static fn(array $req,string $secret): array=>[
            'status'=>'success','code'=>'ssh_ok','summary'=>'ok','artifacts'=>[$secret],'duration_ms'=>1,
        ],
    );
    $out=[
        'result'=>$result,'calls'=>$calls,'secret_separate'=>$seen===SECRET,
        'request_contains_secret'=>str_contains(json_encode($request,JSON_THROW_ON_ERROR),SECRET),
        'visible_contains_secret'=>str_contains(json_encode($result,JSON_THROW_ON_ERROR),SECRET),
        'artifact_result'=>$artifact(descriptor(),SECRET),
    ];
} elseif ($name==='failures') {
    $out=[];
    foreach ([
        'host_key_mismatch'=>['success','ssh_host_key_mismatch'],
        'auth_failure'=>['success','ssh_auth_failed'],
        'timeout'=>['success','ssh_timeout'],
        'partial'=>['partial','ssh_partial'],
        'cancelled'=>['cancelled','ssh_cancelled'],
    ] as $key=>[$status,$code]) {
        $transport=new SshTransportAdapter(
            static fn(): string=>'deploy_user',
            static fn(): array=>['status'=>$status,'code'=>$code,'summary'=>$key,'artifacts'=>[],'duration_ms'=>10],
        );
        $out[$key]=$transport(descriptor(),SECRET);
    }
    $throwing=new SshTransportAdapter(static fn(): string=>'deploy_user',static function(): array { throw new RuntimeException('secret='.SECRET); });
    $out['exception']=$throwing(descriptor(),SECRET);
} else {
    exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

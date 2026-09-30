<?php
declare(strict_types=1);

require __DIR__ . '/../src/ProductionOperation.php';
require __DIR__ . '/../src/ConnectionIdentityBroker.php';
require __DIR__ . '/../src/SshConnectionIdentityResolver.php';
require __DIR__ . '/../src/SshTransportAdapter.php';

use ControlBot\Production\ConnectionIdentityBroker;
use ControlBot\Production\SshConnectionIdentityResolver;
use ControlBot\Production\SshTransportAdapter;

const SECRET_535 = 'fixture-private-key-material-535';
const USERNAME_535 = 'deploy_user_535';
const REF_535 = 'vault:user:brvtal';

function identity535(array $replace = []): array
{
    return array_replace([
        'version'=>1,'username_ref'=>REF_535,'provider'=>'hostinger','project'=>'brvtal',
        'environment'=>'production','generation'=>3,'issued_at'=>'2027-01-15T07:00:00Z',
        'revoked_at'=>null,
    ], $replace);
}

function descriptor535(array $replace = []): array
{
    return array_replace([
        'operation_id'=>'ssh.readonly','capability'=>'ssh.readonly','effect'=>'read','timeout_ms'=>8000,
        'destination'=>'example.internal:22','project'=>'brvtal','environment'=>'production',
        'resource'=>'database:primary','run_id'=>'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        'username_ref'=>REF_535,'expected_fingerprint'=>'SHA256:abcdefghijklmnop',
        'secret_kind'=>'private_key',
    ], $replace);
}

function broker535(array $identity = null): ConnectionIdentityBroker
{
    $broker = new ConnectionIdentityBroker(['hostinger-executor']);
    $broker->register($identity ?? identity535(), USERNAME_535);
    return $broker;
}

function run535(ConnectionIdentityBroker $broker, array $descriptor): array
{
    $clientCalls=0; $identityContext=null; $clientSawUsername=false; $clientSawSecret=false;
    $resolver=new SshConnectionIdentityResolver($broker);
    $adapter=new SshTransportAdapter(
        static function(array $context, callable $consumer) use ($resolver,&$identityContext): mixed {
            $identityContext=$context;
            return $resolver($context,$consumer);
        },
        static function(array $request,string $secret) use (&$clientCalls,&$clientSawUsername,&$clientSawSecret): array {
            $clientCalls++;
            $clientSawUsername=($request['username']??null)===USERNAME_535;
            $clientSawSecret=$secret===SECRET_535;
            return [
                'status'=>'success','code'=>'ssh_readonly_probe_ok','summary'=>'ok',
                'artifacts'=>[],'duration_ms'=>25,
            ];
        },
    );
    $result=$adapter($descriptor,SECRET_535);
    return [
        'result'=>$result,'client_calls'=>$clientCalls,'identity_context'=>$identityContext,
        'client_saw_username'=>$clientSawUsername,'secret_separate'=>$clientSawSecret,
    ];
}

$name=$argv[1]??'';
if ($name==='context') {
    $out=run535(broker535(),descriptor535());
    $out['context_contains_secret']=str_contains(
        json_encode($out['identity_context'],JSON_THROW_ON_ERROR),
        SECRET_535,
    );
} elseif ($name==='scope') {
    $unknown=run535(broker535(),descriptor535(['username_ref'=>'vault:user:unknown']));

    $revokedBroker=broker535();
    $revokedBroker->revoke(REF_535,'2027-01-15T08:00:00Z');
    $revoked=run535($revokedBroker,descriptor535());

    $mismatch=run535(
        broker535(identity535(['project'=>'condor'])),
        descriptor535(),
    );
    $out=['unknown'=>$unknown,'revoked'=>$revoked,'mismatch'=>$mismatch];
} elseif ($name==='redaction') {
    $broker=broker535(); $context=null; $clientSawUsername=false; $clientSawSecret=false;
    $resolver=new SshConnectionIdentityResolver($broker);
    $adapter=new SshTransportAdapter(
        static function(array $value, callable $consumer) use ($resolver,&$context): mixed {
            $context=$value;
            return $resolver($value,$consumer);
        },
        static function(array $request,string $secret) use (&$clientSawUsername,&$clientSawSecret): array {
            $clientSawUsername=($request['username']??null)===USERNAME_535;
            $clientSawSecret=$secret===SECRET_535;
            return [
                'status'=>'success','code'=>'ssh_readonly_probe_ok',
                'summary'=>'connected as '.USERNAME_535,
                'artifacts'=>[],'duration_ms'=>20,
            ];
        },
    );
    $result=$adapter(descriptor535(),SECRET_535);
    $out=[
        'result'=>$result,'client_saw_username'=>$clientSawUsername,'secret_separate'=>$clientSawSecret,
        'visible_contains_username'=>str_contains(json_encode($result,JSON_THROW_ON_ERROR),USERNAME_535),
        'visible_contains_secret'=>str_contains(json_encode($result,JSON_THROW_ON_ERROR),SECRET_535),
    ];
} elseif ($name==='composed') {
    $out=run535(broker535(),descriptor535());
} else {
    exit(2);
}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

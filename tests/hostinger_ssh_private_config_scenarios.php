<?php
declare(strict_types=1);

require __DIR__ . '/../src/CapabilityPolicy.php';
require __DIR__ . '/../src/CapabilityGrant.php';
require __DIR__ . '/../src/ConnectionProfile.php';
require __DIR__ . '/../src/SecretReference.php';
require __DIR__ . '/../src/SecretsBroker.php';
require __DIR__ . '/../src/ConnectionIdentityBroker.php';
require __DIR__ . '/../src/ProductionOperation.php';
require __DIR__ . '/../src/HostingerExecutor.php';
require __DIR__ . '/../src/SshConnectionIdentityResolver.php';
require __DIR__ . '/../src/SshTransportAdapter.php';
require __DIR__ . '/../src/OpenSshClient.php';
require __DIR__ . '/../src/HostingerSshRuntime.php';
require __DIR__ . '/../src/HostingerSshPrivateConfig.php';

use ControlBot\Production\CapabilityGrant;
use ControlBot\Production\HostingerSshPrivateConfig;
use ControlBot\Production\OpenSshClient;
use ControlBot\Production\ProductionOperation;

const NOW_543=1_799_997_000;
const USER_543='deploy_user_543';
const SECRET_REF_543='11111111-2222-4333-8444-555555555555';
const USER_REF_543='vault:user:brvtal';
const HOST_BLOB_543='host-key-fixture-543';

function key543(): string
{
    return "-----BEGIN OPENSSH PRIVATE KEY-----\n"
        .str_repeat('B',80)."\n-----END OPENSSH PRIVATE KEY-----\n";
}
function fp543(string $blob=HOST_BLOB_543): string
{
    return 'SHA256:'.rtrim(base64_encode(hash('sha256',$blob,true)),'=');
}
function profile543(array $x=[]): array
{
    return array_replace([
        'version'=>1,'profile_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'project'=>'brvtal','environment'=>'production','provider'=>'hostinger','transport'=>'ssh',
        'host'=>'example.internal','port'=>22,'username_ref'=>USER_REF_543,
        'secret_ref'=>SECRET_REF_543,'host_fingerprint'=>fp543(),'status'=>'connected',
        'verified_at'=>'2027-01-15T07:00:00+00:00','last_health_at'=>'2027-01-15T07:00:00+00:00',
        'revoked_at'=>null,
    ],$x);
}
function secret543(array $x=[]): array
{
    return array_replace([
        'version'=>1,'reference_id'=>SECRET_REF_543,'capability'=>'ssh.readonly',
        'project'=>'brvtal','environment'=>'production','provider'=>'hostinger',
        'secret_kind'=>'private_key','generation'=>1,'issued_at'=>'2027-01-15T07:00:00Z',
        'revoked_at'=>null,
    ],$x);
}
function identity543(array $x=[]): array
{
    return array_replace([
        'version'=>1,'username_ref'=>USER_REF_543,'provider'=>'hostinger',
        'project'=>'brvtal','environment'=>'production','generation'=>1,
        'issued_at'=>'2027-01-15T07:00:00Z','revoked_at'=>null,
    ],$x);
}
function config543(array $x=[]): array
{
    return array_replace([
        'profile'=>profile543(),'secret_reference'=>secret543(),'private_key'=>key543(),
        'identity'=>identity543(),'username'=>USER_543,
    ],$x);
}
function grant543(): CapabilityGrant
{
    return CapabilityGrant::issue([
        'version'=>1,'grant_id'=>'99999999-8888-4777-8666-555555555555',
        'capability'=>'ssh.readonly','project'=>'brvtal','environment'=>'production',
        'resource'=>'database:primary','operation'=>'ssh.readonly','issue'=>'pl0n3r/brvtal#681',
        'run_id'=>'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff','subject'=>'hostinger-executor',
        'issued_at'=>'2027-01-15T07:00:00Z','expires_at'=>'2027-01-15T08:00:00Z',
        'backup_receipt_id'=>null,'owner_approval_id'=>null,'idempotency_key'=>'idem:543:readonly',
        'revoked_at'=>null,
    ]);
}
function request543(): array
{
    return [
        'project'=>'brvtal','environment'=>'production','resource'=>'database:primary',
        'issue'=>'pl0n3r/brvtal#681','run_id'=>'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        'subject'=>'hostinger-executor','idempotency_key'=>'idem:543:readonly','now'=>NOW_543,
    ];
}
function client543(int &$calls): OpenSshClient
{
    $calls=0;
    $runner=static function(array $argv,int $timeout) use (&$calls): array {
        $calls++;
        if (($argv[0]??null)==='ssh-keyscan') {
            $host=$argv[count($argv)-1];
            return ['exit_code'=>0,'stdout'=>$host.' ssh-ed25519 '.base64_encode(HOST_BLOB_543)."\n",
                'stderr'=>'','duration_ms'=>5,'timed_out'=>false];
        }
        return ['exit_code'=>0,'stdout'=>'','stderr'=>'','duration_ms'=>10,'timed_out'=>false];
    };
    return new OpenSshClient($runner,null,sys_get_temp_dir());
}
function build543(array $config): array
{
    $calls=0; $error=null; $ctx=null;
    try { $ctx=HostingerSshPrivateConfig::fromRecord($config,client543($calls)); }
    catch (Throwable $e) { $error=$e->getMessage(); }
    return ['context'=>$ctx,'runner_calls'=>$calls,'error'=>$error];
}

$name=$argv[1]??'';
if ($name==='valid') {
    $built=build543(config543());
    $first=$built['context']->execute(ProductionOperation::fromId('ssh.readonly'),grant543(),request543());
    $second=$built['context']->execute(ProductionOperation::fromId('ssh.readonly'),grant543(),request543());
    $out=['first'=>$first,'second'=>$second,'snapshot'=>$built['context']->safeSnapshot(),
        'runner_calls'=>$built['runner_calls'],'error'=>$built['error']];
} elseif ($name==='scope') {
    $cases=[
        'profile_project'=>config543(['profile'=>profile543(['project'=>'other'])]),
        'secret_project'=>config543(['secret_reference'=>secret543(['project'=>'other'])]),
        'secret_environment'=>config543(['secret_reference'=>secret543(['environment'=>'staging'])]),
        'secret_provider'=>config543(['secret_reference'=>secret543(['provider'=>'other'])]),
        'secret_ref'=>config543(['profile'=>profile543(['secret_ref'=>'22222222-3333-4444-8555-666666666666'])]),
        'identity_project'=>config543(['identity'=>identity543(['project'=>'other'])]),
        'identity_environment'=>config543(['identity'=>identity543(['environment'=>'staging'])]),
        'identity_provider'=>config543(['identity'=>identity543(['provider'=>'other'])]),
        'identity_ref'=>config543(['identity'=>identity543(['username_ref'=>'vault:user:other'])]),
    ];
    $out=[]; foreach($cases as $k=>$v) $out[$k]=build543($v);
} elseif ($name==='unsupported') {
    $out=[
        'capability'=>build543(config543(['secret_reference'=>secret543(['capability'=>'health.check'])])),
        'kind'=>build543(config543(['secret_reference'=>secret543(['secret_kind'=>'api_token'])])),
    ];
} elseif ($name==='invalid') {
    $extra=config543()+['token'=>'forbidden'];
    $missing=config543(); unset($missing['username']);
    $out=[
        'extra'=>build543($extra),'missing'=>build543($missing),
        'empty_key'=>build543(config543(['private_key'=>''])),
        'control_key'=>build543(config543(['private_key'=>"abcdefghijkl\x01mnop"])),
        'bad_username'=>build543(config543(['username'=>'bad user'])),
    ];
} elseif ($name==='surface') {
    $built=build543(config543());
    $result=$built['context']->execute(ProductionOperation::fromId('ssh.readonly'),grant543(),request543());
    $out=['snapshot'=>$built['context']->safeSnapshot(),'result'=>$result];
} else {
    fwrite(STDERR,"scenario inválido\n"); exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

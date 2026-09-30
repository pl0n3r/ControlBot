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
require __DIR__ . '/../src/HostingerSshRuntime.php';

use ControlBot\Production\CapabilityGrant;
use ControlBot\Production\ConnectionIdentityBroker;
use ControlBot\Production\ConnectionProfile;
use ControlBot\Production\HostingerSshRuntime;
use ControlBot\Production\ProductionOperation;
use ControlBot\Production\SecretReference;
use ControlBot\Production\SecretsBroker;
use InvalidArgumentException;

const NOW_539 = 1_799_997_000;
const SECRET_539 = 'fixture-private-key-material-539';
const USERNAME_539 = 'deploy_user_539';
const USER_REF_539 = 'vault:user:brvtal';
const SECRET_REF_539 = '11111111-2222-4333-8444-555555555555';

function secret539(
    string $referenceId = SECRET_REF_539,
    string $project = 'brvtal',
    string $environment = 'production',
): SecretReference {
    return SecretReference::fromRecord([
        'version'=>1,'reference_id'=>$referenceId,'capability'=>'ssh.readonly',
        'project'=>$project,'environment'=>$environment,'provider'=>'hostinger',
        'secret_kind'=>'private_key','generation'=>1,'issued_at'=>'2027-01-15T07:00:00Z',
        'revoked_at'=>null,
    ]);
}

function profile539(
    string $project = 'brvtal',
    string $environment = 'production',
    string $usernameRef = USER_REF_539,
    string $secretRef = SECRET_REF_539,
): ConnectionProfile {
    return ConnectionProfile::fromServerRecord([
        'version'=>1,'profile_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'project'=>$project,'environment'=>$environment,'provider'=>'hostinger','transport'=>'ssh',
        'host'=>'example.internal','port'=>22,'username_ref'=>$usernameRef,'secret_ref'=>$secretRef,
        'host_fingerprint'=>'SHA256:abcdefghijklmnop','status'=>'connected',
        'verified_at'=>'2027-01-15T07:00:00+00:00','last_health_at'=>'2027-01-15T07:00:00+00:00',
        'revoked_at'=>null,
    ]);
}

function identity539(
    string $usernameRef = USER_REF_539,
    string $project = 'brvtal',
    string $environment = 'production',
): array {
    return [
        'version'=>1,'username_ref'=>$usernameRef,'provider'=>'hostinger',
        'project'=>$project,'environment'=>$environment,'generation'=>3,
        'issued_at'=>'2027-01-15T07:00:00Z','revoked_at'=>null,
    ];
}

function grant539(
    string $operation = 'ssh.readonly',
    string $capability = 'ssh.readonly',
): CapabilityGrant {
    return CapabilityGrant::issue([
        'version'=>1,'grant_id'=>'99999999-8888-4777-8666-555555555555',
        'capability'=>$capability,'project'=>'brvtal','environment'=>'production',
        'resource'=>'database:primary','operation'=>$operation,'issue'=>'pl0n3r/brvtal#681',
        'run_id'=>'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff','subject'=>'hostinger-executor',
        'issued_at'=>'2027-01-15T07:00:00Z','expires_at'=>'2027-01-15T08:00:00Z',
        'backup_receipt_id'=>null,'owner_approval_id'=>null,
        'idempotency_key'=>'idem:brvtal-681-539','revoked_at'=>null,
    ]);
}

function request539(): array
{
    return [
        'project'=>'brvtal','environment'=>'production','resource'=>'database:primary',
        'issue'=>'pl0n3r/brvtal#681','run_id'=>'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
        'subject'=>'hostinger-executor','idempotency_key'=>'idem:brvtal-681-539','now'=>NOW_539,
    ];
}

function secrets539(SecretReference $reference): SecretsBroker
{
    $broker = new SecretsBroker(['hostinger-executor']);
    $broker->register($reference, SECRET_539);
    return $broker;
}

function identities539(?array $identity = null): ConnectionIdentityBroker
{
    $broker = new ConnectionIdentityBroker(['hostinger-executor']);
    if ($identity !== null) {
        $broker->register($identity, USERNAME_539);
    }
    return $broker;
}

function run539(
    ?CapabilityGrant $grant,
    ConnectionProfile $profile,
    SecretReference $reference,
    SecretsBroker $secrets,
    ConnectionIdentityBroker $identities,
    ?array $request = null,
): array {
    $calls=0; $observed=null; $secretSeen=false;
    $runtime = new HostingerSshRuntime(
        $secrets,
        $identities,
        static function(array $sshRequest, string $secret) use (&$calls,&$observed,&$secretSeen): array {
            $calls++; $observed=$sshRequest; $secretSeen=$secret===SECRET_539;
            return [
                'status'=>'success','code'=>'ssh_readonly_probe_ok',
                'summary'=>'connected '.USERNAME_539.' secret='.SECRET_539,
                'artifacts'=>[],'duration_ms'=>25,
            ];
        },
    );
    $result = $runtime->execute(
        ProductionOperation::fromId('ssh.readonly'),
        $grant,
        $profile,
        $reference,
        $request ?? request539(),
    );
    return ['result'=>$result,'client_calls'=>$calls,'request'=>$observed,'secret_seen'=>$secretSeen];
}

$name=$argv[1]??'';

if ($name==='valid') {
    $ref=secret539();
    $out=run539(grant539(),profile539(),$ref,secrets539($ref),identities539(identity539()));
} elseif ($name==='preclient') {
    $ref=secret539();
    $wrongRef=secret539('22222222-3333-4444-8555-666666666666');
    $out=[
        'missing_grant'=>run539(null,profile539(),$ref,secrets539($ref),identities539(identity539())),
        'wrong_grant'=>run539(
            grant539('migration.status','migration.status'),
            profile539(),$ref,secrets539($ref),identities539(identity539()),
        ),
        'profile_mismatch'=>run539(
            grant539(),profile539(project:'other'),$ref,secrets539($ref),identities539(identity539()),
        ),
        'secret_mismatch'=>run539(
            grant539(),profile539(),$wrongRef,secrets539($wrongRef),identities539(identity539()),
        ),
    ];
} elseif ($name==='identity') {
    $ref=secret539();
    $revoked=identities539(identity539());
    $revoked->revoke(USER_REF_539,'2027-01-15T07:30:00Z');
    $out=[
        'unknown'=>run539(grant539(),profile539(),$ref,secrets539($ref),identities539()),
        'revoked'=>run539(grant539(),profile539(),$ref,secrets539($ref),$revoked),
        'mismatch'=>run539(
            grant539(),profile539(),$ref,secrets539($ref),identities539(identity539(project:'other')),
        ),
    ];
} elseif ($name==='authority') {
    $ref=secret539();
    $valid=run539(grant539(),profile539(),$ref,secrets539($ref),identities539(identity539()));
    $override=request539();
    $override['host']='attacker.invalid';
    $rejected=false; $overrideCalls=0;
    $runtime=new HostingerSshRuntime(
        secrets539($ref),
        identities539(identity539()),
        static function() use (&$overrideCalls): array {
            $overrideCalls++;
            return ['status'=>'success','code'=>'unexpected','summary'=>'unexpected','artifacts'=>[],'duration_ms'=>1];
        },
    );
    try {
        $runtime->execute(ProductionOperation::fromId('ssh.readonly'),grant539(),profile539(),$ref,$override);
    } catch (InvalidArgumentException) {
        $rejected=true;
    }
    $out=[
        'valid'=>$valid,'override_rejected'=>$rejected,'override_client_calls'=>$overrideCalls,
    ];
} else {
    fwrite(STDERR,"scenario inválido\n");
    exit(2);
}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

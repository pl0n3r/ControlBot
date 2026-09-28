<?php
declare(strict_types=1);

require __DIR__ . '/../src/VentureIdentity.php';
require __DIR__ . '/../src/DecisionRights.php';
require __DIR__ . '/../src/IdentityCenter.php';

use ControlBot\Business\IdentityCenter;

const NOW = 2000;
const SCOPE = 'venture:grindflow';
const POLICY = 'controlbot:policy/business-os-v1';

function identity(string $id = 'identity-user', string $state = 'active'): array {
    return ['version'=>1,'identity_id'=>$id,'kind'=>'human','display_name'=>strtoupper($id),
        'state'=>$state,'source_ref'=>'controlbot:identity/'.$id,'observed_at'=>1900];
}
function grant(string $id, string $identityId, string $capability, string $authority = 'L1_OPERATOR', string $role = 'operator'): array {
    return ['version'=>1,'grant_id'=>$id,'identity_id'=>$identityId,'role'=>$role,'capability'=>$capability,
        'scope'=>SCOPE,'authority_level'=>$authority,'policy_ref'=>POLICY,'budget_limit'=>null,'granted_at'=>1800,'expires_at'=>3000];
}
function state(array $grants = []): array {
    return ['identity'=>identity(),'grants'=>$grants,'requests'=>[],'audit'=>[],'mfa'=>['required'=>false,'status'=>'unknown']];
}
function context(): array {
    return ['identity'=>identity('identity-admin'),'scope'=>SCOPE,'active_policy_refs'=>[POLICY]];
}
function authority(string $capability, string $level = 'L2_VENTURE_ADMIN'): array {
    return grant('grant-authority','identity-admin',$capability,$level,$level === 'L1_OPERATOR' ? 'operator' : 'venture_admin');
}
function command(string $id, string $operation, array $payload = [], ?int $expires = null): array {
    return ['version'=>1,'command_id'=>$id,'idempotency_key'=>'idem_'.$id,'operation'=>$operation,
        'actor_identity_id'=>'identity-admin','reason_code'=>'owner_request','scope'=>SCOPE,'expires_at'=>$expires,'payload'=>$payload];
}
function blocked(callable $fn): bool {
    try { $fn(); return false; } catch (Throwable) { return true; }
}

$name = $argv[1] ?? '';
if ($name === 'commands') {
    $samples = [
        command('invite01','invite',['identity_id'=>'identity-new','kind'=>'human','display_name'=>'New User','source_ref'=>'controlbot:identity/identity-new'],3000),
        command('create01','create',['identity'=>identity('identity-new')]),
        command('suspend01','suspend'), command('reactivate01','reactivate'),
        command('grant01','grant_scope',['grant'=>grant('grant-new','identity-user','venture.read')]),
        command('revoke01','revoke_scope',['grant_id'=>'grant-main']),
        command('role01','change_role',['grant_id'=>'grant-main','role'=>'viewer']),
        command('cap01','change_capability',['grant_id'=>'grant-main','capability'=>'venture.read']),
        command('reauth01','request_reauth',[],3000), command('reset01','request_reset',[],3000),
        command('mfa01','set_mfa_required',['required'=>true,'status'=>'required']),
    ];
    $out = array_map(static fn(array $row): array => IdentityCenter::normalizeCommand($row, NOW), $samples);
} elseif ($name === 'secrets') {
    $out = ['blocked'=>[],'leaked'=>false];
    foreach (['password','password_hash','token','session_cookie','otp','recovery_code','reset_token','two_factor_secret','master_credential'] as $field) {
        $secret = 'never-leak-'.$field;
        $out['blocked'][$field] = blocked(static fn() => IdentityCenter::normalizeCommand(
            command('secret01','suspend',[$field=>$secret]), NOW
        ));
        $out['leaked'] = $out['leaked'] || str_contains(json_encode($out, JSON_THROW_ON_ERROR), $secret);
    }
} elseif ($name === 'revoke') {
    $main = grant('grant-main','identity-user','venture.manage');
    $other = grant('grant-other','identity-user','venture.read');
    $out = IdentityCenter::execute(state([$main,$other]), command('revoke01','revoke_scope',['grant_id'=>'grant-main']),
        context(), authority('identity.access.manage'), NOW);
} elseif ($name === 'role-change') {
    $target = grant('grant-main','identity-user','venture.manage','L1_OPERATOR','operator');
    $cmd = command('role01','change_role',['grant_id'=>'grant-main','role'=>'viewer']);
    $denied = IdentityCenter::execute(state([$target]), $cmd, context(), authority('identity.access.manage','L1_OPERATOR'), NOW);
    $allowed = IdentityCenter::execute(state([$target]), $cmd, context(), authority('identity.access.manage'), NOW);
    $out = ['denied'=>$denied,'allowed'=>$allowed];
} elseif ($name === 'requests') {
    $reset = IdentityCenter::execute(state(), command('reset01','request_reset',[],3000), context(), authority('identity.auth.request','L1_OPERATOR'), NOW);
    $reauth = IdentityCenter::execute(state(), command('reauth01','request_reauth',[],3000), context(), authority('identity.auth.request','L1_OPERATOR'), NOW);
    $out = ['reset'=>$reset,'reauth'=>$reauth,'serialized'=>json_encode([$reset,$reauth],JSON_THROW_ON_ERROR)];
} elseif ($name === 'mfa') {
    $out = IdentityCenter::execute(state(), command('mfa01','set_mfa_required',['required'=>true,'status'=>'required']),
        context(), authority('identity.security.manage'), NOW);
} elseif ($name === 'deterministic') {
    $cmd = command('suspend01','suspend');
    $first = IdentityCenter::execute(state(), $cmd, context(), authority('identity.lifecycle'), NOW);
    $second = IdentityCenter::execute(state(), $cmd, context(), authority('identity.lifecycle'), NOW);
    $replay = IdentityCenter::execute($first['state'], $cmd, context(), authority('identity.lifecycle'), NOW);
    $out = ['first'=>$first,'second'=>$second,'same'=>$first===$second,'replay'=>$replay,
        'serialized'=>json_encode([$first,$second,$replay],JSON_THROW_ON_ERROR)];
} else {
    fwrite(STDERR, "scenario inválido\n"); exit(2);
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

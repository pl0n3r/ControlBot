<?php
declare(strict_types=1);
require __DIR__.'/../src/VentureIdentity.php'; require __DIR__.'/../src/DecisionRights.php'; require __DIR__.'/../src/IdentityCenter.php';
use ControlBot\Business\IdentityCenter;
const NOW=2000,SCOPE='venture:grindflow',POLICY='controlbot:policy/business-os-v1';
function identity(string $id='identity-user',string $state='active'):array{return ['version'=>1,'identity_id'=>$id,'kind'=>'human','display_name'=>strtoupper($id),'state'=>$state,'source_ref'=>'controlbot:identity/'.$id,'observed_at'=>1900];}
function grant(string $id,string $who,string $cap,string $auth='L1_OPERATOR',string $role='operator'):array{return ['version'=>1,'grant_id'=>$id,'identity_id'=>$who,'role'=>$role,'capability'=>$cap,'scope'=>SCOPE,'authority_level'=>$auth,'policy_ref'=>POLICY,'budget_limit'=>null,'granted_at'=>1800,'expires_at'=>3000];}
function state(array $grants=[]):array{return ['identity'=>identity(),'grants'=>$grants,'requests'=>[],'audit'=>[],'mfa'=>['required'=>false,'status'=>'unknown']];}
function ctx():array{return ['identity'=>identity('identity-admin'),'scope'=>SCOPE,'active_policy_refs'=>[POLICY]];}
function authority(string $cap,string $auth='L2_VENTURE_ADMIN'):array{return grant('grant-authority','identity-admin',$cap,$auth,$auth==='L1_OPERATOR'?'operator':'venture_admin');}
function cmd(string $id,string $op,array $payload=[],?int $expires=null):array{return ['version'=>1,'command_id'=>$id,'idempotency_key'=>'idem_'.$id,'operation'=>$op,'actor_identity_id'=>'identity-admin','reason_code'=>'owner_request','scope'=>SCOPE,'expires_at'=>$expires,'payload'=>$payload];}
function blocked(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}}
$n=$argv[1]??'';
if($n==='commands'){
 $rows=[cmd('invite01','invite',['identity_id'=>'identity-new','kind'=>'human','display_name'=>'New User','source_ref'=>'controlbot:identity/identity-new'],3000),cmd('create01','create',['identity'=>identity('identity-new')]),cmd('suspend01','suspend'),cmd('reactivate01','reactivate'),cmd('grant01','grant_scope',['grant'=>grant('grant-new','identity-user','venture.read')]),cmd('revoke01','revoke_scope',['grant_id'=>'grant-main']),cmd('role01','change_role',['grant_id'=>'grant-main','role'=>'viewer']),cmd('cap01','change_capability',['grant_id'=>'grant-main','capability'=>'venture.read']),cmd('reauth01','request_reauth',[],3000),cmd('reset01','request_reset',[],3000),cmd('mfa01','set_mfa_required',['required'=>true,'status'=>'required'])];
 $out=array_map(static fn($r)=>IdentityCenter::normalizeCommand($r,NOW),$rows);
}elseif($n==='secrets'){
 $out=['blocked'=>[]];foreach(['password','password_hash','token','session_cookie','otp','recovery_code','reset_token','two_factor_secret','master_credential'] as $f)$out['blocked'][$f]=blocked(static fn()=>IdentityCenter::normalizeCommand(cmd('secret01','suspend',[$f=>'never-leak']),NOW));
}elseif($n==='revoke'){
 $out=IdentityCenter::execute(state([grant('grant-main','identity-user','venture.manage'),grant('grant-other','identity-user','venture.read')]),cmd('revoke01','revoke_scope',['grant_id'=>'grant-main']),ctx(),authority('identity.access.manage'),NOW);
}elseif($n==='role-change'){
 $g=grant('grant-main','identity-user','venture.manage');$c=cmd('role01','change_role',['grant_id'=>'grant-main','role'=>'viewer']);
 $denied=IdentityCenter::execute(state([$g]),$c,ctx(),authority('identity.access.manage','L1_OPERATOR'),NOW);$allowed=IdentityCenter::execute(state([$g]),$c,ctx(),authority('identity.access.manage'),NOW);
 $cap=cmd('cap02','change_capability',['grant_id'=>'grant-main','capability'=>'identity.security.manage']);$capability=IdentityCenter::execute(state([$g]),$cap,ctx(),authority('identity.access.manage'),NOW);
 $out=['denied'=>$denied,'allowed'=>$allowed,'capability'=>$capability];
}elseif($n==='requests'){
 $a=IdentityCenter::execute(state(),cmd('reset01','request_reset',[],3000),ctx(),authority('identity.auth.request','L1_OPERATOR'),NOW);$b=IdentityCenter::execute(state(),cmd('reauth01','request_reauth',[],3000),ctx(),authority('identity.auth.request','L1_OPERATOR'),NOW);$out=['reset'=>$a,'reauth'=>$b,'serialized'=>json_encode([$a,$b],JSON_THROW_ON_ERROR)];
}elseif($n==='mfa'){
 $out=IdentityCenter::execute(state(),cmd('mfa01','set_mfa_required',['required'=>true,'status'=>'required']),ctx(),authority('identity.security.manage'),NOW);
}elseif($n==='deterministic'){
 $c=cmd('suspend01','suspend');$a=IdentityCenter::execute(state(),$c,ctx(),authority('identity.lifecycle'),NOW);$b=IdentityCenter::execute(state(),$c,ctx(),authority('identity.lifecycle'),NOW);$r=IdentityCenter::execute($a['state'],$c,ctx(),authority('identity.lifecycle'),NOW);
 $denyCmd=cmd('role-denied','change_role',['grant_id'=>'grant-main','role'=>'viewer']);$target=grant('grant-main','identity-user','venture.manage');$denied=IdentityCenter::execute(state([$target]),$denyCmd,ctx(),authority('identity.access.manage','L1_OPERATOR'),NOW);$deniedReplay=IdentityCenter::execute($denied['state'],$denyCmd,ctx(),authority('identity.access.manage'),NOW);
 $expired=[...grant('grant-expired','identity-user','venture.read'),'expires_at'=>1900];$expiredSuspend=IdentityCenter::execute(state([$expired]),cmd('suspend-expired','suspend'),ctx(),authority('identity.lifecycle'),NOW);$expiredRevoke=IdentityCenter::execute(state([$expired]),cmd('revoke-expired','revoke_scope',['grant_id'=>'grant-expired']),ctx(),authority('identity.access.manage'),NOW);
 $full=$a['state'];$full['audit']=array_fill(0,500,$a['audit_event']);$auditFullBlocked=blocked(static fn()=>IdentityCenter::execute($full,cmd('suspend-full','suspend'),ctx(),authority('identity.lifecycle'),NOW));
 $out=['first'=>$a,'second'=>$b,'same'=>$a===$b,'replay'=>$r,'denied_replay'=>$deniedReplay,'expired_suspend'=>$expiredSuspend,'expired_revoke'=>$expiredRevoke,'audit_full_blocked'=>$auditFullBlocked,'actor_scope_blocked'=>blocked(static fn()=>IdentityCenter::execute(state(),[...$c,'actor_identity_id'=>'identity-other'],ctx(),authority('identity.lifecycle'),NOW)),'serialized'=>json_encode([$a,$b,$r,$deniedReplay,$expiredSuspend,$expiredRevoke],JSON_THROW_ON_ERROR)];
}else{fwrite(STDERR,"scenario inválido\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

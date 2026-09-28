<?php
declare(strict_types=1);
require __DIR__.'/../src/VentureIdentity.php';
require __DIR__.'/../src/DecisionRights.php';
require __DIR__.'/../src/IdentityCenter.php';
require __DIR__.'/../src/VentureAccessRuntime.php';
require __DIR__.'/../src/Approvals.php';

use ControlBot\Approvals\HumanGate;
use ControlBot\Business\VentureAccessRuntime;

const NOW=3000, SCOPE='venture:alpha', POLICY='controlbot:policy/business-os-v1';
function ident(string $id='identity-admin'):array{return ['version'=>1,'identity_id'=>$id,'kind'=>'human','display_name'=>strtoupper($id),'state'=>'active','source_ref'=>'controlbot:identity/'.$id,'observed_at'=>2900];}
function grant(string $id,string $who,string $cap,string $scope=SCOPE,string $auth='L2_VENTURE_ADMIN'):array{return ['version'=>1,'grant_id'=>$id,'identity_id'=>$who,'role'=>$auth==='L1_OPERATOR'?'operator':'venture_admin','capability'=>$cap,'scope'=>$scope,'authority_level'=>$auth,'policy_ref'=>POLICY,'budget_limit'=>null,'granted_at'=>2800,'expires_at'=>4000];}
function state(array $grants=[]):array{return ['identity'=>ident('identity-user'),'grants'=>$grants,'requests'=>[],'audit'=>[],'mfa'=>['required'=>false,'status'=>'unknown']];}
function guard(string $decision='allow',string $reason='eligible'):array{return ['decision'=>$decision,'reason_code'=>$reason];}
function ctx(string $cap,string $auth='L2_VENTURE_ADMIN',array $budget=[],array $production=[]):array{return ['identity'=>ident(),'scope'=>SCOPE,'active_policy_refs'=>[POLICY],'grant'=>grant('grant-authority','identity-admin',$cap,SCOPE,$auth),'budget_guard'=>$budget?:guard(),'production_authority'=>$production?:guard()];}
function req(string $id,string $op,array $payload=[],?int $expires=null):array{return ['version'=>1,'command_id'=>$id,'idempotency_key'=>'idem_'.$id,'operation'=>$op,'reason_code'=>'staff_request','expires_at'=>$expires,'payload'=>$payload];}
function blocked(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}}
function gate(string $body):array{$g=HumanGate::fromIssueBody($body);return ['category'=>$g->category,'safe_default'=>$g->safeDefault,'options'=>$g->options];}
$n=$argv[1]??'';
if($n==='scope'){
 $foreign=grant('grant-foreign','identity-user','venture.manage','venture:beta');
 $out=VentureAccessRuntime::execute(state([$foreign]),req('revoke01','revoke_scope',['grant_id'=>'grant-foreign']),ctx('identity.access.manage'),NOW);
}elseif($n==='owner'){
 $target=grant('grant-main','identity-user','venture.manage');
 $out=VentureAccessRuntime::execute(state([$target]),req('cap01','change_capability',['grant_id'=>'grant-main','capability'=>'venture.read']),ctx('identity.access.manage'),NOW);
 $out['parsed_gate']=gate($out['owner_decision_gate']);
}elseif($n==='restrictions'){
 $base=state();$request=req('suspend01','suspend');
 $budget=VentureAccessRuntime::execute($base,$request,ctx('identity.lifecycle','L2_VENTURE_ADMIN',guard('deny','budget_blocked')),NOW);
 $production=VentureAccessRuntime::execute($base,$request,ctx('identity.lifecycle','L2_VENTURE_ADMIN',[],guard('owner_decision_required','production_override_required')),NOW);
 $out=[
   'budget'=>$budget,
   'budget_replay'=>VentureAccessRuntime::execute($budget['state'],$request,ctx('identity.lifecycle'),NOW+1),
   'production'=>$production,
   'production_replay'=>VentureAccessRuntime::execute($production['state'],$request,ctx('identity.lifecycle'),NOW+1),
   'allow'=>VentureAccessRuntime::execute($base,$request,ctx('identity.lifecycle'),NOW),
 ];
}elseif($n==='replay'){
 $request=req('suspend-replay','suspend');
 $first=VentureAccessRuntime::execute(state(),$request,ctx('identity.lifecycle'),NOW);
 $out=['first'=>$first,'replay'=>VentureAccessRuntime::execute($first['state'],$request,ctx('identity.lifecycle'),NOW+1)];
}elseif($n==='trusted'){
 $r=req('suspend01','suspend');
 $out=['actor'=>blocked(static fn()=>VentureAccessRuntime::execute(state(),[...$r,'actor_identity_id'=>'identity-other'],ctx('identity.lifecycle'),NOW)),
       'scope'=>blocked(static fn()=>VentureAccessRuntime::execute(state(),[...$r,'scope'=>'venture:beta'],ctx('identity.lifecycle'),NOW))];
}elseif($n==='audit'){
 $g=grant('grant-main','identity-user','venture.manage');
 $grantPayload=grant('grant-new','identity-user','venture.read');
 $out=[
  'grant'=>VentureAccessRuntime::execute(state(),req('grant01','grant_scope',['grant'=>$grantPayload]),ctx('identity.access.manage'),NOW)['audit'],
  'revoke'=>VentureAccessRuntime::execute(state([$g]),req('revoke01','revoke_scope',['grant_id'=>'grant-main']),ctx('identity.access.manage'),NOW)['audit'],
  'override'=>VentureAccessRuntime::execute(state(),req('suspend02','suspend'),ctx('identity.lifecycle','L2_VENTURE_ADMIN',[],guard('owner_decision_required','production_override_required')),NOW)['audit'],
  'deny'=>VentureAccessRuntime::execute(state([grant('foreign','identity-user','venture.read','venture:beta')]),req('revoke02','revoke_scope',['grant_id'=>'foreign']),ctx('identity.access.manage'),NOW)['audit'],
 ];
}elseif($n==='secrets'){
 $out=['blocked'=>[]]; foreach(['password','password_hash','token','cookie','otp','recovery_code','two_factor_secret'] as $key)
   $out['blocked'][$key]=blocked(static fn()=>VentureAccessRuntime::execute(state(),req('secret01','suspend',[$key=>'never']),ctx('identity.lifecycle'),NOW));
}elseif($n==='e2e'){
 $foreign=VentureAccessRuntime::execute(state([grant('foreign','identity-user','venture.read','venture:beta')]),req('revoke03','revoke_scope',['grant_id'=>'foreign']),ctx('identity.access.manage'),NOW);
 $target=grant('grant-main','identity-user','venture.manage');
 $owner=VentureAccessRuntime::execute(state([$target]),req('cap02','change_capability',['grant_id'=>'grant-main','capability'=>'venture.read']),ctx('identity.access.manage'),NOW);
 $out=['foreign'=>$foreign,'owner'=>$owner,'gate'=>gate($owner['owner_decision_gate'])];
}else{fwrite(STDERR,"scenario inválido\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

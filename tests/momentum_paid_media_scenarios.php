<?php
declare(strict_types=1);
foreach([
 'Approvals','OwnerSession','GitHub','ApprovalEndpoint','BudgetGuard','VentureIdentity','VentureAccessSource',
 'DecisionRights','DecisionRuntime','VerifiedAccessContext','IdentityCenter','CapitalPolicy','MomentumCampaign','MomentumPaidMedia'
] as $f) require __DIR__.'/../src/'.$f.'.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\Momentum\MomentumPaidMedia;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const NOW=2000;
const POLICY='controlbot:policy/business-os-v1';
const CAPABILITY='momentum.paid_media.plan';

final class PaidMediaSource implements VentureAccessSource {
 public function __construct(private array $row){}
 public function resolve(string $identityId,string $scope,string $capability,int $now):array{return $this->row;}
}
function brand():array{return [
 'version'=>1,'brand_context_id'=>'brand:11111111111111111111111111111111','venture_id'=>'venture-condor',
 'tone_ref'=>'tone:22222222222222222222222222222222','constraints'=>['brand-safe'],
 'source_ref'=>'source:33333333333333333333333333333333','observed_at'=>1900];}
function campaign():array{return [
 'version'=>1,'campaign_id'=>'campaign:44444444444444444444444444444444','venture_id'=>'venture-condor',
 'brand_context_id'=>'brand:11111111111111111111111111111111','objective'=>'acquisition',
 'audience_ref'=>'audience:55555555555555555555555555555555','offer_ref'=>'offer:66666666666666666666666666666666',
 'cta_ref'=>'cta:77777777777777777777777777777777','channels'=>['paid_social'],'creative_variant_refs'=>[],
 'budget_ref'=>'budget:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','schedule'=>['start_at'=>2100,'end_at'=>3100],
 'experiment_refs'=>[],'status'=>'planned','evidence_refs'=>[],'execution'=>false];}
function identity(string $state='active'):array{return [
 'version'=>1,'identity_id'=>'identity-growth','kind'=>'human','display_name'=>'Growth Admin','state'=>$state,
 'source_ref'=>'controlbot:identity/identity-growth','observed_at'=>1900];}
function grant(string $scope='venture:condor',string $cap=CAPABILITY,string $authority='L2_VENTURE_ADMIN',
 string $policy=POLICY):array{return [
 'version'=>1,'grant_id'=>'grant-growth','identity_id'=>'identity-growth',
 'role'=>$authority==='L1_OPERATOR'?'operator':'venture_admin','capability'=>$cap,'scope'=>$scope,
 'authority_level'=>$authority,'policy_ref'=>$policy,'budget_limit'=>10000000.0,'granted_at'=>1800,'expires_at'=>3000];}
function verified(string $scope='venture:condor',string $cap=CAPABILITY,string $authority='L2_VENTURE_ADMIN',
 string $grantPolicy=POLICY,?array $policies=null):VerifiedAccessContext{
 $source=new PaidMediaSource(['identity'=>identity(),'scope'=>$scope,
  'active_policy_refs'=>$policies??[$grantPolicy],'grant'=>grant($scope,$cap,$authority,$grantPolicy)]);
 $vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
 $sessions=new OwnerSessionService('pl0n3r',$vault);$session=[];
 $sessions->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
 $path=tempnam(sys_get_temp_dir(),'paid-');$audit=new AppendOnlyAuditLog($path);
 try{$runtime=DecisionRuntime::fromServer($sessions,$audit,['pl0n3r/controlbot'],$source);
  return VerifiedAccessContext::fromDecisionRuntime($runtime,$session,'pl0n3r/controlbot',
   ['identity_id'=>'identity-growth','scope'=>$scope,'capability'=>$cap],NOW);
 }finally{@unlink($path);}
}
function rights():array{return ['identity'=>identity(),'scope'=>'venture:condor','active_policy_refs'=>[POLICY]];}
function budgetGuard():array{return [
 'account'=>['provider'=>'github_actions','account_scope'=>'pl0n3r','resource'=>'private_minutes','included'=>100,'used'=>10,
 'billable_used'=>0,'reset_at'=>'2026-10-01','budget_limit'=>0,'budget_mode'=>'alert_only','capacity'=>'available',
 'observed_at'=>1900,'source'=>'github_api'],'projects'=>[],
 'work'=>['critical'=>false,'requires_private_runner'=>false,'pr_open'=>false]];}
function capital():array{return [
 'version'=>1,'scope'=>'venture:condor','currency'=>'COP',
 'budget'=>['scope'=>'venture:condor','currency'=>'COP','state'=>'known','limit_minor'=>10000000,'spent_minor'=>1000000,
  'committed_minor'=>1000000,'source_ref'=>'controlbot:finance/budget-condor','observed_at'=>1900],
 'reserve'=>['scope'=>'venture:condor','currency'=>'COP','state'=>'known','cash_available_minor'=>50000000,
  'minimum_reserve_minor'=>20000000,'runway_months'=>6,'source_ref'=>'controlbot:finance/reserve-condor','observed_at'=>1900],
 'proposal'=>['proposal_id'=>'proposal-paid-media','scope'=>'venture:condor','currency'=>'COP','amount_minor'=>2000000,
  'required_authority_level'=>'L2_VENTURE_ADMIN','irreversible'=>false,'shared_costs'=>[]],
 'budget_guard_input'=>budgetGuard(),'decision_rights_input'=>['context'=>rights(),'grant'=>[
  'version'=>1,'grant_id'=>'grant-finance','identity_id'=>'identity-growth','role'=>'venture_admin',
  'capability'=>'capital.propose','scope'=>'venture:condor','authority_level'=>'L2_VENTURE_ADMIN','policy_ref'=>POLICY,
  'budget_limit'=>10000000.0,'granted_at'=>1800,'expires_at'=>3000],
  'action'=>['capability'=>'capital.propose','required_authority_level'=>'L2_VENTURE_ADMIN','budget_amount'=>2000000.0]]];}
function plan(string $operation='launch',string $fresh='current',int $observed=1950,?array $reallocation=null):array{
 if($operation==='reallocate'&&$reallocation===null)
  $reallocation=['currency'=>'COP','current_total_minor'=>5000000,'proposed_total_minor'=>5000000,'limit_minor'=>6000000];
 return ['version'=>1,'campaign'=>campaign(),'channel'=>'paid_social','operation'=>$operation,'spend'=>[
  'spend_ref'=>'spend:cccccccccccccccccccccccccccccccc','amount_minor'=>2000000,'currency'=>'COP',
  'budget_ref'=>'budget:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  'evidence_refs'=>['evidence:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','evidence:dddddddddddddddddddddddddddddddd'],
  'blast_radius_ref'=>'blast-radius:ffffffffffffffffffffffffffffffff'],'secret_scope_ref'=>'scope:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
  'freshness'=>$fresh,'observed_at'=>$observed,'reallocation'=>$reallocation,'execution'=>false];}
function run(array $p,mixed $ctx=null,?array $cap=null):array{
 return MomentumPaidMedia::plan($p,brand(),$ctx??verified(),$cap??capital(),NOW);
}

$case=$argv[1]??'';
if($case==='nominal'){
 $ctx=verified();$copy=['context'=>$ctx->decisionContext(),'grant'=>$ctx->grant()];
 echo json_encode(['ok'=>run(plan(),$ctx),'array'=>run(plan(),$copy),'object'=>run(plan(),(object)$copy)],JSON_THROW_ON_ERROR);
}elseif($case==='authority'){
 $downgrade=[...plan(),'required_authority_level'=>'L0_AI_AUTONOMOUS'];
 echo json_encode(['caller'=>run($downgrade),'launch_l1'=>run(plan(),verified(authority:'L1_OPERATOR')),
  'pause_l1'=>run(plan('pause'),verified(authority:'L1_OPERATOR')),
  'reallocate_l1'=>run(plan('reallocate'),verified(authority:'L1_OPERATOR'))],JSON_THROW_ON_ERROR);
}elseif($case==='mismatch'){
 $other='controlbot:policy/other-v1';
 echo json_encode(['scope'=>run(plan(),verified(scope:'venture:brvtal')),
  'capability'=>run(plan(),verified(cap:'momentum.other.plan')),
  'policy'=>run(plan(),verified(grantPolicy:$other,policies:[POLICY,$other])),
  'owner'=>run(plan(),verified(authority:'L1_OPERATOR')),'invalid'=>run(plan(),new stdClass())],JSON_THROW_ON_ERROR);
}elseif($case==='freshness'){
 echo json_encode(['current'=>run(plan(observed:NOW-300)),'future'=>run(plan(observed:NOW+1)),
  'expired'=>run(plan(observed:NOW-301)),'stale'=>run(plan(fresh:'stale')),'unknown'=>run(plan(fresh:'unknown'))],JSON_THROW_ON_ERROR);
}elseif($case==='evidence'){
 $bad=plan();$bad['spend']['evidence_refs']=[$bad['spend']['evidence_refs'][0],$bad['spend']['evidence_refs'][0]];
 $secret=plan();$secret['spend']['spend_ref']='spend:token-super-secret';
 $budget=plan();$budget['spend']['budget_ref']='budget:ffffffffffffffffffffffffffffffff';
 $wrongCapital=capital();$wrongCapital['proposal']['amount_minor']=1900000;
 $blast=plan();$blast['spend']['blast_radius_ref']='blast-radius:99999999999999999999999999999999';
 $more=['currency'=>'COP','current_total_minor'=>5000000,'proposed_total_minor'=>5500000,'limit_minor'=>6000000];
 echo json_encode(['a'=>run(plan()),'b'=>run(plan()),'duplicate'=>run($bad),'secret'=>run($secret),
  'budget_mismatch'=>run($budget),'capital_mismatch'=>run(plan(),null,$wrongCapital),'blast'=>run($blast),
  'expand'=>run(plan('reallocate',reallocation:$more))],JSON_THROW_ON_ERROR);
}elseif($case==='pure'){
 $r=new ReflectionClass(MomentumPaidMedia::class);
 echo json_encode(['methods'=>array_map(fn($m)=>$m->getName(),$r->getMethods(ReflectionMethod::IS_PUBLIC)),'plan'=>run(plan())],JSON_THROW_ON_ERROR);
}else{fwrite(STDERR,"Unknown paid-media scenario\n");exit(2);}
echo PHP_EOL;

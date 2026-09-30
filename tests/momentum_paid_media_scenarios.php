<?php
declare(strict_types=1);
require __DIR__.'/../src/MomentumCampaign.php';
require __DIR__.'/../src/BudgetGuard.php';
require __DIR__.'/../src/VentureIdentity.php';
require __DIR__.'/../src/DecisionRights.php';
require __DIR__.'/../src/CapitalPolicy.php';
require __DIR__.'/../src/MomentumPaidMedia.php';

use ControlBot\Momentum\MomentumPaidMedia;

const NOW=2000;
const POLICY='controlbot:policy/business-os-v1';

function brand(): array { return [
 'version'=>1,'brand_context_id'=>'brand:11111111111111111111111111111111','venture_id'=>'venture-condor',
 'tone_ref'=>'tone:22222222222222222222222222222222','constraints'=>['brand-safe'],
 'source_ref'=>'source:33333333333333333333333333333333','observed_at'=>1900,
];}
function campaign(): array { return [
 'version'=>1,'campaign_id'=>'campaign:44444444444444444444444444444444','venture_id'=>'venture-condor',
 'brand_context_id'=>'brand:11111111111111111111111111111111','objective'=>'acquisition',
 'audience_ref'=>'audience:55555555555555555555555555555555','offer_ref'=>'offer:66666666666666666666666666666666',
 'cta_ref'=>'cta:77777777777777777777777777777777','channels'=>['paid_social'],'creative_variant_refs'=>[],
 'budget_ref'=>'budget:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','schedule'=>['start_at'=>2100,'end_at'=>3100],
 'experiment_refs'=>[],'status'=>'planned','evidence_refs'=>[],'execution'=>false,
];}
function identity(): array { return [
 'version'=>1,'identity_id'=>'identity-growth','kind'=>'human','display_name'=>'Growth Admin','state'=>'active',
 'source_ref'=>'controlbot:identity/identity-growth','observed_at'=>1900,
];}
function rights(string $scope='venture:condor'): array { return [
 'identity'=>identity(),'scope'=>$scope,'active_policy_refs'=>[POLICY],
];}
function grant(string $cap='momentum.paid_media.plan',string $authority='L2_VENTURE_ADMIN'): array { return [
 'version'=>1,'grant_id'=>'grant-growth','identity_id'=>'identity-growth','role'=>$authority==='L1_OPERATOR'?'operator':'venture_admin',
 'capability'=>$cap,'scope'=>'venture:condor','authority_level'=>$authority,'policy_ref'=>POLICY,
 'budget_limit'=>10000000.0,'granted_at'=>1800,'expires_at'=>3000,
];}
function budgetGuard(): array { return [
 'account'=>['provider'=>'github_actions','account_scope'=>'pl0n3r','resource'=>'private_minutes','included'=>100,'used'=>10,
 'billable_used'=>0,'reset_at'=>'2026-10-01','budget_limit'=>0,'budget_mode'=>'alert_only','capacity'=>'available',
 'observed_at'=>1900,'source'=>'github_api'],
 'projects'=>[],'work'=>['critical'=>false,'requires_private_runner'=>false,'pr_open'=>false],
];}
function capital(): array { return [
 'version'=>1,'scope'=>'venture:condor','currency'=>'COP',
 'budget'=>['scope'=>'venture:condor','currency'=>'COP','state'=>'known','limit_minor'=>10000000,'spent_minor'=>1000000,
 'committed_minor'=>1000000,'source_ref'=>'controlbot:finance/budget-condor','observed_at'=>1900],
 'reserve'=>['scope'=>'venture:condor','currency'=>'COP','state'=>'known','cash_available_minor'=>50000000,
 'minimum_reserve_minor'=>20000000,'runway_months'=>6,'source_ref'=>'controlbot:finance/reserve-condor','observed_at'=>1900],
 'proposal'=>['proposal_id'=>'proposal-paid-media','scope'=>'venture:condor','currency'=>'COP','amount_minor'=>2000000,
 'required_authority_level'=>'L2_VENTURE_ADMIN','irreversible'=>false,'shared_costs'=>[]],
 'budget_guard_input'=>budgetGuard(),
 'decision_rights_input'=>['context'=>rights(),'grant'=>[
   'version'=>1,'grant_id'=>'grant-finance','identity_id'=>'identity-growth','role'=>'venture_admin','capability'=>'capital.propose',
   'scope'=>'venture:condor','authority_level'=>'L2_VENTURE_ADMIN','policy_ref'=>POLICY,'budget_limit'=>10000000.0,
   'granted_at'=>1800,'expires_at'=>3000],
  'action'=>['capability'=>'capital.propose','required_authority_level'=>'L2_VENTURE_ADMIN','budget_amount'=>2000000.0]],
];}
function plan(string $fresh='current',?array $reallocation=null): array { return [
 'version'=>1,'campaign'=>campaign(),'channel'=>'paid_social','capability'=>'momentum.paid_media.plan',
 'required_authority_level'=>'L2_VENTURE_ADMIN',
 'spend'=>['amount_minor'=>2000000,'currency'=>'COP','budget_ref'=>'budget:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
 'secret_scope_ref'=>'scope:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','freshness'=>$fresh,'observed_at'=>1950,
 'reallocation'=>$reallocation,'execution'=>false,
];}
function runPlan(array $p,?array $ctx=null,?array $g=null,?array $cap=null): array {
 return MomentumPaidMedia::plan($p,brand(),$ctx??rights(),$g??grant(),$cap??capital(),NOW);
}

$case=$argv[1]??'';
if($case==='scope'){
 $wrong=rights('venture:brvtal');
 echo json_encode(['ok'=>runPlan(plan()),'wrong_scope'=>runPlan(plan(),$wrong),'organic'=>runPlan([...plan(),'channel'=>'web'])],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='gates'){
 $badGrant=grant('momentum.other.plan');
 $badCapital=capital(); $badCapital['budget']['state']='unknown';
 echo json_encode(['authority'=>runPlan(plan(),null,$badGrant),'capital'=>runPlan(plan(),null,null,$badCapital)],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='closed'){
 $owner=runPlan(plan(),null,grant(authority:'L1_OPERATOR'));
 echo json_encode(['owner'=>$owner,'stale'=>runPlan(plan('stale')),'unknown'=>runPlan(plan('unknown'))],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='reallocation'){
 $same=['currency'=>'COP','current_total_minor'=>5000000,'proposed_total_minor'=>5000000,'limit_minor'=>6000000];
 $more=[...$same,'proposed_total_minor'=>5500000];
 echo json_encode(['same'=>runPlan(plan(reallocation:$same)),'more'=>runPlan(plan(reallocation:$more))],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='secrets'){
 $secret=[...plan(),'secret_scope_ref'=>'scope:token-super-secret'];
 $extra=[...plan(),'api_token'=>'abc'];
 echo json_encode(['opaque'=>runPlan(plan()),'secret'=>runPlan($secret),'extra'=>runPlan($extra)],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='pure'){
 $r=new ReflectionClass(MomentumPaidMedia::class);
 echo json_encode(['methods'=>array_map(fn($m)=>$m->getName(),$r->getMethods(ReflectionMethod::IS_PUBLIC)),'plan'=>runPlan(plan())],JSON_THROW_ON_ERROR),PHP_EOL;
}else{fwrite(STDERR,"Unknown paid-media scenario\n");exit(2);}

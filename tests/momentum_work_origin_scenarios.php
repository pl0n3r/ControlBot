<?php
declare(strict_types=1);
foreach([
 'Approvals','OwnerSession','GitHub','ApprovalEndpoint','BudgetGuard','VentureIdentity','VentureAccessSource',
 'DecisionRights','DecisionRuntime','VerifiedAccessContext','IdentityCenter','CapitalPolicy','MomentumCampaign',
 'MomentumCreative','MomentumEmail','MomentumExperiment','MomentumPaidMedia','MomentumRevenue','MomentumPerformance',
 'MomentumWorkOrigin'
] as $f) require_once __DIR__.'/../src/'.$f.'.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Business\VerifiedAccessContext;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\Momentum\MomentumCampaign;
use ControlBot\Momentum\MomentumCreative;
use ControlBot\Momentum\MomentumEmail;
use ControlBot\Momentum\MomentumExperiment;
use ControlBot\Momentum\MomentumPaidMedia;
use ControlBot\Momentum\MomentumPerformance;
use ControlBot\Momentum\MomentumWorkOrigin;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;

const NOW441=2000;
const POLICY441='controlbot:policy/business-os-v1';
function r441(string $n,string $c):string{return $n.':'.str_repeat($c,32);}
final class Access441 implements VentureAccessSource{
 public function __construct(private array $row){}
 public function resolve(string $identityId,string $scope,string $capability,int $now):array{return $this->row;}
}
function identity441():array{return ['version'=>1,'identity_id'=>'identity-growth','kind'=>'human','display_name'=>'Growth Admin',
 'state'=>'active','source_ref'=>'controlbot:identity/identity-growth','observed_at'=>1900];}
function grant441(string $cap='momentum.paid_media.plan'):array{return ['version'=>1,'grant_id'=>'grant-growth','identity_id'=>'identity-growth',
 'role'=>'venture_admin','capability'=>$cap,'scope'=>'venture:condor','authority_level'=>'L2_VENTURE_ADMIN',
 'policy_ref'=>POLICY441,'budget_limit'=>10000000.0,'granted_at'=>1800,'expires_at'=>3000];}
function verified441():VerifiedAccessContext{
 $src=new Access441(['identity'=>identity441(),'scope'=>'venture:condor','active_policy_refs'=>[POLICY441],'grant'=>grant441()]);
 $vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
 $sessions=new OwnerSessionService('pl0n3r',$vault);$session=[];$sessions->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-value');
 $path=tempnam(sys_get_temp_dir(),'m441-');$audit=new AppendOnlyAuditLog($path);
 try{$runtime=DecisionRuntime::fromServer($sessions,$audit,['pl0n3r/controlbot'],$src);
  return VerifiedAccessContext::fromDecisionRuntime($runtime,$session,'pl0n3r/controlbot',
   ['identity_id'=>'identity-growth','scope'=>'venture:condor','capability'=>'momentum.paid_media.plan'],NOW441);
 }finally{@unlink($path);}
}
function capital441():array{return [
 'version'=>1,'scope'=>'venture:condor','currency'=>'COP',
 'budget'=>['scope'=>'venture:condor','currency'=>'COP','state'=>'known','limit_minor'=>10000000,'spent_minor'=>1000000,
  'committed_minor'=>1000000,'source_ref'=>'controlbot:finance/budget-condor','observed_at'=>1900],
 'reserve'=>['scope'=>'venture:condor','currency'=>'COP','state'=>'known','cash_available_minor'=>50000000,
  'minimum_reserve_minor'=>20000000,'runway_months'=>6,'source_ref'=>'controlbot:finance/reserve-condor','observed_at'=>1900],
 'proposal'=>['proposal_id'=>'proposal-paid-media','scope'=>'venture:condor','currency'=>'COP','amount_minor'=>2000000,
  'required_authority_level'=>'L2_VENTURE_ADMIN','irreversible'=>false,'shared_costs'=>[]],
 'budget_guard_input'=>['account'=>['provider'=>'github_actions','account_scope'=>'pl0n3r','resource'=>'private_minutes',
  'included'=>100,'used'=>10,'billable_used'=>0,'reset_at'=>'2026-10-01','budget_limit'=>0,'budget_mode'=>'alert_only',
  'capacity'=>'available','observed_at'=>1900,'source'=>'github_api'],'projects'=>[],
  'work'=>['critical'=>false,'requires_private_runner'=>false,'pr_open'=>false]],
 'decision_rights_input'=>['context'=>['identity'=>identity441(),'scope'=>'venture:condor','active_policy_refs'=>[POLICY441]],
  'grant'=>grant441('capital.propose'),'action'=>['capability'=>'capital.propose',
   'required_authority_level'=>'L2_VENTURE_ADMIN','budget_amount'=>2000000.0]]];}
function raw441():array{return ['version'=>1,'group_id'=>'group:pl0n3r','venture_id'=>'venture-condor','project_id'=>'project:condor',
 'repository_ref'=>'pl0n3r/Condor','work_type'=>'marketing_growth','requested_capabilities'=>['campaign.optimization','growth.analysis'],
 'required_roles'=>['datos-analitica','marketing'],'priority_class'=>'high','depends_on'=>[r441('work','8')],
 'claims'=>['campaign/'.r441('campaign','4'),'venture/venture-condor'],'evidence_refs'=>[r441('evidence','b'),r441('evidence','c')],
 'freshness'=>'current','observed_at'=>'2026-09-30T03:40:00Z','idempotency_key'=>'momentum:condor:campaign-1:growth','execution'=>false];}
function canonical441(bool $drift=false):array{
 $brandRaw=['version'=>1,'brand_context_id'=>r441('brand','1'),'venture_id'=>'venture-condor','tone_ref'=>r441('tone','2'),
  'constraints'=>['brand-safe'],'source_ref'=>r441('source','3'),'observed_at'=>1900];
 $campaignRaw=['version'=>1,'campaign_id'=>r441('campaign','4'),'venture_id'=>'venture-condor','brand_context_id'=>r441('brand','1'),
  'objective'=>'acquisition','audience_ref'=>r441('audience','5'),'offer_ref'=>r441('offer','6'),'cta_ref'=>r441('cta','7'),
  'channels'=>['email','paid_social'],'creative_variant_refs'=>[r441('creative','a'),r441('creative','b'),r441('creative','c')],
  'budget_ref'=>r441('budget','d'),'schedule'=>['start_at'=>1100,'end_at'=>2200],'experiment_refs'=>[r441('experiment','e')],
  'status'=>'planned','evidence_refs'=>[r441('evidence','f')],'execution'=>false];
 if($drift)$campaignRaw['provider']='meta';
 $brand=MomentumCampaign::brandContext($brandRaw);$campaign=MomentumCampaign::campaign($campaignRaw,$brand);
 $brief=MomentumCreative::brief(['version'=>1,'brief_id'=>r441('brief','1'),'venture_id'=>'venture-condor',
  'brand_context_id'=>r441('brand','1'),'campaign_ref'=>r441('campaign','4'),'format'=>'static_image',
  'audience_ref'=>r441('audience','5'),'offer_ref'=>r441('offer','6'),'cta_ref'=>r441('cta','7'),
  'constraint_refs'=>[r441('constraint','9')]],$brand);
 $creative=MomentumCreative::variant(['version'=>1,'variant_id'=>r441('creative','a'),'brief_id'=>r441('brief','1'),
  'venture_id'=>'venture-condor','brand_context_id'=>r441('brand','1'),'variant_key'=>'A','format'=>'static_image',
  'content_ref'=>r441('content','c'),'asset_ref'=>r441('asset','d'),'hypothesis_ref'=>null,'metric_ref'=>r441('metric','e'),
  'provenance_refs'=>[r441('source','9')],'status'=>'approved'],$brief,$brand);
 $program=MomentumEmail::program(['version'=>1,'email_program_id'=>r441('email-program','8'),'venture_id'=>'venture-condor',
  'campaign_ref'=>r441('campaign','4'),'audience_ref'=>r441('audience','5'),'message_class'=>'marketing','execution'=>false],$campaign,$brand);
 $sig=fn(string $kind,string $status,string $hex)=>['state_ref'=>$kind.':'.str_repeat($hex,32),'status'=>$status,
  'source_ref'=>r441('source','d'),'observed_at'=>1900,'freshness'=>'current'];
 $email=MomentumEmail::audienceState(['version'=>1,'email_program_id'=>r441('email-program','8'),'venture_id'=>'venture-condor',
  'audience_ref'=>r441('audience','5'),'consent'=>$sig('consent','granted','9'),'suppression'=>$sig('suppression','clear','b'),
  'unsubscribe'=>$sig('unsubscribe','clear','c')],$program,$campaign,$brand);
 $experiment=MomentumExperiment::experiment(['version'=>1,'experiment_id'=>r441('experiment','e'),'venture_id'=>'venture-condor',
  'campaign_id'=>r441('campaign','4'),'hypothesis_ref'=>r441('hypothesis','1'),'baseline_ref'=>r441('creative','a'),
  'variant_refs'=>[r441('creative','b'),r441('creative','c')],'metric_ref'=>r441('metric','2'),
  'window'=>['start_at'=>1200,'end_at'=>2000],'status'=>'completed','result'=>['state'=>'observed',
   'winner_ref'=>r441('creative','b'),'effect_bps'=>750,'source_ref'=>r441('source','4'),'observed_at'=>1950,
   'freshness'=>'current','evidence_refs'=>[r441('evidence','5')]],'evidence_refs'=>[r441('evidence','3')],
  'execution'=>false],$campaign,$brand);
 $paid=MomentumPaidMedia::plan(['version'=>1,'campaign'=>$campaign,'channel'=>'paid_social','operation'=>'launch',
  'spend'=>['spend_ref'=>r441('spend','c'),'amount_minor'=>2000000,'currency'=>'COP','budget_ref'=>r441('budget','d'),
   'evidence_refs'=>[r441('evidence','d'),r441('evidence','e')],'blast_radius'=>'medium'],
  'secret_scope_ref'=>r441('scope','b'),'freshness'=>'current','observed_at'=>1950,'reallocation'=>null,'execution'=>false],
  $brand,verified441(),capital441(),NOW441);
 $pipeline=['version'=>1,'pipeline_id'=>r441('pipeline','1'),'venture_id'=>'venture-condor','lead_ref'=>r441('lead','2'),
  'opportunity_ref'=>r441('opportunity','3'),'source_ref'=>r441('source','4'),'campaign_ref'=>r441('campaign','4'),
  'creative_ref'=>r441('creative','a'),'owner_ref'=>r441('owner','7'),'stage'=>'won','qualification'=>'qualified',
  'next_action_ref'=>null,'observed_at'=>1900,'freshness'=>'current','customer_success_handoff_ref'=>r441('customer-success','9')];
 $attribution=['version'=>1,'attribution_id'=>r441('attribution','a'),'venture_id'=>'venture-condor',
  'opportunity_ref'=>r441('opportunity','3'),'campaign_ref'=>r441('campaign','4'),'creative_ref'=>r441('creative','a'),
  'classification'=>'observed','amount_minor'=>9000000,'currency'=>'COP','source_ref'=>r441('source','b'),
  'evidence_refs'=>[r441('evidence','c')],'observed_at'=>1960,'freshness'=>'current'];
 $perfRaw=['version'=>1,'performance_id'=>r441('performance','1'),'venture_id'=>'venture-condor','campaign_ref'=>r441('campaign','4'),
  'period'=>['start_at'=>1000,'end_at'=>2000],'currency'=>'COP',
  'spend'=>['classification'=>'observed','amount_minor'=>2500000,'currency'=>'COP','source_ref'=>r441('source','2'),
   'evidence_refs'=>[r441('evidence','2')],'observed_at'=>1960,'freshness'=>'current','governed_spend_ref'=>r441('spend','c')],
  'funnel'=>['classification'=>'observed','leads'=>100,'conversions'=>20,'source_ref'=>r441('source','3'),
   'evidence_refs'=>[r441('evidence','3')],'observed_at'=>1960,'freshness'=>'current'],
  'cost'=>['classification'=>'observed','amount_minor'=>1000000,'currency'=>'COP','source_ref'=>r441('source','4'),
   'evidence_refs'=>[r441('evidence','4')],'observed_at'=>1960,'freshness'=>'current'],
  'source_ref'=>r441('source','5'),'evidence_refs'=>[r441('evidence','5')],'observed_at'=>1960,'freshness'=>'current','execution'=>false];
 $performance=MomentumPerformance::project($perfRaw,$paid,$pipeline,$attribution);
 return ['raw'=>raw441(),'campaign'=>$campaign,'creative'=>$creative,'email'=>$email,'experiment'=>$experiment,'paid'=>$paid,'performance'=>$performance];
}
function bridge441(array $x,array $o=[]):array{return MomentumWorkOrigin::materialize($o['raw']??$x['raw'],$x['campaign'],$x['creative'],
 $o['email']??$x['email'],$o['experiment']??$x['experiment'],$o['paid']??$x['paid'],$o['performance']??$x['performance']);}
function blocked441(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}}

$case=$argv[1]??'';$x=$case==='drift'?null:canonical441();
if($case==='valid')echo json_encode(bridge441($x),JSON_THROW_ON_ERROR);
elseif($case==='gates'){
 $deny=$x['paid'];$deny['status']='denied';$deny['authority']['decision']='deny';$deny['reasons']=['authority_denied'];
 $budget=$x['paid'];$budget['status']='denied';$budget['capital']['decision']='deny';$budget['reasons']=['capital_denied'];
 $owner=$x['paid'];$owner['status']='owner_decision_required';$owner['authority']['decision']='owner_decision_required';$owner['reasons']=['authority_owner_gate'];
 $unknown=$x['paid'];$unknown['status']='denied';$unknown['authority']['decision']='unknown';$unknown['capital']['decision']='unknown';$unknown['reasons']=['authority_unknown'];
 $ps=$x['paid'];$ps['freshness']='stale';$fs=$x['performance'];$fs['freshness']='stale';$ru=$x['raw'];$ru['freshness']='unknown';
 $eu=$x['experiment'];$eu['result']['state']='unknown';$eu['result']['freshness']='unknown';$em=$x['email'];$em['marketing_eligible']=false;
 echo json_encode(['deny'=>bridge441($x,['paid'=>$deny]),'budget'=>bridge441($x,['paid'=>$budget]),'owner'=>bridge441($x,['paid'=>$owner]),
  'unknown'=>bridge441($x,['paid'=>$unknown]),'paid_stale'=>bridge441($x,['paid'=>$ps]),'performance_stale'=>bridge441($x,['performance'=>$fs]),
  'origin_unknown'=>bridge441($x,['raw'=>$ru]),'experiment_unknown'=>bridge441($x,['experiment'=>$eu]),'email_denied'=>bridge441($x,['email'=>$em])],JSON_THROW_ON_ERROR);
}elseif($case==='identity'){$raw=$x['raw'];$raw['claims']=array_reverse($raw['claims']);$raw['evidence_refs']=array_reverse($raw['evidence_refs']);
 echo json_encode(['a'=>bridge441($x),'b'=>bridge441($x,['raw'=>$raw])],JSON_THROW_ON_ERROR);
}elseif($case==='e2e')echo json_encode([...$x,'out'=>bridge441($x)],JSON_THROW_ON_ERROR);
elseif($case==='drift')echo json_encode(['upstream_rejected'=>blocked441(fn()=>canonical441(true))],JSON_THROW_ON_ERROR);
elseif($case==='unsafe'){$pii=$x['raw'];$pii['group_id']='person@example.com';$secret=$x['raw'];$secret['claims']=['Bearer abcdefghijklmnopqrstuvwxyz'];
 $extra=$x['raw'];$extra['provider']='meta';$up=$x['performance'];$up['source_ref']='person@example.com';
 echo json_encode(['pii'=>bridge441($x,['raw'=>$pii]),'secret'=>bridge441($x,['raw'=>$secret]),
  'extra'=>bridge441($x,['raw'=>$extra]),'upstream_pii'=>bridge441($x,['performance'=>$up])],JSON_THROW_ON_ERROR);
}else{fwrite(STDERR,"Unknown work-origin scenario\n");exit(2);}echo PHP_EOL;

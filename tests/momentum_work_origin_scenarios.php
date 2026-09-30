<?php
declare(strict_types=1);
require __DIR__.'/../src/MomentumWorkOrigin.php';
use ControlBot\Momentum\MomentumWorkOrigin;

function r(string $n,string $c):string{return $n.':'.str_repeat($c,32);}
function campaign429():array{return ['version'=>1,'campaign_id'=>r('campaign','1'),'venture_id'=>'venture-condor',
 'audience_ref'=>r('audience','2'),'creative_variant_refs'=>[r('creative','3')],'status'=>'planned','execution'=>false];}
function creative429():array{return ['version'=>1,'variant_id'=>r('creative','3'),'brief_id'=>r('brief','4'),
 'venture_id'=>'venture-condor','brand_context_id'=>r('brand','5'),'variant_key'=>'A','format'=>'static_image',
 'content_ref'=>r('content','6'),'asset_ref'=>null,'hypothesis_ref'=>r('hypothesis','7'),'metric_ref'=>r('metric','8'),
 'provenance_refs'=>[r('source','9')],'status'=>'approved'];}
function email429(bool $eligible=true):array{return ['version'=>1,'email_program_id'=>r('email-program','a'),'venture_id'=>'venture-condor',
 'audience_ref'=>r('audience','2'),'message_class'=>'marketing','consent'=>['source_ref'=>r('source','b')],
 'suppression'=>['source_ref'=>r('source','c')],'unsubscribe'=>['source_ref'=>r('source','d')],
 'marketing_eligible'=>$eligible,'execution'=>false];}
function experiment429(string $state='observed',string $fresh='current'):array{return [
 'version'=>1,'experiment_id'=>r('experiment','e'),'venture_id'=>'venture-condor','campaign_id'=>r('campaign','1'),
 'status'=>'completed','result'=>['state'=>$state,'freshness'=>$fresh,'evidence_refs'=>$state==='unknown'?[]:[r('evidence','e')]],
 'evidence_refs'=>[r('evidence','f')],'execution'=>false];}
function paid429(string $status='planned',string $fresh='current',string $authority='allow',string $capital='allow',array $reasons=['governance_satisfied']):array{return [
 'version'=>1,'campaign_id'=>r('campaign','1'),'venture_id'=>'venture-condor','freshness'=>$fresh,'observed_at'=>2000,
 'spend'=>['budget_ref'=>r('budget','1'),'evidence_refs'=>[r('evidence','1')]],
 'authority'=>['required_authority_level'=>'L2_VENTURE_ADMIN','decision'=>$authority,'reasons'=>[]],
 'capital'=>['decision'=>$capital,'reasons'=>[]],'reasons'=>$reasons,'status'=>$status,'execution'=>false];}
function performance429(string $fresh='current'):array{return ['version'=>1,'performance_id'=>r('performance','2'),
 'venture_id'=>'venture-condor','campaign_ref'=>r('campaign','1'),'source_ref'=>r('source','2'),
 'evidence_refs'=>[r('evidence','2')],'observed_at'=>2000,'freshness'=>$fresh,'execution'=>false];}
function raw429(string $fresh='current'):array{return [
 'version'=>1,'group_id'=>'group:pl0n3r','venture_id'=>'venture-condor','project_id'=>'project:condor',
 'repository_ref'=>'pl0n3r/Condor','work_type'=>'marketing_growth',
 'requested_capabilities'=>['campaign.optimization','growth.analysis'],'required_roles'=>['datos-analitica','marketing'],
 'priority_class'=>'high','depends_on'=>[r('work','8')],'claims'=>['campaign/'.r('campaign','1'),'venture/venture-condor'],
 'evidence_refs'=>[r('evidence','c'),r('evidence','b')],'freshness'=>$fresh,'observed_at'=>'2026-09-30T03:40:00Z',
 'idempotency_key'=>'momentum:condor:campaign-1:growth','execution'=>false];}
function go429(?array $raw=null,?array $paid=null,?array $perf=null,?array $email=null,?array $exp=null):array{
 return MomentumWorkOrigin::materialize($raw??raw429(),campaign429(),creative429(),$email??email429(),$exp??experiment429(),$paid??paid429(),$perf??performance429());}

$case=$argv[1]??'';
if($case==='valid') echo json_encode(go429(),JSON_THROW_ON_ERROR);
elseif($case==='gates') echo json_encode([
 'deny'=>go429(paid:paid429('denied','current','deny','allow',['authority_denied'])),
 'budget'=>go429(paid:paid429('denied','current','allow','deny',['capital_denied'])),
 'owner'=>go429(paid:paid429('owner_decision_required','current','owner_decision_required','allow',['authority_owner_gate'])),
 'unknown'=>go429(paid:paid429('denied','current','unknown','unknown',['authority_unknown'])),
 'paid_stale'=>go429(paid:paid429('planned','stale')),'performance_stale'=>go429(perf:performance429('stale')),
 'origin_unknown'=>go429(raw429('unknown')),'experiment_unknown'=>go429(exp:experiment429('unknown','unknown')),
 'email_denied'=>go429(email:email429(false))],JSON_THROW_ON_ERROR);
elseif($case==='identity'){$a=raw429();$a['claims']=array_reverse($a['claims']);$a['evidence_refs']=array_reverse($a['evidence_refs']);echo json_encode(['a'=>go429(),'b'=>go429($a)],JSON_THROW_ON_ERROR);}
elseif($case==='e2e') echo json_encode(['out'=>go429(),'campaign'=>campaign429(),'creative'=>creative429(),'email'=>email429(),
 'experiment'=>experiment429(),'paid'=>paid429(),'performance'=>performance429()],JSON_THROW_ON_ERROR);
elseif($case==='unsafe'){$pii=raw429();$pii['group_id']='person@example.com';$secret=raw429();$secret['claims']=['Bearer abcdefghijklmnopqrstuvwxyz'];$extra=raw429();$extra['provider']='meta';$up=performance429();$up['source_ref']='person@example.com';echo json_encode(['pii'=>go429($pii),'secret'=>go429($secret),'extra'=>go429($extra),'upstream_pii'=>go429(perf:$up)],JSON_THROW_ON_ERROR);}
else{fwrite(STDERR,"Unknown work-origin scenario\n");exit(2);} echo PHP_EOL;

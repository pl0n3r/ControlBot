<?php
declare(strict_types=1);
require __DIR__.'/../src/MomentumRevenue.php';
require __DIR__.'/../src/MomentumPerformance.php';
use ControlBot\Momentum\MomentumPerformance;
use InvalidArgumentException;

function ref(string $n,string $c):string{return $n.':'.str_repeat($c,32);}
function pipeline(string $v='venture-condor',string $c='5',string $fresh='current'):array{return [
 'version'=>1,'pipeline_id'=>ref('pipeline','1'),'venture_id'=>$v,'lead_ref'=>ref('lead','2'),'opportunity_ref'=>ref('opportunity','3'),
 'source_ref'=>ref('source','4'),'campaign_ref'=>ref('campaign',$c),'creative_ref'=>ref('creative','6'),'owner_ref'=>ref('owner','7'),
 'stage'=>'won','qualification'=>'qualified','next_action_ref'=>null,'observed_at'=>1200,'freshness'=>$fresh,
 'customer_success_handoff_ref'=>ref('customer-success','9')];}
function attribution(string $k='observed',string $cur='COP'):array{return [
 'version'=>1,'attribution_id'=>ref('attribution','a'),'venture_id'=>'venture-condor','opportunity_ref'=>ref('opportunity','3'),
 'campaign_ref'=>ref('campaign','5'),'creative_ref'=>ref('creative','6'),'classification'=>$k,
 'amount_minor'=>$k==='unknown'?null:9000000,'currency'=>$cur,'source_ref'=>ref('source','b'),
 'evidence_refs'=>$k==='observed'?[ref('evidence','c')]:[],'observed_at'=>1450,'freshness'=>'current'];}
function paid(string $status='planned',string $fresh='current',string $v='venture-condor',string $c='5',string $cur='COP'):array{return [
 'version'=>1,'campaign_id'=>ref('campaign',$c),'venture_id'=>$v,'channel'=>'paid_social','operation'=>'launch',
 'spend'=>['spend_ref'=>ref('spend','d'),'amount_minor'=>2000000,'currency'=>$cur,'budget_ref'=>ref('budget','e'),
  'evidence_refs'=>[ref('evidence','f')],'blast_radius'=>'medium'],
 'freshness'=>$fresh,'observed_at'=>1400,'status'=>$status,'execution'=>false];}
function raw(string $fresh='current'):array{return [
 'version'=>1,'performance_id'=>ref('performance','1'),'venture_id'=>'venture-condor','campaign_ref'=>ref('campaign','5'),
 'period'=>['start_at'=>1000,'end_at'=>2000],'currency'=>'COP',
 'spend'=>['classification'=>'observed','amount_minor'=>2500000,'currency'=>'COP','source_ref'=>ref('source','2'),
  'evidence_refs'=>[ref('evidence','2')],'observed_at'=>1500,'freshness'=>'current','governed_spend_ref'=>ref('spend','d')],
 'funnel'=>['classification'=>'observed','leads'=>100,'conversions'=>20,'source_ref'=>ref('source','3'),
  'evidence_refs'=>[ref('evidence','3')],'observed_at'=>1500,'freshness'=>'current'],
 'cost'=>['classification'=>'observed','amount_minor'=>1000000,'currency'=>'COP','source_ref'=>ref('source','4'),
  'evidence_refs'=>[ref('evidence','4')],'observed_at'=>1500,'freshness'=>'current'],
 'source_ref'=>ref('source','5'),'evidence_refs'=>[ref('evidence','5')],'observed_at'=>1500,'freshness'=>$fresh,'execution'=>false];}
function run(?array $r=null,?array $p=null,?array $pipe=null,?array $a=null):array{
 return MomentumPerformance::project($r??raw(),$p??paid(),$pipe??pipeline(),$a??attribution());}
function bad(callable $f):bool{try{$f();return false;}catch(InvalidArgumentException){return true;}}

$case=$argv[1]??'';
if($case==='scope'){
 $period=raw();$period['period']=['start_at'=>1300,'end_at'=>2000];
 echo json_encode(['ok'=>run(),'venture'=>bad(fn()=>run(null,null,pipeline('venture-other'))),
  'campaign'=>bad(fn()=>run(null,paid(c:'8'))),'period'=>bad(fn()=>run($period)),'paid_denied'=>bad(fn()=>run(null,paid(status:'denied')))],JSON_THROW_ON_ERROR);
}elseif($case==='chain'){
 $d=run();$u=raw();$u['spend']['classification']='unknown';$u['spend']['amount_minor']=null;$u['spend']['evidence_refs']=[];
 echo json_encode(['full'=>$d,'unknown_spend'=>run($u),'planned'=>paid()['spend']['amount_minor']],JSON_THROW_ON_ERROR);
}elseif($case==='classification'){
 echo json_encode(['observed'=>run(),'inferred'=>run(a:attribution('inferred')),'unknown'=>run(a:attribution('unknown'))],JSON_THROW_ON_ERROR);
}elseif($case==='unit'){
 $z=raw();$z['funnel']['conversions']=0;$nc=raw();$nc['cost']['classification']='unknown';$nc['cost']['amount_minor']=null;$nc['cost']['evidence_refs']=[];
 echo json_encode(['full'=>run(),'zero'=>run($z),'no_cost'=>run($nc),'inferred'=>run(a:attribution('inferred'))],JSON_THROW_ON_ERROR);
}elseif($case==='guardrails'){
 $dup=raw();$dup['evidence_refs']=[ref('evidence','5'),ref('evidence','5')];
 $missing=raw();$missing['spend']['evidence_refs']=[];$outside=raw();$outside['spend']['observed_at']=999;
 $wrong=raw();$wrong['spend']['governed_spend_ref']=ref('spend','9');$stale=raw('stale');
 echo json_encode(['stale'=>run($stale),'pipeline_stale'=>run(pipe:pipeline(fresh:'stale')),'paid_stale'=>bad(fn()=>run(null,paid(fresh:'stale'))),
  'currency'=>bad(fn()=>run(null,null,null,attribution(cur:'USD'))),'duplicate'=>bad(fn()=>run($dup)),
  'missing'=>bad(fn()=>run($missing)),'outside'=>bad(fn()=>run($outside)),'spend_ref'=>bad(fn()=>run($wrong))],JSON_THROW_ON_ERROR);
}elseif($case==='pure'){
 $r=new ReflectionClass(MomentumPerformance::class);
 echo json_encode(['methods'=>array_map(fn($m)=>$m->getName(),$r->getMethods(ReflectionMethod::IS_PUBLIC)),'execution'=>run()['execution']],JSON_THROW_ON_ERROR);
}else{fwrite(STDERR,"Unknown performance scenario\n");exit(2);}
echo PHP_EOL;

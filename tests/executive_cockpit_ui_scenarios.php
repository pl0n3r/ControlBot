<?php
declare(strict_types=1);
require __DIR__.'/../src/ExecutiveCockpitUi.php';
use ControlBot\Business\ExecutiveCockpitUi;
function h(string $state='healthy',string $fresh='current'):array{return ['state'=>$state,'freshness'=>$fresh,'source_ref'=>$fresh==='unknown'?null:'controlbot:health/source','observed_at'=>$fresh==='unknown'?null:1000];}
function venture(string $id='venture-alpha',array $business=null,array $technical=null):array{return [
 'venture'=>['venture_id'=>$id,'group_id'=>'group-one','title'=>ucfirst(str_replace('venture-','',$id)),'state'=>'active','strategy_role'=>'core',
  'responsible'=>['identity_id'=>'owner-alpha','kind'=>'human','state'=>'active','source_ref'=>'controlbot:identity/owner-alpha','observed_at'=>1000]],
 'business_health'=>$business??h(),'technical_health'=>$technical??h(),
 'finance'=>['period'=>'2026-09','currency'=>'COP','net_revenue'=>1000,'gross_profit'=>900,'operating_result'=>700,'cash_in'=>1000,'cash_out'=>300,'customers'=>2,'transactions'=>3,'freshness'=>'fresh','confidence'=>'verified','source_ref'=>'controlbot:finance/source','observed_at'=>'2026-09-29T18:00:00Z'],
 'product_health'=>['product_id'=>'product-one','surface'=>'web','period'=>['start_at'=>100,'end_at'=>200],'freshness'=>'fresh','reasons'=>['observed_fresh'],'dimension_count'=>1],
 'infrastructure'=>['resource_id'=>'resource-one','kind'=>'service','state'=>'online','freshness'=>'fresh','source_ref'=>'controlbot:infra/source','observed_at'=>1000,'incident_count'=>0],
 'runtime'=>['source'=>'factoryrunner','provider_id'=>'runner-one','state'=>'idle','observed_at'=>1000,'heartbeat_at'=>995,'total_capacity'=>2,'occupied_capacity'=>0,'assignment_ref'=>null],
 'owner_inbox_counts'=>['fyi'=>0,'watch'=>1,'decision'=>1,'critical'=>0],
];}
function entry(string $class,string $ref,string $venture='venture-alpha'):array{$decision=in_array($class,['decision','critical'],true);return [
 'version'=>1,'entry_ref'=>'controlbot:inbox/'.$ref,'class'=>$class,'scope'=>['kind'=>'venture','ref'=>'controlbot:venture/'.$venture],
 'title'=>ucfirst($class).' item','summary'=>'Material exception','impact'=>'Known impact','actor_ref'=>null,
 'required_authority_level'=>$decision?'L4_OWNER':null,'decision_ref'=>$class==='decision'?'controlbot:decision/decide-aa':null,
 'options_ref'=>$class==='decision'?'controlbot:options/decide-aa':null,'deadline_at'=>$class==='decision'?2000:null,
 'source_ref'=>'controlbot:source/inbox','evidence_refs'=>[],'observed_at'=>1000,'freshness'=>'current'];}
function cockpit(array $ventures):array{return ['version'=>1,'group_id'=>'group-one','ventures'=>$ventures];}
function inbox():array{return ['version'=>1,'entries'=>[entry('critical','critical'),entry('decision','decision'),entry('watch','watch'),entry('fyi','fyi')]];}
function blocked(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}
function cockpitWithExtra(array $path, string $field, mixed $value): array
{
 $data = cockpit([venture()]);
 $node = &$data['ventures'][0];
 foreach ($path as $segment) {
  $node = &$node[$segment];
 }
 $node[$field] = $value;

 return $data;
}
$case=$argv[1]??'';
if($case==='base') echo ExecutiveCockpitUi::render(cockpit([venture()]),inbox());
elseif($case==='stale') echo ExecutiveCockpitUi::render(cockpit([venture('venture-alpha',h('degraded','stale'),h('unknown','unknown'))]),inbox());
elseif($case==='order') echo ExecutiveCockpitUi::render(cockpit([venture('venture-beta'),venture('venture-alpha')]),inbox());
elseif ($case === 'unsafe') {
 $specs = [
  'extra' => [[], 'manual_work_state', 'ready'],
  'responsible_extra' => [['venture', 'responsible'], 'manual_role', 'owner'],
  'runtime_extra' => [['runtime'], 'manual_work_state', 'ready'],
  'finance_extra' => [['finance'], 'manual_score', 'green'],
  'product_health_extra' => [['product_health'], 'manual_score', 'green'],
  'infrastructure_extra' => [['infrastructure'], 'manual_state', 'green'],
  'counts_extra' => [['owner_inbox_counts'], 'manual_priority', 'high'],
 ];
 $result = [];
 foreach ($specs as $name => [$path, $field, $value]) {
  $payload = cockpitWithExtra($path, $field, $value);
  $result[$name] = blocked(fn () => ExecutiveCockpitUi::render($payload, inbox()));
 }
 $secret = cockpit([venture()]);
 $secret['ventures'][0]['venture']['title'] = 'Bearer abcdefghijklmnopqrstuvwxyz';
 $pii = inbox();
 $pii['entries'][0]['summary'] = 'Contact alice@example.com';
 $result['secret'] = blocked(fn () => ExecutiveCockpitUi::render($secret, inbox()));
 $result['pii'] = blocked(fn () => ExecutiveCockpitUi::render(cockpit([venture()]), $pii));
 echo json_encode($result);
} else {
 fwrite(STDERR, "unknown scenario\n");
 exit(2);
}

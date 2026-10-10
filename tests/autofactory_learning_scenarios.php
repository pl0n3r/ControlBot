<?php
declare(strict_types=1);
require __DIR__.'/../src/AutoFactoryLearningEvent.php';
require __DIR__.'/../src/AutoFactoryLearningPolicy.php';
use ControlBot\AutoFactory\AutoFactoryLearningEvent;
use ControlBot\AutoFactory\AutoFactoryLearningPolicy;

$now=1800000000000;
function event(int $n,string $action='retry',string $outcome='success',int $age=0): array {
    global $now;
    return ['schemaVersion'=>1,'eventId'=>'event-'.str_pad((string)$n,8,'0',STR_PAD_LEFT),
      'installationId'=>'install-12345678','browserFamily'=>'chrome','extensionVersion'=>'1.6.29',
      'problemCode'=>'conversation-unavailable','interfaceState'=>'error','action'=>$action,
      'durationMs'=>1000+$n,'attempt'=>1,'outcome'=>$outcome,'policyVersion'=>1,'observedAt'=>$now-$age];
}
$scenario=$argv[1]??'';
if($scenario==='aggregate'){
  $batch=[]; for($i=1;$i<=20;$i++) $batch[]=event($i);
  $state=AutoFactoryLearningEvent::ingest($batch,[],$now);
  $again=AutoFactoryLearningEvent::ingest([$batch[0]],$state,$now);
  echo json_encode(['state'=>$state,'again'=>$again,'policy'=>AutoFactoryLearningPolicy::build($again,$now)],JSON_THROW_ON_ERROR),"\n";
}elseif($scenario==='atomic'){
  $bad=event(2); $bad['prompt']='private'; $rejected=false;
  try{AutoFactoryLearningEvent::ingest([event(1),$bad],[],$now);}catch(Throwable){$rejected=true;}
  echo json_encode(['rejected'=>$rejected],JSON_THROW_ON_ERROR),"\n";
}elseif($scenario==='threshold'){
  $batch=[]; for($i=1;$i<=19;$i++) $batch[]=event($i);
  $state=AutoFactoryLearningEvent::ingest($batch,[],$now);
  echo json_encode(AutoFactoryLearningPolicy::build($state,$now),JSON_THROW_ON_ERROR),"\n";
}elseif($scenario==='expiry'){
  $state=AutoFactoryLearningEvent::ingest([event(1,'retry','success',31*86400000)],[],$now);
  echo json_encode($state,JSON_THROW_ON_ERROR),"\n";
}else{exit(2);}

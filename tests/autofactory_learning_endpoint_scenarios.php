<?php
declare(strict_types=1);
require __DIR__.'/../src/AutoFactoryLearningEvent.php';
require __DIR__.'/../src/AutoFactoryLearningPolicy.php';
require __DIR__.'/../src/AutoFactoryLearningEndpoint.php';
use ControlBot\AutoFactory\AutoFactoryLearningEndpoint;
$scenario=$argv[1]??'';$now=1800000000000;
$event=['schemaVersion'=>1,'eventId'=>'event-12345678','installationId'=>'install-12345678','browserFamily'=>'chrome','extensionVersion'=>'1.6.29','problemCode'=>'conversation-unavailable','interfaceState'=>'error','action'=>'retry','durationMs'=>1000,'attempt'=>1,'outcome'=>'success','policyVersion'=>0,'observedAt'=>$now];
if($scenario==='disabled'){
 $blocked=false;try{AutoFactoryLearningEndpoint::upload([$event],[],$now,true,false);}catch(Throwable){$blocked=true;}
 echo json_encode(['blocked'=>$blocked,'contract'=>AutoFactoryLearningEndpoint::contract()],JSON_THROW_ON_ERROR),"\n";
}elseif($scenario==='auth'){
 $blocked=false;try{AutoFactoryLearningEndpoint::upload([$event],[],$now,false,true);}catch(Throwable){$blocked=true;}
 echo json_encode(['blocked'=>$blocked],JSON_THROW_ON_ERROR),"\n";
}elseif($scenario==='enabled'){
 $state=AutoFactoryLearningEndpoint::upload([$event],[],$now,true,true);
 echo json_encode(AutoFactoryLearningEndpoint::snapshot($state,$now,true,true,0),JSON_THROW_ON_ERROR),"\n";
}else{exit(2);}

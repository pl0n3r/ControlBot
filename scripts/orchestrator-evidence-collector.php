<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryOrchestratorEvidenceCollector.php';
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
if(PHP_SAPI!=='cli'){fwrite(STDERR,"orchestrator-evidence-collector: cli only\n");exit(64);}
$env=[
 'CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>getenv('CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED')?:'',
 'CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>getenv('CONTROLBOT_GITHUB_READ_TOKEN_FILE')?:'',
 'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>getenv('CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH')?:'',
];
$transport=static function(string $method,string $url,array $headers):array{
 FactoryOrchestratorEvidenceCollector::validateLiveRequest($method,$url);
 $responseHeaders=[];$ch=curl_init($url);if($ch===false)throw new RuntimeException('github transport unavailable.');
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>array_map(static fn($k,$v)=>$k.': '.$v,array_keys($headers),$headers),CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$responseHeaders){$p=strpos($line,':');if($p!==false)$responseHeaders[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return strlen($line);}]);
 $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 return FactoryOrchestratorEvidenceCollector::normalizeLiveResponse($status,$responseHeaders,$body);
};
try{$result=FactoryOrchestratorEvidenceCollector::run($env,$transport,time());echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit(0);}catch(Throwable){fwrite(STDERR,"orchestrator-evidence-collector: execution failed\n");exit(70);}

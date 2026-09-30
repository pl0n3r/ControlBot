<?php
declare(strict_types=1);
require __DIR__.'/../src/ExternalMonitorCore.php';
require __DIR__.'/../src/ExternalMonitorAdapter.php';

use ControlBot\Observability\ExternalMonitorAdapter;
use ControlBot\Observability\ExternalMonitorCore;

if(PHP_SAPI!=='cli'){fwrite(STDERR,"external-monitor: cli only\n");exit(64);}
$url=getenv('CONTROLBOT_MONITOR_URL')?:'';$webhook=getenv('CONTROLBOT_MONITOR_WEBHOOK')?:'';
if(!preg_match('#^https://[^\s@]+$#D',$url)||!preg_match('#^https://[^\s@]+$#D',$webhook)){
    fwrite(STDERR,"external-monitor: missing or invalid configuration\n");exit(64);
}
$http=static function(array $d): array {
    $ch=curl_init($d['url']);$started=microtime(true);$body='';$overflow=false;
    curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>$d['max_redirects']>0,CURLOPT_MAXREDIRS=>$d['max_redirects'],
        CURLOPT_TIMEOUT_MS=>$d['timeout_ms'],CURLOPT_CONNECTTIMEOUT_MS=>min(3000,$d['timeout_ms']),
        CURLOPT_USERAGENT=>'ControlBot-ExternalMonitor/1',CURLOPT_HTTPHEADER=>['Accept: application/json,text/html;q=0.5']]);
    curl_setopt($ch,CURLOPT_WRITEFUNCTION,static function($handle,string $chunk) use (&$body,&$overflow,$d): int {
        $remaining=$d['max_body_bytes']-strlen($body);
        if($remaining<=0){$overflow=true;return 0;}
        if(strlen($chunk)>$remaining){$body.=substr($chunk,0,$remaining);$overflow=true;return 0;}
        $body.=$chunk;return strlen($chunk);
    });
    $ok=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $redirects=(int)curl_getinfo($ch,CURLINFO_REDIRECT_COUNT);$latency=(int)round((microtime(true)-$started)*1000);curl_close($ch);
    if($errno===CURLE_OPERATION_TIMEDOUT)return ['outcome'=>'timeout','http_status'=>null,'latency_ms'=>$latency,'body'=>'','redirects'=>$redirects];
    if($overflow||$errno!==0||$ok===false)return ['outcome'=>'network_error','http_status'=>null,'latency_ms'=>$latency,'body'=>'','redirects'=>$redirects];
    return ['outcome'=>'response','http_status'=>$status,'latency_ms'=>$latency,'body'=>$body,'redirects'=>$redirects];
};
$alert=static function(array $payload) use($webhook): array {
    $body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$ch=curl_init($webhook);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_TIMEOUT_MS=>5000,
        CURLOPT_CONNECTTIMEOUT_MS=>3000,CURLOPT_HTTPHEADER=>['Content-Type: application/json','User-Agent: ControlBot-ExternalMonitor/1']]);
    curl_setopt($ch,CURLOPT_WRITEFUNCTION,static fn($handle,string $chunk): int=>strlen($chunk));
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
    return ['status'=>$errno===0&&$ok!==false&&$status>=200&&$status<300?'delivered':'failed','code'=>$errno===0?'webhook_http_'.$status:'webhook_transport_error'];
};
try{
    $adapter=new ExternalMonitorAdapter($http,$alert);$now=time();
    $probe=$adapter->probe(['version'=>1,'endpoint_kind'=>'health','url'=>$url,'observed_at'=>$now,'timeout_ms'=>5000,'max_redirects'=>1,'max_body_bytes'=>16384,'source_ref'=>'controlbot:external-monitor']);
    $assessment=ExternalMonitorCore::assess($probe,[],null,$now,300);
    $delivery=$adapter->deliver($assessment,['version'=>1,'channel'=>'webhook','observed_at'=>$now,'evidence_ref'=>'controlbot:external-monitor']);
    echo json_encode(['application_state'=>$assessment['application_state'],'alert_required'=>$assessment['alert_intent']['required'],'receipt'=>$delivery['receipt']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    if(($delivery['receipt']['status']??'delivered')==='failed')exit(3);
    exit($assessment['application_state']==='healthy'?0:2);
}catch(Throwable){fwrite(STDERR,"external-monitor: execution failed\n");exit(70);}

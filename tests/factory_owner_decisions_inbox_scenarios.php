<?php
declare(strict_types=1);

require __DIR__.'/../src/FactoryOrchestratorEvidenceCollector.php';
require __DIR__.'/../src/FactoryOrchestratorSnapshotSource.php';
require __DIR__.'/../src/FactoryOrchestratorLiveUi.php';

use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
use ControlBot\Business\FactoryOrchestratorSnapshotSource;
use ControlBot\Business\FactoryOrchestratorLiveUi;

$scenario=$argv[1]??'';
$now=strtotime('2026-10-05T14:00:00Z');

function labels(array $names): array { return array_map(static fn(string $name):array=>['name'=>$name],$names); }
function option(string $id,string $label): array {
    return [
        'id'=>$id,'label'=>$label,'effect'=>'Efecto '.$id,
        'pros'=>['Ventaja '.$id],'cons'=>['Costo de complejidad '.$id],
        'risk'=>$id==='A'?'low':'medium','cost'=>'Sin gasto externo',
        'reversible'=>true,'explain_simple'=>'Explicación '.$id,
    ];
}
function gateBody(bool $release=false): string {
    $gate=[
        'category'=>'product-direction','context'=>'CAMPO NO PROYECTADO',
        'title_simple'=>'Preparar entrada HTTP segura',
        'summary_simple'=>'Dos hojas offline sin activar producción.',
        'explain_simple'=>'La decisión solo prepara contratos reversibles.',
        'why_recommended'=>'Cierra una frontera verificable.',
        'blocks'=>'Siguiente tramo del roadmap.',
        'options'=>[option('A','Aprobar'),option('B','Diferir')],
        'recommendation'=>'A','safe_default'=>'B',
    ];
    $body='<!-- factory-human-gate '.json_encode($gate,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).' -->';
    if($release){
        $body.="\n<!-- factory-release-window {\"version\":1,\"opened_at\":\"2026-10-05T13:00:00Z\",\"expires_at\":\"2026-10-05T15:00:00Z\",\"sha\":\"".str_repeat('a',40)."\"} -->";
    }
    return $body;
}
function collect(string $scenario,int $now): array {
    $dir=sys_get_temp_dir().'/cb746-'.bin2hex(random_bytes(4));mkdir($dir);
    $token=$dir.'/token';$evidence=$dir.'/evidence.json';
    file_put_contents($token,'read-only');chmod($token,0600);
    $transport=static function(string $method,string $url,array $headers)use($scenario):array{
        $path=parse_url($url,PHP_URL_PATH)?:'';$json=[];
        if(str_ends_with($path,'/issues/767')){
            $json=['number'=>767,'user'=>['login'=>'pl0n3r'],'body'=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->'];
        } elseif($path==='/repos/pl0n3r/ControlBot/issues'){
            if($scenario==='hostile'){
                $bad=[
                    'title_simple'=>'<script>alert(1)</script>','summary_simple'=>'Resumen',
                    'why_recommended'=>'Porque','blocks'=>'Nada',
                    'options'=>[option('A','Uno'),option('A','Duplicada')],
                    'recommendation'=>'A','safe_default'=>'A',
                ];
                $json=[
                    ['number'=>744,'title'=>'<b>Decisión hostil</b>','labels'=>labels(['decisión: dueño']),'body'=>'<!-- factory-human-gate '.json_encode($bad,JSON_THROW_ON_ERROR).' -->'],
                    ['number'=>745,'title'=>'Marker enorme','labels'=>labels(['decisión: dueño']),'body'=>str_repeat('x',70000)],
                ];
            } else {
                $json=[['number'=>744,'title'=>'Decisión producto','labels'=>labels(['decisión: dueño']),'body'=>gateBody(false)]];
            }
        } elseif($path==='/repos/pl0n3r/Factory/issues' && $scenario!=='hostile'){
            $json=[['number'=>1014,'title'=>'Decisión release','labels'=>labels(['decisión: dueño']),'body'=>gateBody(true)]];
        } elseif($path==='/repos/pl0n3r/Condor/issues' && $scenario!=='hostile'){
            $json=[['number'=>437,'title'=>'Decisión antigua','labels'=>labels(['decisión: dueño']),'body'=>'Sin marker estructurado']];
        } elseif(str_ends_with($path,'/issues')) {
            $json=[];
        } elseif(str_ends_with($path,'/pulls')) {
            $json=[];
        }
        return ['status'=>200,'headers'=>['x-ratelimit-remaining'=>'100'],'bytes'=>strlen(json_encode($json,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),'json'=>$json];
    };
    FactoryOrchestratorEvidenceCollector::run([
        'CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED'=>'1',
        'CONTROLBOT_GITHUB_READ_TOKEN_FILE'=>$token,
        'CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH'=>$evidence,
    ],$transport,$now);
    return json_decode(file_get_contents($evidence),true,64,JSON_THROW_ON_ERROR);
}

if($scenario==='structured'){
    $evidence=collect($scenario,$now);
    $view=FactoryOrchestratorSnapshotSource::fromInjectedEvidence($evidence,$now);
    echo json_encode(['evidence'=>$evidence,'view'=>$view,'html'=>FactoryOrchestratorLiveUi::render($view)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit;
}
if($scenario==='hostile'){
    $evidence=collect($scenario,$now);
    $view=FactoryOrchestratorSnapshotSource::fromInjectedEvidence($evidence,$now);
    echo json_encode(['evidence'=>$evidence,'view'=>$view,'html'=>FactoryOrchestratorLiveUi::render($view)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit;
}
if($scenario==='invalid-command'){
    $evidence=collect('structured',$now);
    $view=FactoryOrchestratorSnapshotSource::fromInjectedEvidence($evidence,$now);
    $checks=[];
    foreach([
        static function(array &$v):void{$v['owner_decisions'][0]['repository_ref']='pl0n3r/Evil';},
        static function(array &$v):void{$v['owner_decisions'][0]['issue_number']=0;},
        static function(array &$v):void{$v['owner_decisions'][0]['options'][0]['id']='Z';},
    ] as $mutate){
        $copy=$view;$mutate($copy);
        try{FactoryOrchestratorLiveUi::render($copy);$checks[]=false;}catch(Throwable){$checks[]=true;}
    }
    echo json_encode(['checks'=>$checks],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"scenario invalid\n");exit(2);

<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryLiveSnapshot.php';
require __DIR__.'/../src/FactoryLiveMatrix.php';
require __DIR__.'/../src/FactoryLearningSnapshot.php';
require __DIR__.'/../src/FactoryLiveUi.php';
use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryLiveMatrix;
use ControlBot\Business\FactoryLearningSnapshot;
use ControlBot\Business\FactoryLiveUi;

$scenario=$argv[1]??'';
function lsig(string $id,string $authority,string $state='healthy',string $fresh='current',array $data=[],int $at=190): array {
    return ['id'=>$id,'authority'=>$authority,'state'=>$state,'source_ref'=>'controlbot:evidence/'.$id,'observed_at'=>$at,'freshness'=>$fresh,'data'=>$data];
}
function learningFixture(): array {
    $work=[];$layers=['product_business','application','data','infrastructure','ci_cd','security_privacy','governance_agents','costs_limits'];
    foreach($layers as $i=>$layer)$work[]=lsig('work:'.$layer,'github_project_snapshot','healthy','current',[
        'title'=>$layer,'layer'=>$layer,'project'=>'ControlBot','evidence_ref'=>'https://github.com/pl0n3r/ControlBot/issues/'.(600+$i)
    ],180+$i);
    $work[]=lsig('incident:real','github_project_snapshot','degraded','current',[
        'title'=>'Database outage','kind'=>'incident','open'=>true,'layer'=>'infrastructure','project'=>'ControlBot',
        'evidence_ref'=>'https://github.com/pl0n3r/ControlBot/issues/700'
    ],188);
    $work[]=lsig('incident:auto','github_project_snapshot','pending','current',[
        'title'=>'[AUTO] CI warning','kind'=>'incident','open'=>true,'layer'=>'ci_cd','project'=>'ControlBot',
        'evidence_ref'=>'https://github.com/pl0n3r/ControlBot/issues/701'
    ],189);
    $work[]=lsig('work:metric-spoof','github_project_snapshot','healthy','current',[
        'title'=>'Untrusted metric source','metric'=>'mttr_seconds','metric_value'=>0,'project'=>'ControlBot',
        'evidence_ref'=>'https://github.com/pl0n3r/ControlBot/issues/702'
    ],199);
    $metrics=[
        'lessons_per_week_project'=>3,'incidents_by_class'=>'deployment:1','mttr_seconds'=>420,
        'blockers_with_cause'=>2,'fix_feat_ratio'=>'2:5','learning_gaps'=>1,
    ];
    $learning=[];$n=710;
    foreach($metrics as $metric=>$value)$learning[]=lsig('learning:'.$metric,'incident_lesson','healthy','current',[
        'title'=>$metric,'metric'=>$metric,'metric_value'=>$value,'project'=>'ControlBot',
        'evidence_ref'=>'https://github.com/pl0n3r/ControlBot/issues/'.$n++
    ],190);
    $learning[0]['data']['metric_value']=7;
    $learning[]=lsig('learning:lessons_per_week_project:condor','incident_lesson','healthy','current',[
        'title'=>'lessons_per_week_project Condor','metric'=>'lessons_per_week_project','metric_value'=>3,'project'=>'Condor',
        'evidence_ref'=>'https://github.com/pl0n3r/Condor/issues/1'
    ],191);
    $learning[0]['data']['recurrence_count']=0;
    return [
        'production'=>[lsig('production:controlbot','observability_project_status','healthy','current',[
            'title'=>'Production','layer'=>'production_observability','project'=>'ControlBot',
            'evidence_ref'=>'https://github.com/pl0n3r/ControlBot/issues/720'
        ],190)],
        'quality'=>[lsig('quality:controlbot','quality_health','healthy','current',[
            'title'=>'Quality','layer'=>'quality','project'=>'ControlBot',
            'evidence_ref'=>'https://sonarcloud.io/project/overview?id=pl0n3r_factory-control'
        ],190)],
        'work'=>$work,'learning'=>$learning,'tool_usage'=>null,
    ];
}
function learningBuilt(array $raw): array {
    $snapshot=FactoryLiveSnapshot::build($raw,200);
    return [$snapshot,FactoryLearningSnapshot::build($snapshot)];
}

if($scenario==='fingerprint'){
    [$snapshot,]=$unused=learningBuilt(learningFixture());
    $snapshot['fingerprint']='021f1312f923fc8e6e4e43ee70eef9ff1402571831c973c518a02db5a6f06873';
    $valid=true;
    try{FactoryLearningSnapshot::build($snapshot);}catch(Throwable){$valid=false;}
    $snapshot['fingerprint']='not-a-sha256';
    $invalidBlocked=false;
    try{FactoryLearningSnapshot::build($snapshot);}catch(Throwable){$invalidBlocked=true;}
    echo json_encode(['valid'=>$valid,'invalid_blocked'=>$invalidBlocked],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='missing_scope'){
    $raw=learningFixture();unset($raw['learning'][0]['data']['project']);
    [, $learning]=learningBuilt($raw);
    echo json_encode($learning['metrics']['lessons_per_week_project']['global'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='explicit_global'){
    $raw=learningFixture();unset($raw['learning'][0]['data']['project']);
    $raw['learning'][0]['data']['scope']='global';$raw['learning'][0]['data']['metric_value']=11;
    [, $learning]=learningBuilt($raw);
    echo json_encode($learning['metrics']['lessons_per_week_project'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='project_contract'){
    $raw=learningFixture();
    $raw['learning'][0]['data']['project']='brvtal';
    $raw['work'][0]['data']['project']='brvtal';
    [, $learning]=learningBuilt($raw);
    $metric=$learning['metrics']['lessons_per_week_project']['by_project']['BRVTAL']??null;
    $layerProject=$learning['layers']['product_business']['signals'][0]['project']??null;
    $unknownRejected=false;
    $bad=learningFixture();$bad['learning'][0]['data']['project']='unknown-project';
    try{learningBuilt($bad);}catch(Throwable){$unknownRejected=true;}
    $conflictRejected=false;
    $conflict=learningFixture();$conflict['learning'][0]['data']['scope']='global';
    try{learningBuilt($conflict);}catch(Throwable){$conflictRejected=true;}
    echo json_encode([
        'metric_value'=>$metric['value']??null,'layer_project'=>$layerProject,
        'unknown_rejected'=>$unknownRejected,'conflict_rejected'=>$conflictRejected
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($scenario==='ui_missing_scope'){
    $raw=learningFixture();
    unset($raw['work'][0]['data']['project']);
    [$snapshot,$learning]=learningBuilt($raw);$matrix=FactoryLiveMatrix::build($snapshot);
    echo FactoryLiveUi::render($snapshot,$matrix,$learning),PHP_EOL;exit;
}
if($scenario==='full'){[, $learning]=learningBuilt(learningFixture());echo json_encode($learning,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;}
if($scenario==='missing'){
    $raw=learningFixture();$raw['work']=array_values(array_filter($raw['work'],fn(array $x):bool=>($x['data']['layer']??null)!=='security_privacy'));
    [, $learning]=learningBuilt($raw);echo json_encode($learning['layers']['security_privacy'],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='ui'){
    [$snapshot,$learning]=learningBuilt(learningFixture());$matrix=FactoryLiveMatrix::build($snapshot);
    echo FactoryLiveUi::render($snapshot,$matrix,$learning),PHP_EOL;exit;
}
if($scenario==='recurrence'){[, $learning]=learningBuilt(learningFixture());echo json_encode($learning['recurrence'],JSON_THROW_ON_ERROR),PHP_EOL;exit;}
fwrite(STDERR,"scenario inválido\n");exit(2);

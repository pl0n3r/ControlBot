<?php
declare(strict_types=1);
require __DIR__.'/../src/FactoryOrchestratorLiveUi.php';

use ControlBot\Business\FactoryOrchestratorLiveUi;

$scenario=$argv[1]??'';

function view(): array {
    return [
        'version'=>1,
        'observed_at'=>220,
        'source_snapshot'=>str_repeat('a',64),
        'read_only'=>true,
        'central'=>[
            'activity_state'=>'ACTIVE','available'=>1,'reserved'=>2,'blocked'=>1,
            'source_ref'=>'github:pl0n3r/Factory@abc','observed_at'=>200,
            'freshness'=>'current','age_seconds'=>20,
        ],
        'fronts'=>[
            [
                'id'=>'work:controlbot-622','repository_ref'=>'pl0n3r/ControlBot',
                'issue_ref'=>'github:pl0n3r/ControlBot#622','status'=>'reserved',
                'progress_percent'=>40,'progress_state'=>'EVIDENCED',
                'source_ref'=>'controlbot:evidence/work:controlbot-622',
                'observed_at'=>210,'freshness'=>'current','age_seconds'=>10,
            ],
            [
                'id'=>'work:factory-868','repository_ref'=>'pl0n3r/Factory',
                'issue_ref'=>'github:pl0n3r/Factory#868','status'=>'in_review',
                'progress_percent'=>null,'progress_state'=>'UNKNOWN',
                'source_ref'=>'controlbot:evidence/work:factory-868',
                'observed_at'=>190,'freshness'=>'stale','age_seconds'=>30,
            ],
        ],
        'owner_decisions'=>[
            [
                'id'=>'decision:controlbot-700',
                'issue_ref'=>'github:pl0n3r/ControlBot#700',
                'source_ref'=>'controlbot:evidence/decision:700',
                'observed_at'=>150,'freshness'=>'current','age_seconds'=>70,
            ],
        ],
        'fingerprint'=>str_repeat('b',64),
    ];
}

if($scenario==='full'){
    echo FactoryOrchestratorLiveUi::render(view());
    exit;
}
if($scenario==='stale_unknown'){
    $v=view();
    $v['central']['activity_state']='UNKNOWN';
    $v['central']['freshness']='unknown';
    $v['central']['available']=$v['central']['reserved']=$v['central']['blocked']=null;
    $v['fronts'][0]['status']='unknown';
    $v['fronts'][0]['freshness']='unknown';
    $v['fronts'][0]['progress_percent']=null;
    $v['fronts'][0]['progress_state']='UNKNOWN';
    echo FactoryOrchestratorLiveUi::render($v);
    exit;
}
if($scenario==='hostile'){
    $v=view();
    $v['fronts'][0]['id']='<script>alert(1)</script>';
    echo FactoryOrchestratorLiveUi::render($v);
    exit;
}
if($scenario==='invalid'){
    $v=view();
    $v['fronts'][0]['issue_ref']='javascript:alert(1)';
    try{FactoryOrchestratorLiveUi::render($v);echo 'unsafe';}catch(Throwable){echo 'blocked';}
    exit;
}
fwrite(STDERR,"scenario invalid\n");
exit(2);

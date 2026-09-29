<?php
declare(strict_types=1);

require_once __DIR__.'/../src/ExternalApiContract.php';
require_once __DIR__.'/../src/ExternalApiReadProjection.php';

use ControlBot\ExternalApi\ExternalApiReadProjection;
use InvalidArgumentException;

function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function meta(array $overrides=[]): array {
    return array_replace([
        'request_id'=>str_repeat('a',32),
        'correlation_id'=>str_repeat('b',32),
        'generated_at'=>2000,
        'freshness'=>[
            'state'=>'current','observed_at'=>1900,'source_ref'=>'controlbot:cockpit/group-health',
        ],
    ],$overrides);
}
function venture(array $overrides=[]): array {
    return array_replace([
        'venture_ref'=>'controlbot:venture/condor',
        'business_state'=>'current',
        'technical_state'=>'stale',
        'pending_decisions'=>2,
        'critical_events'=>1,
    ],$overrides);
}
function entry(array $overrides=[]): array {
    return array_replace([
        'entry_id'=>'decision-alpha',
        'kind'=>'DECISION',
        'venture_ref'=>'controlbot:venture/condor',
        'title'=>'Revisión de bearer token',
        'summary'=>'La política de password requiere revisión del owner.',
        'decision_id'=>'decision-alpha',
        'deadline_at'=>2500,
    ],$overrides);
}

$case=$argv[1]??'';
if($case==='cockpit'){
    $out=ExternalApiReadProjection::cockpit(meta(),[venture()]);
}elseif($case==='inbox'){
    $out=ExternalApiReadProjection::ownerInbox(meta(),[
        entry(),
        entry([
            'entry_id'=>'watch-beta','kind'=>'WATCH','venture_ref'=>null,
            'decision_id'=>null,'deadline_at'=>null,'title'=>'Seguimiento',
            'summary'=>'Señal observada sin acción requerida.',
        ]),
    ]);
}elseif($case==='freshness'){
    $out=[
        'current'=>ExternalApiReadProjection::cockpit(meta(),[]),
        'stale'=>ExternalApiReadProjection::cockpit(meta(['freshness'=>[
            'state'=>'stale','observed_at'=>1000,'source_ref'=>'controlbot:cockpit/group-health',
        ]]),[]),
        'unknown'=>ExternalApiReadProjection::cockpit(meta(['freshness'=>[
            'state'=>'unknown','observed_at'=>null,'source_ref'=>null,
        ]]),[]),
        'unknown_with_source'=>bad(fn()=>ExternalApiReadProjection::cockpit(meta(['freshness'=>[
            'state'=>'unknown','observed_at'=>null,'source_ref'=>'controlbot:cockpit/group-health',
        ]]),[])),
    ];
}elseif($case==='invalid'){
    $out=[
        'extra_meta'=>bad(fn()=>ExternalApiReadProjection::cockpit(meta(['secret'=>'x']),[])),
        'bad_state'=>bad(fn()=>ExternalApiReadProjection::cockpit(meta(),[venture(['business_state'=>'healthy'])])),
        'bad_ref'=>bad(fn()=>ExternalApiReadProjection::cockpit(meta(),[venture(['venture_ref'=>'https://example.test'])])),
        'bad_time'=>bad(fn()=>ExternalApiReadProjection::cockpit(meta(['generated_at'=>0]),[])),
        'sensitive_ref'=>bad(fn()=>ExternalApiReadProjection::cockpit(meta(),[
            venture(['venture_ref'=>'controlbot:venture/api_key.value']),
        ])),
        'extra_entry'=>bad(fn()=>ExternalApiReadProjection::ownerInbox(meta(),[entry(['internal_model_id'=>'x'])])),
    ];
}elseif($case==='pure'){
    $reflection=new ReflectionClass(ExternalApiReadProjection::class);
    $methods=[];
    foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
        if($method->getDeclaringClass()->getName()===ExternalApiReadProjection::class) $methods[]=$method->getName();
    sort($methods);
    $out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiReadProjection.php')];
}elseif($case==='no_leak'){
    $out=[
        'cockpit'=>ExternalApiReadProjection::cockpit(meta(),[venture()]),
        'inbox'=>ExternalApiReadProjection::ownerInbox(meta(),[entry()]),
        'blocked_internal'=>bad(fn()=>ExternalApiReadProjection::cockpit(meta(),[venture(['internal_owner_email'=>'x@example.test'])])),
    ];
}else{
    fwrite(STDERR,"unknown scenario\n");
    exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);

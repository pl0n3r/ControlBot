<?php
declare(strict_types=1);
foreach([
    'InfrastructureProvider','InfrastructureResource','InfrastructureObservation','RuntimeCapacitySignal',
    'VentureIdentity','VentureFinancialSnapshot','OwnerInbox','ProductIntelligence',
    'ProductHealthSnapshot','ExecutiveCockpit'
] as $file) require __DIR__.'/../src/'.$file.'.php';
use ControlBot\Business\ExecutiveCockpit;
function venture(string $id='venture-alpha'): array {
    $slug=str_replace('venture-','',$id);
    return ['version'=>1,'venture_id'=>$id,'group_id'=>'group-one','slug'=>$slug,'title'=>ucfirst($slug),'strategy_role'=>'core','state'=>'active','responsible_identity_id'=>'owner-alpha','project_refs'=>[]];
}
function identity(): array {
    return ['version'=>1,'identity_id'=>'owner-alpha','kind'=>'human','display_name'=>'Owner Alpha','state'=>'active','source_ref'=>'controlbot:identity/owner-alpha','observed_at'=>1000];
}
function health(string $state='healthy',string $fresh='current'): array {
    return ['state'=>$state,'freshness'=>$fresh,'source_ref'=>$fresh==='unknown'?null:'controlbot:health/source','observed_at'=>$fresh==='unknown'?null:1000];
}
function finance(string $venture='venture-alpha'): array {
    return ['version'=>1,'venture_id'=>$venture,'period'=>'2026-09','currency'=>'COP','revenue'=>1000,'refunds'=>0,'direct_costs'=>100,'operating_costs'=>200,'infrastructure_costs'=>50,'ai_costs'=>50,'cash_in'=>1000,'cash_out'=>400,'customers'=>2,'transactions'=>3,'revenue_streams'=>[['stream_id'=>'main','revenue'=>1000,'refunds'=>0,'customers'=>2,'transactions'=>3]],'source_ref'=>'controlbot:finance/source','observed_at'=>'2026-09-29T18:00:00Z','freshness'=>'fresh','confidence'=>'verified'];
}
function product(string $venture='venture-alpha',string $fresh='fresh'): array {
    return ['product_id'=>'product-one','metrics'=>[[
        'version'=>1,'metric_id'=>'metric-activation','venture_id'=>$venture,'product_id'=>'product-one',
        'surface'=>'web','category'=>'activation','period'=>['start_at'=>100,'end_at'=>200],
        'status'=>'measured','value'=>0.8,'unit'=>'ratio','source_ref'=>'aggregate:product/activation',
        'evidence_ref'=>'controlbot:evidence/activation','sample_size'=>100,'freshness'=>$fresh,
        'confidence'=>0.9,'nature'=>'observed',
    ]]];
}
function resource(string $venture='venture-alpha'): array {
    return [
        'version'=>1,'resource_id'=>'resource-one','kind'=>'service','provider_id'=>'provider-one',
        'account_id'=>'account-one','project_ref'=>null,'venture_ref'=>'controlbot:venture/'.$venture,
        'environment_ref'=>null,'service_ref'=>null,'parent_ref'=>null,'release_evidence'=>null,
        'cost_ref'=>null,'backup_refs'=>[],'source_ref'=>'controlbot:infra/resource','observed_at'=>1000,
    ];
}
function observation(): array {
    return [
        'version'=>1,'resource_id'=>'resource-one','state'=>'online','source_ref'=>'controlbot:infra/observation','observed_at'=>1000,'freshness'=>'fresh',
        'backup_freshness'=>['state'=>'fresh','source_ref'=>'controlbot:infra/backup','observed_at'=>990],
        'restore_verification'=>['state'=>'verified','source_ref'=>'controlbot:infra/restore','observed_at'=>980],
        'cost_attribution'=>['state'=>'unknown','cost_ref'=>null,'source_ref'=>null,'observed_at'=>null],
        'release_drift'=>['state'=>'unknown','expected_sha'=>null,'observed_sha'=>null,'source_ref'=>null,'observed_at'=>null],
        'incident_refs'=>[],
    ];
}
function infra(string $venture='venture-alpha'): array {
    return ['resource'=>resource($venture),'observation'=>observation()];
}
function runtime(string $venture='venture-alpha'): array {
    return ['venture_id'=>$venture,'signal'=>[
        'version'=>1,'source'=>'factoryrunner','provider_id'=>'runner-one','account_ref'=>'controlbot:account/runner-one',
        'session_ref'=>'controlbot:session/runner-one','state'=>'idle','observed_at'=>1000,'heartbeat_at'=>995,
        'total_capacity'=>2,'occupied_capacity'=>0,'assignment_ref'=>null,'generation'=>1,'attempt'=>1,'capabilities'=>['php'],
    ]];
}
function inbox(string $venture='venture-alpha'): array {
    $scope='controlbot:venture/'.$venture;
    return [
        ['version'=>1,'entry_ref'=>'controlbot:entry/watch-one','class'=>'watch','scope'=>['kind'=>'venture','ref'=>$scope],'title'=>'Watch growth','summary'=>'Deviation tracked','impact'=>'Low','actor_ref'=>null,'required_authority_level'=>null,'decision_ref'=>null,'options_ref'=>null,'deadline_at'=>null,'source_ref'=>'controlbot:source/watch','evidence_refs'=>[],'observed_at'=>1000,'freshness'=>'current'],
        ['version'=>1,'entry_ref'=>'controlbot:entry/decision-one','class'=>'decision','scope'=>['kind'=>'venture','ref'=>$scope],'title'=>'Approve budget','summary'=>'Owner decision','impact'=>'Medium','actor_ref'=>null,'required_authority_level'=>'L4_OWNER','decision_ref'=>'controlbot:decision/budget','options_ref'=>null,'deadline_at'=>1100,'source_ref'=>'controlbot:source/decision','evidence_refs'=>[],'observed_at'=>1000,'freshness'=>'current'],
    ];
}
function row(array $overrides=[]): array {
    return array_replace([
        'venture'=>venture(),'responsible_identity'=>identity(),
        'business_health'=>health(),'technical_health'=>health(),
        'finance'=>finance(),'product_health'=>product(),'infrastructure'=>infra(),
        'runtime'=>runtime(),'owner_inbox'=>inbox(),
    ],$overrides);
}
function bad(callable $fn): bool {try{$fn();return false;}catch(InvalidArgumentException){return true;}}
$case=$argv[1]??'';
if($case==='base') $out=ExecutiveCockpit::build([row()]);
elseif($case==='stale') $out=ExecutiveCockpit::build([row([
    'business_health'=>health('degraded','stale'),
    'technical_health'=>health('unknown','unknown'),
    'product_health'=>product('venture-alpha','unknown'),
])]);
elseif($case==='scope') $out=[
    'finance'=>bad(fn()=>ExecutiveCockpit::build([row(['finance'=>finance('venture-beta')])])),
    'infra'=>bad(fn()=>ExecutiveCockpit::build([row(['infrastructure'=>infra('venture-beta')])])),
    'runtime'=>bad(fn()=>ExecutiveCockpit::build([row(['runtime'=>runtime('venture-beta')])])),
    'product'=>bad(fn()=>ExecutiveCockpit::build([row(['product_health'=>product('venture-beta')])])),
];
elseif($case==='order') {
    $beta=row([
        'venture'=>venture('venture-beta'),'finance'=>finance('venture-beta'),
        'product_health'=>product('venture-beta'),'infrastructure'=>infra('venture-beta'),
        'runtime'=>runtime('venture-beta'),'owner_inbox'=>inbox('venture-beta'),
    ]);
    $out=ExecutiveCockpit::build([$beta,row()]);
}
elseif($case==='duplicate') $out=['duplicate'=>bad(fn()=>ExecutiveCockpit::build([row(),row()]))];
elseif($case==='source') $out=['source'=>file_get_contents(__DIR__.'/../src/ExecutiveCockpit.php')];
else {fwrite(STDERR,"unknown scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

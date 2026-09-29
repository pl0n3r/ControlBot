<?php
declare(strict_types=1);
require __DIR__.'/../src/VendorRegistry.php';
require __DIR__.'/../src/VendorExceptionSignal.php';

use ControlBot\Vendors\VendorExceptionSignal;

function vx(array $o=[]): array {
    $base=[
        'version'=>1,
        'vendor_id'=>'vendor:11111111111111111111111111111111',
        'venture_id'=>'venture-alpha',
        'category'=>'cloud',
        'service_ref'=>'service:22222222222222222222222222222222',
        'owner_ref'=>'identity:33333333333333333333333333333333',
        'lifecycle'=>'active',
        'cost'=>[
            'venture_id'=>'venture-alpha','amount'=>100.0,'currency'=>'USD','billing_cadence'=>'monthly',
            'capital_ref'=>'capital:44444444444444444444444444444444',
        ],
        'contract_ref'=>'contract:55555555555555555555555555555555',
        'data_ref'=>'data:66666666666666666666666666666666',
        'subprocessor_refs'=>[],
        'credentials_ref'=>'credential:77777777777777777777777777777777',
        'criticality'=>'high',
        'exit_plan_ref'=>'exit:88888888888888888888888888888888',
        'export_ref'=>'export:99999999999999999999999999999999',
        'sla_state'=>'healthy',
        'security_review'=>[
            'venture_id'=>'venture-alpha','state'=>'approved',
            'aegis_review_ref'=>'aegis:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
        'legal_review'=>[
            'venture_id'=>'venture-alpha','state'=>'approved',
            'lex_review_ref'=>'lex:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ],
        'renewal_at'=>2000,
        'expiry_at'=>3000,
        'health'=>'healthy',
        'freshness'=>[
            'state'=>'fresh','observed_at'=>1500,
            'source_ref'=>'evidence:cccccccccccccccccccccccccccccccc',
        ],
        'offboarding'=>[
            'export_ref'=>null,'revoke_ref'=>null,'retention_ref'=>null,
            'continuity_ref'=>null,'evidence_ref'=>null,
        ],
    ];
    return array_replace_recursive($base,$o);
}
function project(array $v,int $now=1600,int $horizon=500): array {
    return VendorExceptionSignal::project($v,$now,$horizon);
}
function types(array $signals): array { return array_column($signals,'type'); }

$case=$argv[1]??'';
if($case==='material'){
    $vendor=vx([
        'health'=>'degraded','sla_state'=>'degraded',
        'security_review'=>['venture_id'=>'venture-alpha','state'=>'rejected','aegis_review_ref'=>'aegis:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        'legal_review'=>['venture_id'=>'venture-alpha','state'=>'rejected','lex_review_ref'=>'lex:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
        'exit_plan_ref'=>null,'export_ref'=>null,'criticality'=>'critical',
    ]);
    $out=['signals'=>project($vendor),'types'=>types(project($vendor))];
}elseif($case==='unknown'){
    $vendor=vx([
        'criticality'=>'critical','health'=>'unknown','sla_state'=>'unknown',
        'freshness'=>['state'=>'unknown','observed_at'=>null,'source_ref'=>null],
        'renewal_at'=>null,'expiry_at'=>null,
    ]);
    $out=['signals'=>project($vendor),'types'=>types(project($vendor))];
}elseif($case==='provenance'){
    $out=['signals'=>project(vx())];
}elseif($case==='boundary'){
    $signals=project(vx(['renewal_at'=>null,'expiry_at'=>null]));
    $out=['signals'=>$signals,'payload'=>json_encode($signals,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)];
}elseif($case==='deterministic'){
    $a=project(vx([
        'health'=>'degraded','security_review'=>['venture_id'=>'venture-alpha','state'=>'rejected','aegis_review_ref'=>'aegis:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ]));
    $b=project(vx([
        'health'=>'degraded','security_review'=>['venture_id'=>'venture-alpha','state'=>'rejected','aegis_review_ref'=>'aegis:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ]));
    $out=['a'=>$a,'b'=>$b,'types'=>types($a)];
}elseif($case==='pure'){
    $r=new ReflectionClass(VendorExceptionSignal::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===VendorExceptionSignal::class
    ));
    sort($methods,SORT_STRING);
    $out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/VendorExceptionSignal.php')];
}else{fwrite(STDERR,"Unknown vendor exception scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

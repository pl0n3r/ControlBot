<?php
declare(strict_types=1);
require __DIR__.'/../src/VendorRegistry.php';
require __DIR__.'/../src/VendorExceptionSignal.php';
require __DIR__.'/../src/OwnerInbox.php';
require __DIR__.'/../src/VendorOwnerInboxProjection.php';

use ControlBot\Vendors\VendorExceptionSignal;
use ControlBot\Vendors\VendorOwnerInboxProjection;

function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function vendor(array $o=[]): array {
    $base=[
        'version'=>1,'vendor_id'=>'vendor:11111111111111111111111111111111','venture_id'=>'venture-alpha',
        'category'=>'cloud','service_ref'=>'service:22222222222222222222222222222222',
        'owner_ref'=>'identity:33333333333333333333333333333333','lifecycle'=>'active',
        'cost'=>['venture_id'=>'venture-alpha','amount'=>100.0,'currency'=>'USD','billing_cadence'=>'monthly','capital_ref'=>'capital:44444444444444444444444444444444'],
        'contract_ref'=>'contract:55555555555555555555555555555555',
        'data_ref'=>'data:66666666666666666666666666666666','subprocessor_refs'=>[],
        'credentials_ref'=>'credential:77777777777777777777777777777777','criticality'=>'high',
        'exit_plan_ref'=>'exit:88888888888888888888888888888888','export_ref'=>'export:99999999999999999999999999999999',
        'sla_state'=>'healthy',
        'security_review'=>['venture_id'=>'venture-alpha','state'=>'approved','aegis_review_ref'=>'aegis:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        'legal_review'=>['venture_id'=>'venture-alpha','state'=>'approved','lex_review_ref'=>'lex:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
        'renewal_at'=>2000,'expiry_at'=>3000,'health'=>'healthy',
        'freshness'=>['state'=>'fresh','observed_at'=>1500,'source_ref'=>'evidence:cccccccccccccccccccccccccccccccc'],
        'offboarding'=>['export_ref'=>null,'revoke_ref'=>null,'retention_ref'=>null,'continuity_ref'=>null,'evidence_ref'=>null],
    ];
    return array_replace_recursive($base,$o);
}
function input(array $o=[]): array { return array_replace([
    'class'=>'watch','title'=>'Vendor renewal approaching','summary'=>'A governed vendor exception requires review.',
    'impact'=>'Potential continuity or commercial impact if left unresolved.','actor_ref'=>null,
    'required_authority_level'=>null,'decision_ref'=>null,'options_ref'=>null,'deadline_at'=>null,
],$o); }
function signalRef(array $v,string $type,int $now=1600,int $horizon=500): string {
    foreach(VendorExceptionSignal::project($v,$now,$horizon) as $signal)
        if($signal['type']===$type) return $signal['signal_ref'];
    throw new RuntimeException('signal not emitted');
}
function bridge(array $v,string $type,array $in=[],int $now=1600,int $horizon=500): array {
    return VendorOwnerInboxProjection::project($v,$now,$horizon,signalRef($v,$type,$now,$horizon),input($in));
}

$case=$argv[1]??'';
if($case==='material'){
    $v=vendor(['health'=>'degraded']);
    $real=signalRef($v,'health_degraded');
    $out=[
        'entry'=>VendorOwnerInboxProjection::project($v,1600,500,$real,input()),
        'fake'=>bad(fn()=>VendorOwnerInboxProjection::project($v,1600,500,'vendor-exception:'.str_repeat('f',64),input())),
    ];
}elseif($case==='explicit'){
    $v=vendor(['criticality'=>'critical','health'=>'degraded']);
    $watch=bridge($v,'health_degraded');
    $decision=bridge($v,'health_degraded',[
        'class'=>'decision','required_authority_level'=>'L4_OWNER',
        'decision_ref'=>'controlbot:decision/vendor-review','options_ref'=>'controlbot:options/vendor-review',
        'deadline_at'=>2200,
    ]);
    $out=['watch'=>$watch,'decision'=>$decision];
}elseif($case==='provenance'){
    $entry=bridge(vendor(),'renewal_due');
    $out=['entry'=>$entry,'payload'=>json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)];
}elseif($case==='freshness'){
    $unknown=vendor([
        'criticality'=>'critical','health'=>'unknown','sla_state'=>'unknown',
        'freshness'=>['state'=>'unknown','observed_at'=>null,'source_ref'=>null],
        'renewal_at'=>null,'expiry_at'=>null,
    ]);
    $stale=vendor([
        'criticality'=>'critical','health'=>'unknown','sla_state'=>'unknown',
        'freshness'=>['state'=>'stale','observed_at'=>1500,'source_ref'=>'evidence:cccccccccccccccccccccccccccccccc'],
        'renewal_at'=>null,'expiry_at'=>null,
    ]);
    $out=['unknown'=>bridge($unknown,'critical_unknown'),'stale'=>bridge($stale,'critical_unknown')];
}elseif($case==='classes'){
    $v=vendor();
    $ref=signalRef($v,'renewal_due');
    $out=[
        'watch'=>VendorOwnerInboxProjection::project($v,1600,500,$ref,input()),
        'watch_authority'=>bad(fn()=>VendorOwnerInboxProjection::project($v,1600,500,$ref,input(['required_authority_level'=>'L4_OWNER']))),
        'decision_missing'=>bad(fn()=>VendorOwnerInboxProjection::project($v,1600,500,$ref,input(['class'=>'decision']))),
        'critical'=>VendorOwnerInboxProjection::project($v,1600,500,$ref,input(['class'=>'critical','required_authority_level'=>'L4_OWNER'])),
    ];
}elseif($case==='pure'){
    $r=new ReflectionClass(VendorOwnerInboxProjection::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===VendorOwnerInboxProjection::class
    ));
    sort($methods,SORT_STRING);$out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/VendorOwnerInboxProjection.php')];
}else{fwrite(STDERR,"Unknown vendor owner inbox projection scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

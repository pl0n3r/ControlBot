<?php
declare(strict_types=1);

require __DIR__.'/../src/VendorRegistry.php';

use ControlBot\Vendors\VendorRegistry;

const VENTURE='venture-condor';
function ref(string $ns,string $char): string { return $ns.':'.str_repeat($char,32); }
function vendor(string $venture=VENTURE): array {
    return [
        'version'=>1,'vendor_id'=>ref('vendor','1'),'venture_id'=>$venture,'category'=>'saas',
        'service_ref'=>ref('service','2'),'owner_ref'=>ref('identity','3'),'lifecycle'=>'active',
        'cost'=>['venture_id'=>$venture,'amount'=>120000,'currency'=>'COP','billing_cadence'=>'monthly','capital_ref'=>ref('capital','4')],
        'contract_ref'=>ref('contract','5'),'data_ref'=>ref('data','6'),
        'subprocessor_refs'=>[ref('subprocessor','7')],'credentials_ref'=>ref('credential','8'),
        'criticality'=>'high','exit_plan_ref'=>ref('exit','9'),'export_ref'=>ref('export','a'),
        'sla_state'=>'healthy',
        'security_review'=>['venture_id'=>$venture,'state'=>'approved','aegis_review_ref'=>ref('aegis','b')],
        'legal_review'=>['venture_id'=>$venture,'state'=>'approved','lex_review_ref'=>ref('lex','c')],
        'renewal_at'=>1800000000,'expiry_at'=>1900000000,'health'=>'healthy',
        'freshness'=>['state'=>'fresh','observed_at'=>1700000000,'source_ref'=>ref('evidence','d')],
        'offboarding'=>[
            'export_ref'=>ref('export','e'),'revoke_ref'=>ref('revoke','f'),
            'retention_ref'=>ref('retention','1'),'continuity_ref'=>ref('continuity','2'),
            'evidence_ref'=>ref('evidence','3'),
        ],
    ];
}
function blocked(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }

$case=$argv[1]??'';
if($case==='scope'){
    $cost=vendor(); $cost['cost']['venture_id']='venture-brvtal';
    $security=vendor(); $security['security_review']['venture_id']='venture-brvtal';
    echo json_encode([
        'valid'=>VendorRegistry::normalize(vendor()),
        'cost_cross'=>blocked(fn()=>VendorRegistry::normalize($cost)),
        'security_cross'=>blocked(fn()=>VendorRegistry::normalize($security)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='authority'){
    $row=VendorRegistry::normalize(vendor());
    echo json_encode(['cost'=>$row['cost'],'security'=>$row['security_review'],'legal'=>$row['legal_review']],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='privacy'){
    $human=vendor(); $human['owner_ref']='John_Doe';
    $credential=vendor(); $credential['credentials_ref']='credential:plain-value';
    echo json_encode([
        'human_rejected'=>blocked(fn()=>VendorRegistry::normalize($human)),
        'credential_rejected'=>blocked(fn()=>VendorRegistry::normalize($credential)),
        'valid'=>VendorRegistry::normalize(vendor()),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='states'){
    $stale=vendor(); $stale['freshness']['state']='stale'; $stale['health']='degraded'; $stale['sla_state']='degraded';
    $unknown=vendor(); $unknown['freshness']=['state'=>'unknown','observed_at'=>null,'source_ref'=>null]; $unknown['health']='unknown'; $unknown['sla_state']='unknown';
    $falseHealthy=$unknown; $falseHealthy['health']='healthy';
    $falseSla=$unknown; $falseSla['sla_state']='healthy';
    $badDates=vendor(); $badDates['renewal_at']=1950000000;
    echo json_encode([
        'stale'=>VendorRegistry::normalize($stale),'unknown'=>VendorRegistry::normalize($unknown),
        'false_healthy_rejected'=>blocked(fn()=>VendorRegistry::normalize($falseHealthy)),
        'false_sla_rejected'=>blocked(fn()=>VendorRegistry::normalize($falseSla)),
        'bad_dates_rejected'=>blocked(fn()=>VendorRegistry::normalize($badDates)),
    ],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='offboarding'){
    $row=vendor(); $row['lifecycle']='offboarding';
    echo json_encode(VendorRegistry::normalize($row),JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
if($case==='surface'){
    $source=file_get_contents(__DIR__.'/../src/VendorRegistry.php');
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),(new ReflectionClass(VendorRegistry::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    echo json_encode(['public'=>$methods,'source'=>$source],JSON_THROW_ON_ERROR),PHP_EOL; exit;
}
fwrite(STDERR,"Unknown vendor registry scenario\n"); exit(2);

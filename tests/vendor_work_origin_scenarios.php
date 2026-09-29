<?php
declare(strict_types=1);
require __DIR__.'/../src/VendorRegistry.php';
require __DIR__.'/../src/VendorWorkOrigin.php';

use ControlBot\Vendors\VendorWorkOrigin;

const VENTURE='venture-condor';
function vwRef(string $ns,string $char): string { return $ns.':'.str_repeat($char,32); }
function vwVendor(string $venture=VENTURE): array {
    return [
        'version'=>1,'vendor_id'=>vwRef('vendor','1'),'venture_id'=>$venture,'category'=>'saas',
        'service_ref'=>vwRef('service','2'),'owner_ref'=>vwRef('identity','3'),'lifecycle'=>'active',
        'cost'=>['venture_id'=>$venture,'amount'=>120000,'currency'=>'COP','billing_cadence'=>'monthly','capital_ref'=>vwRef('capital','4')],
        'contract_ref'=>vwRef('contract','5'),'data_ref'=>vwRef('data','6'),
        'subprocessor_refs'=>[vwRef('subprocessor','7')],'credentials_ref'=>vwRef('credential','8'),
        'criticality'=>'critical','exit_plan_ref'=>vwRef('exit','9'),'export_ref'=>vwRef('export','a'),
        'sla_state'=>'degraded',
        'security_review'=>['venture_id'=>$venture,'state'=>'pending','aegis_review_ref'=>vwRef('aegis','b')],
        'legal_review'=>['venture_id'=>$venture,'state'=>'rejected','lex_review_ref'=>vwRef('lex','c')],
        'renewal_at'=>1800000000,'expiry_at'=>1900000000,'health'=>'degraded',
        'freshness'=>['state'=>'fresh','observed_at'=>1700000000,'source_ref'=>vwRef('evidence','d')],
        'offboarding'=>[
            'export_ref'=>vwRef('export','e'),'revoke_ref'=>vwRef('revoke','f'),
            'retention_ref'=>vwRef('retention','1'),'continuity_ref'=>vwRef('continuity','2'),
            'evidence_ref'=>vwRef('evidence','3'),
        ],
    ];
}
function vwIntent(array $o=[]): array {
    return array_replace([
        'version'=>1,'work_id'=>'work-vendor','group_id'=>'pl0n3r-group','work_type'=>'operations',
        'requested_capabilities'=>['vendor_remediation'],'required_roles'=>['sre','seguridad'],
        'authority_level'=>'operational','priority_class'=>'high','depends_on'=>[],'claims'=>[],
        'policy_ref'=>'factory:constitution-v1',
    ],$o);
}
function vwRejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }

$case=$argv[1]??'';
if($case==='scope'){
    $risk=VendorWorkOrigin::fromRisk(vwIntent(),vwVendor());
    $lifecycle=vwVendor(); $lifecycle['lifecycle']='offboarding';
    $life=VendorWorkOrigin::fromLifecycle(vwIntent(['work_id'=>'work-vendor-offboarding']),$lifecycle);
    $out=['risk'=>$risk,'lifecycle'=>$life];
}elseif($case==='explicit'){
    $first=VendorWorkOrigin::fromRisk(vwIntent(),vwVendor());
    $second=VendorWorkOrigin::fromRisk(vwIntent([
        'work_id'=>'work-vendor-other','work_type'=>'compliance_review',
        'requested_capabilities'=>['vendor_review'],'required_roles'=>['legal-privacidad'],
        'authority_level'=>'owner','priority_class'=>'medium','policy_ref'=>'factory:vendor-policy',
    ]),vwVendor());
    $out=['first'=>$first,'second'=>$second];
}elseif($case==='idempotency'){
    $a=VendorWorkOrigin::fromRisk(vwIntent([
        'requested_capabilities'=>['vendor_remediation','audit'],
        'required_roles'=>['seguridad','sre'],'claims'=>['vendor:1','service:2'],
    ]),vwVendor());
    $b=VendorWorkOrigin::fromRisk(vwIntent([
        'work_id'=>'work-vendor-2',
        'requested_capabilities'=>['audit','vendor_remediation'],
        'required_roles'=>['sre','seguridad'],'claims'=>['service:2','vendor:1'],
    ]),vwVendor());
    $out=['a'=>$a,'b'=>$b];
}elseif($case==='limited'){
    $stale=vwVendor(); $stale['freshness']['state']='stale';
    $staleOut=VendorWorkOrigin::fromRisk(vwIntent(),$stale);
    $unknown=vwVendor();
    $unknown['freshness']=['state'=>'unknown','observed_at'=>null,'source_ref'=>null];
    $unknown['health']='unknown'; $unknown['sla_state']='unknown';
    $unknown['security_review']=['venture_id'=>VENTURE,'state'=>'unknown','aegis_review_ref'=>null];
    $unknown['legal_review']=['venture_id'=>VENTURE,'state'=>'unknown','lex_review_ref'=>null];
    $out=[
        'stale'=>$staleOut,
        'unknown_rejected'=>vwRejected(fn()=>VendorWorkOrigin::fromRisk(vwIntent(),$unknown)),
    ];
}elseif($case==='optional'){
    $good=VendorWorkOrigin::fromRisk(vwIntent([
        'project_id'=>'controlbot','repository_ref'=>'pl0n3r/ControlBot',
        'budget_ref'=>'capital:vendor-budget','approval_ref'=>'owner-decision:275',
    ]),vwVendor());
    $bad=vwIntent(); $bad['provider']='saas-provider';
    $out=['good'=>$good,'bad_execution_field'=>vwRejected(fn()=>VendorWorkOrigin::fromRisk($bad,vwVendor()))];
}elseif($case==='pure'){
    $r=new ReflectionClass(VendorWorkOrigin::class);
    $methods=array_values(array_map(static fn(ReflectionMethod $m):string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m):bool=>$m->getDeclaringClass()->getName()===VendorWorkOrigin::class
    )));
    sort($methods,SORT_STRING);
    $active=vwVendor();
    $out=[
        'methods'=>$methods,
        'active_lifecycle_rejected'=>vwRejected(fn()=>VendorWorkOrigin::fromLifecycle(vwIntent(),$active)),
    ];
}else{fwrite(STDERR,"Unknown vendor work origin scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

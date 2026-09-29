<?php
declare(strict_types=1);
require __DIR__.'/../src/VendorRegistry.php';
require __DIR__.'/../src/VendorWorkOrigin.php';
use ControlBot\Vendors\VendorWorkOrigin;

const VENTURE='venture-condor';

function vendor(array $override=[]): array {
    $script=__DIR__.'/vendor_registry_scenarios.php';
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' scope';
    $lines=[]; $status=0;
    exec($command,$lines,$status);
    if($status!==0 || $lines===[]) throw new RuntimeException('Canonical vendor fixture failed.');
    $payload=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
    $vendor=$payload['valid']??null;
    if(!is_array($vendor)) throw new RuntimeException('Canonical vendor fixture invalid.');
    foreach($override as $key=>$value) $vendor[$key]=$value;
    return $vendor;
}
function intent(array $override=[]): array {
    return array_replace([
        'version'=>1,'work_id'=>'work-vendor','group_id'=>'pl0n3r-group','work_type'=>'operations',
        'requested_capabilities'=>['vendor_remediation'],'required_roles'=>['sre','seguridad'],
        'authority_level'=>'operational','priority_class'=>'high','depends_on'=>[],'claims'=>[],
        'policy_ref'=>'factory:constitution-v1',
    ],$override);
}
function rejects(callable $call): bool { try{$call();return false;}catch(InvalidArgumentException){return true;} }

$case=$argv[1]??'';
switch($case){
case 'scope':
    $life=vendor(['lifecycle'=>'offboarding']);
    $out=['risk'=>VendorWorkOrigin::fromRisk(intent(),vendor()),
          'lifecycle'=>VendorWorkOrigin::fromLifecycle(intent(['work_id'=>'work-offboard']),$life)];
    break;
case 'explicit':
    $out=['first'=>VendorWorkOrigin::fromRisk(intent(),vendor()),
          'second'=>VendorWorkOrigin::fromRisk(intent([
              'work_id'=>'work-review','work_type'=>'compliance_review','requested_capabilities'=>['vendor_review'],
              'required_roles'=>['legal-privacidad'],'authority_level'=>'owner','priority_class'=>'medium',
              'policy_ref'=>'factory:vendor-policy',
          ]),vendor())];
    break;
case 'idempotency':
    $out=['a'=>VendorWorkOrigin::fromRisk(intent([
              'requested_capabilities'=>['vendor_remediation','audit'],'required_roles'=>['seguridad','sre'],
              'claims'=>['vendor:1','service:2'],
          ]),vendor()),
          'b'=>VendorWorkOrigin::fromRisk(intent([
              'work_id'=>'work-vendor-2','requested_capabilities'=>['audit','vendor_remediation'],
              'required_roles'=>['sre','seguridad'],'claims'=>['service:2','vendor:1'],
          ]),vendor())];
    break;
case 'limited':
    $stale=vendor(); $stale['freshness']['state']='stale';
    $unknown=vendor([
        'freshness'=>['state'=>'unknown','observed_at'=>null,'source_ref'=>null],
        'health'=>'unknown','sla_state'=>'unknown',
        'security_review'=>['venture_id'=>VENTURE,'state'=>'unknown','aegis_review_ref'=>null],
        'legal_review'=>['venture_id'=>VENTURE,'state'=>'unknown','lex_review_ref'=>null],
    ]);
    $out=['stale'=>VendorWorkOrigin::fromRisk(intent(),$stale),
          'unknown_rejected'=>rejects(fn()=>VendorWorkOrigin::fromRisk(intent(),$unknown))];
    break;
case 'optional':
    $good=intent(['project_id'=>'controlbot','repository_ref'=>'pl0n3r/ControlBot',
                  'budget_ref'=>'capital:vendor-budget','approval_ref'=>'owner-decision:275']);
    $bad=intent(); $bad['provider']='saas-provider';
    $out=['good'=>VendorWorkOrigin::fromRisk($good,vendor()),
          'bad_execution_field'=>rejects(fn()=>VendorWorkOrigin::fromRisk($bad,vendor()))];
    break;
case 'pure':
    $public=array_map(static fn(ReflectionMethod $m):string=>$m->getName(),array_filter(
        (new ReflectionClass(VendorWorkOrigin::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m):bool=>$m->getDeclaringClass()->getName()===VendorWorkOrigin::class));
    sort($public,SORT_STRING);
    $out=['methods'=>$public,'active_lifecycle_rejected'=>rejects(fn()=>VendorWorkOrigin::fromLifecycle(intent(),vendor()))];
    break;
default:
    fwrite(STDERR,"Unknown vendor work origin scenario\n"); exit(2);
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

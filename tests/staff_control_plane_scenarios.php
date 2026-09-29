<?php
declare(strict_types=1);
require __DIR__.'/../src/StaffControlPlane.php';
use ControlBot\Staff\StaffControlPlane;

function summary(array $x=[]): array { return array_replace([
 'version'=>1,'project_id'=>'controlbot','population'=>'staff_only','active_staff'=>2,'suspended_staff'=>1,
 'failed_logins_summary'=>3,'source_ref'=>'product-staff/controlbot','observed_at'=>1000,'freshness'=>'fresh','admin_ref'=>'admin/controlbot'
],$x); }
function records(): array { return [[
 'staff_id'=>'staff-alpha','account_type'=>'staff','display_name'=>'Ada Operator','email'=>'ada@example.com',
 'role'=>'operator','status'=>'active','last_access_at'=>990,'mfa_state'=>'satisfied'
],[
 'staff_id'=>'staff-owner','account_type'=>'admin','display_name'=>'Owner Admin','email'=>'owner@example.org',
 'role'=>'admin','status'=>'suspended','last_access_at'=>null,'mfa_state'=>'required'
]]; }
function search(array $x=[]): array { return array_replace([
 'version'=>1,'project_id'=>'controlbot','population'=>'staff_only','records'=>records(),
 'source_ref'=>'product-staff/controlbot','observed_at'=>1000,'freshness'=>'fresh'
],$x); }
function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
$case=$argv[1]??'';
if($case==='summary'){
 echo json_encode(['valid'=>StaffControlPlane::summary(summary(),'controlbot'),
  'customer_boundary'=>bad(fn()=>StaffControlPlane::summary(summary(['population'=>'all_accounts']),'controlbot'))],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='search'){
 $out=StaffControlPlane::search(search(),'controlbot');
 $quoted=records();$quoted[0]['email']='"a@private-name"@example.com';
 $quotedOut=StaffControlPlane::search(search(['records'=>$quoted]),'controlbot');
 echo json_encode(['valid'=>$out,'raw_email_leaked'=>str_contains(json_encode($out,JSON_THROW_ON_ERROR),'ada@example.com'),
  'quoted_masked'=>$quotedOut['records'][0]['masked_email']],JSON_THROW_ON_ERROR),PHP_EOL;
}elseif($case==='invalid'){
 $customer=records();$customer[0]['account_type']='customer';
 $extra=records();$extra[0]['password']='nope';
 echo json_encode([
  'customer'=>bad(fn()=>StaffControlPlane::search(search(['records'=>$customer]),'controlbot')),
  'ambiguous'=>bad(fn()=>StaffControlPlane::search(search(['population'=>'unknown']),'controlbot')),
  'cross_project'=>bad(fn()=>StaffControlPlane::search(search(),'condor')),
  'extra_sensitive'=>bad(fn()=>StaffControlPlane::search(search(['records'=>$extra]),'controlbot')),
 ],JSON_THROW_ON_ERROR),PHP_EOL;
}else{fwrite(STDERR,"scenario invalid\n");exit(2);}

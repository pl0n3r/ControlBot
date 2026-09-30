<?php
declare(strict_types=1);
require __DIR__.'/../src/DisasterRecoveryPolicy.php';
use ControlBot\Infrastructure\DisasterRecoveryPolicy;

function policy(array $o=[]):array{return array_replace_recursive([
 'version'=>1,'project_id'=>'controlbot','rpo_seconds'=>900,'rto_seconds'=>3600,
 'retention'=>['recent'=>24,'daily'=>7,'weekly'=>8,'monthly'=>12],
 'strategies'=>['database'=>'consistent_backup','media'=>'versioned_backup','code'=>'repository_mirror','secrets'=>'vault_reference_only'],
 'capabilities'=>['offsite'=>true,'versioned_or_immutable'=>true,'checksum_required'=>true,'freshness_required'=>true,'restore_drill_required'=>true],
 'destinations'=>[['provider'=>'object_store','role'=>'versioned_or_immutable_copy'],['provider'=>'google_drive','role'=>'offsite_encrypted_copy']],
 'policy_ref'=>'controlbot:dr-policy/controlbot/v1',
 'provenance_refs'=>['controlbot:source/aegis-182','controlbot:source/factory-305'],
],$o);}
function rejected(callable $f):array{try{$f();return['blocked'=>false];}catch(InvalidArgumentException){return['blocked'=>true];}}
function without(string $k):array{$r=policy();unset($r[$k]);return$r;}

$c=$argv[1]??'';
$out=match($c){
 'valid'=>['policy'=>DisasterRecoveryPolicy::normalize(policy()),'fingerprint'=>DisasterRecoveryPolicy::fingerprint(policy())],
 'evidence'=>[
  'missing'=>DisasterRecoveryPolicy::evidenceStatus(null),
  'stale_healthy'=>DisasterRecoveryPolicy::evidenceStatus(['state'=>'healthy','freshness'=>'stale','complete'=>true]),
  'incomplete_healthy'=>DisasterRecoveryPolicy::evidenceStatus(['state'=>'healthy','freshness'=>'fresh','complete'=>false]),
  'unknown'=>DisasterRecoveryPolicy::evidenceStatus(['state'=>'unknown','freshness'=>'unknown','complete'=>false]),
  'blocked'=>DisasterRecoveryPolicy::evidenceStatus(['state'=>'blocked','freshness'=>'stale','complete'=>false]),
  'healthy'=>DisasterRecoveryPolicy::evidenceStatus(['state'=>'healthy','freshness'=>'fresh','complete'=>true]),
 ],
 'bad-rpo'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['rpo_seconds'=>0]))),
 'bad-rto'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['rto_seconds'=>-1]))),
 'empty-retention'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['retention'=>['recent'=>0,'daily'=>0,'weekly'=>0,'monthly'=>0]]))),
 'missing-field'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(without('rpo_seconds'))),
 'extra-field'=>rejected(fn()=>DisasterRecoveryPolicy::normalize([...policy(),'extra'=>'x'])),
 'bad-secret-strategy'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['strategies'=>['secrets'=>'consistent_backup']]))),
 'drive-primary'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['destinations'=>[['provider'=>'google_drive','role'=>'primary_runtime_storage']]]))),
 'drive-server'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['destinations'=>[['provider'=>'google_drive','role'=>'server_automation_dependency']]]))),
 'icloud-primary'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['destinations'=>[['provider'=>'icloud','role'=>'primary_runtime_storage']]]))),
 'icloud-server'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['destinations'=>[['provider'=>'icloud','role'=>'server_automation_dependency']]]))),
 'duplicate-destination'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['destinations'=>[
  ['provider'=>'google_drive','role'=>'offsite_encrypted_copy'],['provider'=>'google_drive','role'=>'offsite_encrypted_copy']]]))),
 'duplicate-provenance'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['provenance_refs'=>['controlbot:source/a','controlbot:source/a']]))),
 'sensitive-ref'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['provenance_refs'=>['x?token=abc']]))),
 'bad-policy-project'=>rejected(fn()=>DisasterRecoveryPolicy::normalize(policy(['policy_ref'=>'controlbot:dr-policy/other/v1']))),
 'permuted'=>['base'=>DisasterRecoveryPolicy::fingerprint(policy()),'permuted'=>DisasterRecoveryPolicy::fingerprint([
  'provenance_refs'=>['controlbot:source/factory-305','controlbot:source/aegis-182'],
  'policy_ref'=>'controlbot:dr-policy/controlbot/v1',
  'destinations'=>[['role'=>'offsite_encrypted_copy','provider'=>'google_drive'],['role'=>'versioned_or_immutable_copy','provider'=>'object_store']],
  'capabilities'=>['restore_drill_required'=>true,'freshness_required'=>true,'checksum_required'=>true,'versioned_or_immutable'=>true,'offsite'=>true],
  'strategies'=>['secrets'=>'vault_reference_only','code'=>'repository_mirror','media'=>'versioned_backup','database'=>'consistent_backup'],
  'retention'=>['monthly'=>12,'weekly'=>8,'daily'=>7,'recent'=>24],
  'rto_seconds'=>3600,'rpo_seconds'=>900,'project_id'=>'controlbot','version'=>1,
 ])],
 default=>throw new InvalidArgumentException('scenario'),
};
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

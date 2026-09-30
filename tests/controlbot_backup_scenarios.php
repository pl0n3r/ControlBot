<?php
declare(strict_types=1);
require __DIR__.'/../src/ControlBotBackup.php';

use ControlBot\Backup\ControlBotBackup;

function rejected(callable $fn): bool {try{$fn();return false;}catch(InvalidArgumentException){return true;}}
function backup(string $id='backup-001',int $start=100,int $done=110,array $x=[]): array {return array_replace([
    'version'=>1,'backup_id'=>$id,'project'=>'controlbot','environment'=>'production','resource'=>'database:primary',
    'started_at'=>$start,'completed_at'=>$done,'checksum'=>hash('sha256',$id),'size_bytes'=>4096,
    'storage_ref'=>'backup:controlbot/'.$id,'encryption'=>'kms-managed','status'=>'completed',
],$x);}
function restore(array $b,string $id='restore-001',int $start=120,int $done=130,array $x=[]): array {return array_replace([
    'version'=>1,'restore_id'=>$id,'backup_id'=>$b['backup_id'],'source_checksum'=>$b['checksum'],
    'destination_checksum'=>$b['checksum'],'target_environment'=>'restore-drill','started_at'=>$start,'completed_at'=>$done,
    'status'=>'passed','evidence_ref'=>'evidence:restore/'.$id,
],$x);}

$case=$argv[1]??'';
if($case==='backup'){
    $b=backup();
    echo json_encode([
        'valid'=>ControlBotBackup::backupReceipt($b),
        'size_rejected'=>rejected(fn()=>ControlBotBackup::backupReceipt(backup(x:['size_bytes'=>0]))),
        'url_rejected'=>rejected(fn()=>ControlBotBackup::backupReceipt(backup(x:['storage_ref'=>'https://signed.example/backup?token=abc']))),
        'secret_rejected'=>rejected(fn()=>ControlBotBackup::backupReceipt(backup(x:['storage_ref'=>'backup:token=abc']))),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='restore'){
    $b=backup();
    echo json_encode([
        'valid'=>ControlBotBackup::restoreReceipt($b,restore($b)),
        'production_rejected'=>rejected(fn()=>ControlBotBackup::restoreReceipt($b,restore($b,x:['target_environment'=>'production']))),
        'checksum_rejected'=>rejected(fn()=>ControlBotBackup::restoreReceipt($b,restore($b,x:['destination_checksum'=>str_repeat('a',64)]))),
        'before_backup_rejected'=>rejected(fn()=>ControlBotBackup::restoreReceipt($b,restore($b,start:105,done:115))),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='restorable'){
    $b=backup();$r=restore($b);
    $bad=restore($b,x:['destination_checksum'=>str_repeat('a',64)]);
    echo json_encode([
        'verified'=>ControlBotBackup::restorable($b,$r),
        'missing'=>ControlBotBackup::restorable($b,null),
        'invalid'=>ControlBotBackup::restorable($b,$bad),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='retention'){
    $b1=backup('backup-001',100,110);$b2=backup('backup-002',200,210);$b3=backup('backup-003',300,310);
    $other=backup('backup-004',400,410,['resource'=>'config:runtime']);
    $r2=restore($b2,'restore-002',220,230);
    echo json_encode([
        'plan'=>ControlBotBackup::retentionPlan([$b3,$other,$b1,$b2],[$r2],1),
        'ambiguous_rejected'=>rejected(fn()=>ControlBotBackup::retentionPlan([$b1,$b2],[$r2,$r2],1)),
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;exit;
}
if($case==='pure'){
    $source=strtolower((string)file_get_contents(__DIR__.'/../src/ControlBotBackup.php'));
    $forbidden=['new pdo','mysqli','curl_','file_put_contents','fopen(','unlink(','shell_exec','proc_open','passthru(','system(','exec(','cron','productionauthority'];
    $hits=[];foreach($forbidden as $needle)if(str_contains($source,$needle))$hits[]=$needle;
    echo json_encode(['hits'=>$hits],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown backup scenario\n");exit(2);

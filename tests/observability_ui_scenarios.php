<?php
declare(strict_types=1);
require __DIR__.'/../src/ExternalMonitorCore.php';
require __DIR__.'/../src/ControlBotBackup.php';
require __DIR__.'/../src/ObservabilityUi.php';

use ControlBot\Observability\ExternalMonitorCore;
use ControlBot\Observability\ObservabilityUi;

function backupRow(): array {return [
    'version'=>1,'backup_id'=>'backup:controlbot-001','project'=>'controlbot','environment'=>'production','resource'=>'mariadb:primary',
    'started_at'=>900,'completed_at'=>920,'checksum'=>str_repeat('a',64),'size_bytes'=>1048576,
    'storage_ref'=>'vault:controlbot/backup-001','encryption'=>'kms-managed','status'=>'completed',
];}
function restoreRow(): array {return [
    'version'=>1,'restore_id'=>'restore:controlbot-001','backup_id'=>'backup:controlbot-001',
    'source_checksum'=>str_repeat('a',64),'destination_checksum'=>str_repeat('a',64),'target_environment'=>'restore-lab',
    'started_at'=>930,'completed_at'=>950,'status'=>'passed','evidence_ref'=>'evidence:restore/controlbot-001',
];}
function monitor(bool $down=false): array {
    $probe=ExternalMonitorCore::probe([
        'version'=>1,'endpoint_kind'=>'health','observed_at'=>1000,'outcome'=>$down?'timeout':'response',
        'http_status'=>$down?null:200,'latency_ms'=>$down?1000:42,'reported_version'=>$down?null:'1.4.0',
        'reported_sha'=>$down?null:str_repeat('b',40),'freshness'=>'fresh','source_ref'=>'external:probe/controlbot',
    ]);
    return ExternalMonitorCore::assess($probe,[],null,1000,300);
}
function unknownMonitor(): array {
    $probe=ExternalMonitorCore::probe([
        'version'=>1,'endpoint_kind'=>'health','observed_at'=>0,'outcome'=>'unknown','http_status'=>null,'latency_ms'=>null,
        'reported_version'=>null,'reported_sha'=>null,'freshness'=>'unknown','source_ref'=>'external:probe/bootstrap',
    ]);
    return ExternalMonitorCore::assess($probe,[],null,0,300);
}
$case=$argv[1]??'';
if($case==='complete'){echo ObservabilityUi::render(monitor(),backupRow(),restoreRow()),PHP_EOL;exit;}
if($case==='missing'){echo ObservabilityUi::render(null,null,null),PHP_EOL;exit;}
if($case==='orphan_restore'){echo ObservabilityUi::render(monitor(),null,restoreRow()),PHP_EOL;exit;}
if($case==='unknown_probe'){echo ObservabilityUi::render(unknownMonitor(),null,null),PHP_EOL;exit;}
if($case==='no_restore'){echo ObservabilityUi::render(monitor(),backupRow(),null),PHP_EOL;exit;}
if($case==='invalid_restore'){
    $restore=restoreRow();$restore['destination_checksum']=str_repeat('c',64);
    echo ObservabilityUi::render(monitor(),backupRow(),$restore),PHP_EOL;exit;
}
if($case==='alert'){echo ObservabilityUi::render(monitor(true),backupRow(),restoreRow()),PHP_EOL;exit;}
if($case==='redaction'){
    $html=ObservabilityUi::render(monitor(),backupRow(),restoreRow());
    echo json_encode([
        'storage_ref_absent'=>!str_contains($html,'vault:controlbot/backup-001'),
        'checksum_absent'=>!str_contains($html,str_repeat('a',64)),
        'evidence_ref_absent'=>!str_contains($html,'evidence:restore/controlbot-001'),
        'no_http_links'=>!str_contains(strtolower($html),'http://')&&!str_contains(strtolower($html),'https://'),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='pure'){
    $source=strtolower((string)file_get_contents(__DIR__.'/../src/ObservabilityUi.php'));
    $hits=[];foreach(['curl_','new pdo','mysqli','file_put_contents','fopen(','shell_exec','proc_open','passthru(','system(','externalmonitorcore::assess','controlbotbackup::retentionplan'] as $needle)if(str_contains($source,$needle))$hits[]=$needle;
    $html=ObservabilityUi::render(monitor(),backupRow(),restoreRow());
    echo json_encode(['hits'=>$hits,'form'=>str_contains($html,'<form'),'button'=>str_contains($html,'<button'),'input'=>str_contains($html,'<input')],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"unknown observability ui scenario\n");exit(2);

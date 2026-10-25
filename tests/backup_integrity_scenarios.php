<?php
declare(strict_types=1);
require __DIR__ . '/../src/BackupReceipt.php';
use ControlBot\Production\BackupReceipt;
const PAYLOAD = 'synthetic-backup-data-only/no-real-records';
const VERIFIED_AT = '2027-01-15T07:01:00Z';
const CHECKED_AT = '2027-01-15T07:02:00Z';
function fixture(array $replace=[]): array {
    return array_replace_recursive([
        'version'=>1,
        'receipt_id'=>'11111111-2222-4333-8444-555555555555',
        'project'=>'controlbot','environment'=>'testing','resource'=>'database:synthetic',
        'backup_type'=>'database_dump','run_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'issue'=>'pl0n3r/ControlBot#898','created_at'=>'2027-01-15T07:00:00Z',
        'verified_at'=>VERIFIED_AT,'status'=>'ready',
        'artifact_ref'=>'artifact:synthetic:sha256-proof',
        'integrity'=>['algorithm'=>'sha256','digest'=>hash('sha256',PAYLOAD)],
        'restore_capability'=>'unknown','expires_at'=>null,
    ],$replace);
}
function scope(array $replace=[]): array {
    return array_replace([
        'project'=>'controlbot','environment'=>'testing','resource'=>'database:synthetic',
        'run_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'issue'=>'pl0n3r/ControlBot#898',
    ],$replace);
}
function rejected(callable $cb): bool {
    try { $cb(); } catch (\InvalidArgumentException) { return true; }
    return false;
}
function verify(array $change=[],string $payload=PAYLOAD,array $scope=[],?int $time=null): array {
    return BackupReceipt::fromRecord(fixture($change))->verifyPayloadChecksumBefore(
        $payload,scope($scope),$time??strtotime(CHECKED_AT)
    );
}
$mode=$argv[1]??'';
$out=match($mode) {
    'shape'=>[
        'valid'=>!rejected(fn()=>BackupReceipt::fromRecord(fixture())),
        'uppercase'=>!rejected(fn()=>BackupReceipt::fromRecord(fixture(['integrity'=>['digest'=>strtoupper(hash('sha256',PAYLOAD))]]))),
        'short'=>verify(['integrity'=>['digest'=>str_repeat('a',16)]]),
        'non_hex'=>verify(['integrity'=>['digest'=>str_repeat('g',64)]]),
        'long'=>verify(['integrity'=>['digest'=>str_repeat('b',65)]]),
    ],
    'content'=>[
        'match'=>verify(),'mismatch'=>verify([],PAYLOAD.'-changed'),
        'uppercase'=>verify(['integrity'=>['digest'=>strtoupper(hash('sha256',PAYLOAD))]]),
    ],
    'guard'=>[
        'scope'=>verify([],PAYLOAD,['project'=>'condor']),
        'premature'=>verify([],PAYLOAD,[],strtotime(VERIFIED_AT)),
        'expired'=>verify(['expires_at'=>'2027-01-15T07:01:30Z']),
        'stale'=>verify([],PAYLOAD,[],strtotime(VERIFIED_AT)+86401),
        'invalid_scope'=>rejected(fn()=>verify([],PAYLOAD,['project'=>'@bad'])),
        'invalid_time'=>rejected(fn()=>verify([],PAYLOAD,[],-10)),
    ],
    'privacy'=>[
        'result'=>verify(),'safe_receipt'=>BackupReceipt::fromRecord(fixture())->safeEvidence(),
    ],
    default=>throw new \InvalidArgumentException('Unknown synthetic scenario'),
};
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

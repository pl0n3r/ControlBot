<?php
declare(strict_types=1);

foreach(['ConnectionProfile','CapabilityPolicy','CapabilityGrant','HostingReadPanel'] as $f)
    require __DIR__.'/../src/'.$f.'.php';

use ControlBot\Production\{ConnectionProfile,CapabilityGrant,HostingReadPanel};

const FP='SHA256:QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo=';
const RUN='11111111-1111-4111-8111-111111111111';
function profile(string $project='alpha',string $env='prod',string $status='connected'): ConnectionProfile {
    return ConnectionProfile::fromServerRecord([
        'version'=>1,'profile_id'=>'22222222-2222-4222-8222-222222222222','project'=>$project,
        'environment'=>$env,'provider'=>'hostinger','transport'=>'ssh','host'=>$project.'.example.test','port'=>22,
        'username_ref'=>'user:hostinger','secret_ref'=>'secret:hostinger','host_fingerprint'=>FP,'status'=>$status,
        'verified_at'=>$status==='connected'?'2026-09-29T20:00:00+00:00':null,
        'last_health_at'=>'2026-09-29T20:00:00+00:00','revoked_at'=>$status==='revoked'?'2026-09-29T20:00:00+00:00':null,
    ]);
}
function grant(string $project='alpha',string $env='prod',string $cap='hostinger.read'): CapabilityGrant {
    return CapabilityGrant::issue([
        'version'=>1,'grant_id'=>'33333333-3333-4333-8333-333333333333','capability'=>$cap,
        'project'=>$project,'environment'=>$env,'resource'=>'hosting-panel','operation'=>'hosting.snapshot.read',
        'issue'=>'pl0n3r/ControlBot#15','run_id'=>RUN,'subject'=>'owner:pl0n3r',
        'issued_at'=>'2026-09-29T19:50:00Z','expires_at'=>'2026-09-29T20:50:00Z',
        'backup_receipt_id'=>null,'owner_approval_id'=>null,'idempotency_key'=>'hosting-read-alpha',
        'revoked_at'=>null,
    ]);
}
function raw(array $over=[]): array {
    return array_replace([
        'fingerprint'=>FP,'observed_at'=>1790713200,'source'=>'hostinger:ssh',
        'freshness'=>'fresh','site_status'=>'online','disk'=>'ok','databases'=>'ok',
        'cron'=>'ok','ssl'=>'ok','deploy_status'=>'ok','recent_errors'=>'ok',
    ],$over);
}
function panel(array $over=[]): HostingReadPanel {return new HostingReadPanel(fn(array $req)=>raw($over));}
function ctx(): array{return ['issue'=>'pl0n3r/ControlBot#15','run_id'=>RUN,'subject'=>'owner:pl0n3r'];}
function bad(callable $fn): bool {try{$fn();return false;}catch(\Throwable){return true;}}

$case=$argv[1]??'';
if($case==='connected'){
    $out=panel()->read(profile(),grant(),ctx(),1790713800);
}elseif($case==='identity'){
    $out=[
        'unavailable'=>bad(fn()=>panel()->read(profile(status:'unavailable'),grant(),ctx(),1790713800)),
        'revoked'=>bad(fn()=>panel()->read(profile(status:'revoked'),grant(),ctx(),1790713800)),
        'fingerprint'=>bad(fn()=>panel(['fingerprint'=>'SHA256:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXo='])->read(profile(),grant(),ctx(),1790713800)),
    ];
}elseif($case==='capability'){
    $out=['denied'=>bad(fn()=>panel()->read(profile(),grant(cap:'health.check'),ctx(),1790713800))];
}elseif($case==='partial'){
    $out=panel(['disk'=>'unknown','cron'=>'unknown'])->read(profile(),grant(),ctx(),1790713800);
}elseif($case==='secret'){
    $out=['rejected'=>bad(fn()=>panel(['source'=>'bearer-token'])->read(profile(),grant(),ctx(),1790713800))];
}elseif($case==='scopes'){
    $a=panel()->read(profile('alpha','prod'),grant('alpha','prod'),ctx(),1790713800);
    $b=panel()->read(profile('beta','stage'),grant('beta','stage'),ctx(),1790713800);
    $out=['rows'=>HostingReadPanel::aggregate([$b,$a]),'cross_denied'=>bad(fn()=>panel()->read(profile('beta','stage'),grant('alpha','prod'),ctx(),1790713800))];
}elseif($case==='source'){
    $out=['panel'=>file_get_contents(__DIR__.'/../src/HostingReadPanel.php')];
}else{fwrite(STDERR,"unknown scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

<?php
declare(strict_types=1);
require __DIR__.'/../src/ExternalApiSession.php';

use ControlBot\ExternalApi\ExternalApiSession;

function xr(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function xd(array $o=[]): array { return array_replace([
    'version'=>1,'device_ref'=>'device:11111111111111111111111111111111',
    'identity_id'=>'owner-human','registered_at'=>1000,'state'=>'active',
    'revoked_at'=>null,'revocation_reason'=>null,
],$o); }
function xs(array $o=[]): array { return array_replace([
    'version'=>1,'session_ref'=>'session:22222222222222222222222222222222',
    'device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'owner-human',
    'scope'=>'venture:alpha','issued_at'=>1100,'expires_at'=>2000,'state'=>'active',
    'revoked_at'=>null,'revocation_reason'=>null,
],$o); }
function xu(array $o=[]): array { return array_replace([
    'version'=>1,'step_up_ref'=>'stepup:33333333333333333333333333333333',
    'session_ref'=>'session:22222222222222222222222222222222',
    'device_ref'=>'device:11111111111111111111111111111111','identity_id'=>'owner-human',
    'method'=>'passkey','verified_at'=>1500,'expires_at'=>1750,'state'=>'active',
    'revoked_at'=>null,'revocation_reason'=>null,
],$o); }

$case=$argv[1]??'';
if($case==='binding'){
    $out=[
        'device'=>ExternalApiSession::device(xd(),'owner-human'),
        'session'=>ExternalApiSession::session(xs(),xd(),'owner-human','venture:alpha',1600),
        'wrong_identity'=>xr(fn()=>ExternalApiSession::session(xs(['identity_id'=>'other-human']),xd(),'owner-human','venture:alpha',1600)),
        'wrong_scope'=>xr(fn()=>ExternalApiSession::session(xs(),xd(),'owner-human','venture:beta',1600)),
        'wrong_device'=>xr(fn()=>ExternalApiSession::session(xs(['device_ref'=>'device:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']),xd(),'owner-human','venture:alpha',1600)),
    ];
}elseif($case==='lifetime'){
    $revokedSession=xs(['state'=>'revoked','revoked_at'=>1300,'revocation_reason'=>'owner_revoked']);
    $revokedDevice=xd(['state'=>'revoked','revoked_at'=>1400,'revocation_reason'=>'device_lost']);
    $out=[
        'valid'=>ExternalApiSession::session(xs(),xd(),'owner-human','venture:alpha',1600),
        'too_long'=>xr(fn()=>ExternalApiSession::session(xs(['expires_at'=>5001]),xd(),'owner-human','venture:alpha',1600)),
        'expired'=>xr(fn()=>ExternalApiSession::session(xs(),xd(),'owner-human','venture:alpha',2000)),
        'revoked_session'=>xr(fn()=>ExternalApiSession::session($revokedSession,xd(),'owner-human','venture:alpha',1600)),
        'revoked_device'=>xr(fn()=>ExternalApiSession::session(xs(),$revokedDevice,'owner-human','venture:alpha',1600)),
    ];
}elseif($case==='stepup'){
    $out=[
        'valid'=>ExternalApiSession::stepUp(xu(),xs(),xd(),'owner-human','venture:alpha',1600),
        'too_long'=>xr(fn()=>ExternalApiSession::stepUp(xu(['expires_at'=>1801]),xs(),xd(),'owner-human','venture:alpha',1600)),
        'expired'=>xr(fn()=>ExternalApiSession::stepUp(xu(),xs(),xd(),'owner-human','venture:alpha',1750)),
        'wrong_session'=>xr(fn()=>ExternalApiSession::stepUp(xu(['session_ref'=>'session:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']),xs(),xd(),'owner-human','venture:alpha',1600)),
        'wrong_device'=>xr(fn()=>ExternalApiSession::stepUp(xu(['device_ref'=>'device:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']),xs(),xd(),'owner-human','venture:alpha',1600)),
        'wrong_identity'=>xr(fn()=>ExternalApiSession::stepUp(xu(['identity_id'=>'other-human']),xs(),xd(),'owner-human','venture:alpha',1600)),
        'revoked_device'=>xr(fn()=>ExternalApiSession::stepUp(xu(),xs(),xd(['state'=>'revoked','revoked_at'=>1400,'revocation_reason'=>'device_lost']),'owner-human','venture:alpha',1600)),
        'revoked_step_up'=>xr(fn()=>ExternalApiSession::stepUp(
            xu(['state'=>'revoked','revoked_at'=>1550,'revocation_reason'=>'owner_revoked']),
            xs(),xd(),'owner-human','venture:alpha',1600
        )),
    ];
}elseif($case==='inventory'){
    $devices=[xd(),xd(['device_ref'=>'device:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','state'=>'revoked','revoked_at'=>1450,'revocation_reason'=>'owner_revoked'])];
    $sessions=[xs(),xs(['session_ref'=>'session:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','device_ref'=>'device:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','state'=>'revoked','revoked_at'=>1500,'revocation_reason'=>'owner_revoked'])];
    $bad=[];
    foreach(['access_token','refresh_token','cookie','otp','secret','credential','private_key','public_key'] as $field){
        $row=xs();$row[$field]='forbidden';$bad[$field]=xr(fn()=>ExternalApiSession::safeInventory([xd()],[$row],'owner-human'));
    }
    $out=['inventory'=>ExternalApiSession::safeInventory($devices,$sessions,'owner-human'),'bad'=>$bad];
}elseif($case==='boundary'){
    $device=ExternalApiSession::device(xd(),'owner-human');
    $session=ExternalApiSession::session(xs(),xd(),'owner-human','venture:alpha',1600);
    $step=ExternalApiSession::stepUp(xu(),xs(),xd(),'owner-human','venture:alpha',1600);
    $out=['device'=>$device,'session'=>$session,'step_up'=>$step];
}elseif($case==='pure'){
    $r=new ReflectionClass(ExternalApiSession::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ExternalApiSession::class
    ));
    sort($methods,SORT_STRING);$out=['methods'=>$methods];
}else{fwrite(STDERR,"Unknown external api session scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

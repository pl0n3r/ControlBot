<?php
declare(strict_types=1);
require __DIR__.'/../src/ExternalApiMobileState.php';

use ControlBot\ExternalApi\ExternalApiMobileState;

function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function snap(array $o=[]): array { return array_replace([
    'version'=>1,'resource_ref'=>'cockpit/group-health','observed_at'=>1000,'received_at'=>1010,
    'max_age_seconds'=>300,'source_ref'=>'controlbot:cockpit/group-health','sensitivity'=>'confidential',
],$o); }

$case=$argv[1]??'';
if($case==='states'){
    $out=[
        'fresh'=>ExternalApiMobileState::snapshot(snap(),true,1200),
        'stale'=>ExternalApiMobileState::snapshot(snap(),true,1401),
        'offline'=>ExternalApiMobileState::snapshot(snap(),false,1200),
        'unknown'=>ExternalApiMobileState::snapshot(snap(['observed_at'=>null,'received_at'=>null,'source_ref'=>null]),true,1200),
        'partial'=>bad(fn()=>ExternalApiMobileState::snapshot(snap(['received_at'=>null]),true,1200)),
    ];
}elseif($case==='reads'){
    $out=[
        'stale'=>ExternalApiMobileState::snapshot(snap(),true,1401),
        'offline'=>ExternalApiMobileState::snapshot(snap(),false,1200),
    ];
}elseif($case==='mutation'){
    $out=[
        'fresh'=>ExternalApiMobileState::mutationPolicy(snap(),true,1200,'owner.note.ack',true,false,false,['owner.note.ack']),
        'stale'=>ExternalApiMobileState::mutationPolicy(snap(),true,1401,'owner.note.ack',true,false,false,['owner.note.ack']),
        'offline_stepup'=>ExternalApiMobileState::mutationPolicy(snap(),false,1200,'owner.note.ack',true,true,false,['owner.note.ack']),
        'offline_high'=>ExternalApiMobileState::mutationPolicy(snap(),false,1200,'owner.note.ack',true,false,true,['owner.note.ack']),
        'unknown'=>ExternalApiMobileState::mutationPolicy(snap(['observed_at'=>null,'received_at'=>null,'source_ref'=>null]),true,1200,'owner.note.ack',true,false,false,['owner.note.ack']),
    ];
}elseif($case==='queue'){
    $out=[
        'queueable'=>ExternalApiMobileState::mutationPolicy(snap(),false,1200,'owner.note.ack',true,false,false,['owner.note.ack']),
        'not_listed'=>ExternalApiMobileState::mutationPolicy(snap(),false,1200,'owner.note.ack',true,false,false,[]),
        'not_idempotent'=>ExternalApiMobileState::mutationPolicy(snap(),false,1200,'owner.note.ack',false,false,false,['owner.note.ack']),
        'fresh'=>ExternalApiMobileState::mutationPolicy(snap(),true,1200,'owner.note.ack',true,false,false,['owner.note.ack']),
    ];
}elseif($case==='cache'){
    $public=snap(['sensitivity'=>'public']);
    $conf=snap(['sensitivity'=>'confidential']);
    $restricted=snap(['sensitivity'=>'restricted']);
    $badExtra=snap();$badExtra['access_token']='forbidden';
    $out=[
        'public'=>ExternalApiMobileState::cachePolicy($public,true,1200,true,false),
        'conf_plain'=>ExternalApiMobileState::cachePolicy($conf,true,1200,true,false),
        'conf_encrypted'=>ExternalApiMobileState::cachePolicy($conf,true,1200,true,true),
        'restricted'=>ExternalApiMobileState::cachePolicy($restricted,true,1200,true,true),
        'sensitive_ref'=>bad(fn()=>ExternalApiMobileState::snapshot(snap(['source_ref'=>'controlbot:credential/store']),true,1200)),
        'extra_sensitive'=>bad(fn()=>ExternalApiMobileState::snapshot($badExtra,true,1200)),
    ];
}elseif($case==='pure'){
    $r=new ReflectionClass(ExternalApiMobileState::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===ExternalApiMobileState::class
    ));
    sort($methods,SORT_STRING);$out=['methods'=>$methods];
}else{fwrite(STDERR,"Unknown external api mobile state scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

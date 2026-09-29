<?php
declare(strict_types=1);
require __DIR__.'/../src/OwnerInbox.php';

use ControlBot\Business\OwnerInbox;

function oiBlocked(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function oiEntry(array $o=[]): array { return array_replace([
    'version'=>1,'entry_ref'=>'controlbot:inbox/entry-aa','class'=>'fyi',
    'scope'=>['kind'=>'venture','ref'=>'controlbot:venture/condor'],
    'title'=>'Deploy completed','summary'=>'Release completed with verified evidence.',
    'impact'=>'No owner action required.','actor_ref'=>'controlbot:identity/agent-aa',
    'required_authority_level'=>null,'decision_ref'=>null,'options_ref'=>null,'deadline_at'=>null,
    'source_ref'=>'controlbot:source/factory','evidence_refs'=>['controlbot:evidence/release-aa'],
    'observed_at'=>2000,'freshness'=>'current',
],$o); }

$case=$argv[1]??'';
if($case==='scope'){
    $bad=oiEntry(); $bad['scope']=['kind'=>'venture','ref'=>'controlbot:project/condor'];
    $extra=oiEntry(); $extra['score']=9;
    $out=['good'=>OwnerInbox::entry(oiEntry()),'scope'=>oiBlocked(fn()=>OwnerInbox::entry($bad)),
        'class'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['class'=>'urgent']))),
        'extra'=>oiBlocked(fn()=>OwnerInbox::entry($extra))];
}elseif($case==='authority'){
    $decision=oiEntry(['class'=>'decision','entry_ref'=>'controlbot:inbox/entry-decision',
        'required_authority_level'=>'L4_OWNER','decision_ref'=>'controlbot:decision/decide-aa',
        'options_ref'=>'controlbot:options/owner-aa','deadline_at'=>4000]);
    $critical=oiEntry(['class'=>'critical','entry_ref'=>'controlbot:inbox/entry-critical',
        'required_authority_level'=>'L4_OWNER']);
    $out=['decision'=>OwnerInbox::entry($decision),'critical'=>OwnerInbox::entry($critical),
        'missing'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['class'=>'decision']))),
        'fyi_authority'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['required_authority_level'=>'L4_OWNER'])))];
}elseif($case==='freshness'){
    $out=['stale'=>OwnerInbox::entry(oiEntry(['freshness'=>'stale'])),
        'unknown'=>OwnerInbox::entry(oiEntry(['freshness'=>'unknown','source_ref'=>null,'evidence_refs'=>[],'observed_at'=>null])),
        'unknown_with_source'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['freshness'=>'unknown']))),
        'current_without_source'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['source_ref'=>null])))];
}elseif($case==='safe'){
    $out=['sorted'=>OwnerInbox::entry(oiEntry(['evidence_refs'=>['controlbot:evidence/zz','controlbot:evidence/aa']])),
        'duplicate'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['evidence_refs'=>['controlbot:evidence/aa','controlbot:evidence/aa']]))),
        'html'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['summary'=>'<b>secret</b>']))),
        'secret'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['source_ref'=>'controlbot:source/api-token-aa']))),
        'mail'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['summary'=>'Contact alice@example.com']))),
        'number'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['impact'=>'Call +57 300 123 4567']))),
        'local_phone_title'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['title'=>'Call 555-1234']))),
        'local_phone_summary'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['summary'=>'Call 555-1234']))),
        'local_phone_impact'=>oiBlocked(fn()=>OwnerInbox::entry(oiEntry(['impact'=>'Call 555-1234'])))];
}elseif($case==='collection'){
    $out=OwnerInbox::collection([
        oiEntry(['entry_ref'=>'controlbot:inbox/entry-fyi']),
        oiEntry(['class'=>'decision','entry_ref'=>'controlbot:inbox/entry-d2','required_authority_level'=>'L4_OWNER','decision_ref'=>'controlbot:decision/d2','deadline_at'=>3000]),
        oiEntry(['class'=>'critical','entry_ref'=>'controlbot:inbox/entry-c1','required_authority_level'=>'L4_OWNER']),
        oiEntry(['class'=>'decision','entry_ref'=>'controlbot:inbox/entry-d1','required_authority_level'=>'L4_OWNER','decision_ref'=>'controlbot:decision/d1','deadline_at'=>5000]),
        oiEntry(['class'=>'watch','entry_ref'=>'controlbot:inbox/entry-watch']),
    ]);
    $out['duplicate']=oiBlocked(fn()=>OwnerInbox::collection([oiEntry(),oiEntry()]));
}elseif($case==='pure'){
    $r=new ReflectionClass(OwnerInbox::class);
    $methods=array_values(array_map(static fn(ReflectionMethod $m):string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m):bool=>$m->getDeclaringClass()->getName()===OwnerInbox::class
    )));
    sort($methods,SORT_STRING);$out=['methods'=>$methods];
}else{fwrite(STDERR,"Unknown owner inbox scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

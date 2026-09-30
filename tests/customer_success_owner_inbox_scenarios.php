<?php
declare(strict_types=1);
require __DIR__.'/../src/CustomerSuccessCore.php';
require __DIR__.'/../src/OwnerInbox.php';
require __DIR__.'/../src/CustomerSuccessOwnerInboxProjection.php';

use ControlBot\Business\OwnerInbox;
use ControlBot\CustomerSuccess\CustomerSuccessCore;
use ControlBot\CustomerSuccess\CustomerSuccessOwnerInboxProjection;

const VENTURE451='venture-condor';
function ref451(string $ns,string $hex): string { return $ns.':'.str_repeat($hex,32); }
function dim451(string $name): array {
    $inferred=in_array($name,['churn_risk','renewal_signal'],true);
    return ['name'=>$name,'status'=>'measured','value_ref'=>ref451('metric','1'),
        'evidence_ref'=>ref451('evidence','2'),'freshness'=>'fresh','confidence'=>$inferred?0.7:1.0,
        'nature'=>$inferred?'inferred':'observed'];
}
function snapshot451(string $venture=VENTURE451): array {
    return ['version'=>1,'snapshot_id'=>ref451('success','3'),'venture_id'=>$venture,
        'product_ref'=>ref451('product','4'),'observed_at'=>1000,
        'dimensions'=>array_map('dim451',['activation','adoption','churn_risk','onboarding','reliability_impact','renewal_signal','satisfaction','support_burden','usage_recency'])];
}
function support451(string $venture=VENTURE451): array {
    return ['version'=>1,'signal_id'=>ref451('support','5'),'venture_id'=>$venture,
        'product_ref'=>ref451('product','4'),'category'=>'bug','severity'=>'critical',
        'pattern_ref'=>ref451('pattern','6'),'knowledge_ref'=>ref451('knowledge','7'),
        'resolution_state'=>'investigating','evidence_ref'=>ref451('evidence','8'),
        'freshness'=>'fresh','observed_at'=>995,'escalation_ref'=>ref451('escalation','9')];
}
function entry451(array $o=[]): array { return array_replace([
    'class'=>'watch','title'=>'Customer success exception','summary'=>'A material post-sale signal requires attention.',
    'impact'=>'Customer outcome may be affected if the condition persists.','actor_ref'=>null,
    'required_authority_level'=>null,'decision_ref'=>null,'options_ref'=>null,'deadline_at'=>null,
],$o); }
function blocked451(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }

$case=$argv[1]??'';
if($case==='support'){
    $entry=CustomerSuccessOwnerInboxProjection::fromSupport(support451(),VENTURE451,entry451());
    $out=['entry'=>$entry,'payload'=>json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)];
}elseif($case==='snapshot'){
    $normalized=CustomerSuccessCore::snapshot(snapshot451(),VENTURE451);
    $dimension=current(array_filter($normalized['dimensions'],static fn(array $d):bool=>$d['name']==='churn_risk'));
    $entry=CustomerSuccessOwnerInboxProjection::fromSnapshotDimension(snapshot451(),VENTURE451,'churn_risk',entry451(['class'=>'fyi']));
    $out=['dimension'=>$dimension,'entry'=>$entry];
}elseif($case==='explicit'){
    $watch=CustomerSuccessOwnerInboxProjection::fromSupport(support451(),VENTURE451,entry451());
    $decision=CustomerSuccessOwnerInboxProjection::fromSupport(support451(),VENTURE451,entry451([
        'class'=>'decision','required_authority_level'=>'L4_OWNER','decision_ref'=>'controlbot:decision/customer-success-review',
        'options_ref'=>'controlbot:options/customer-success-review','deadline_at'=>2200,
    ]));
    $out=['watch'=>$watch,'decision'=>$decision];
}elseif($case==='closed'){
    $unknown=support451();$unknown['freshness']='unknown';$unknown['observed_at']=null;$unknown['evidence_ref']=null;
    $stale=snapshot451();foreach($stale['dimensions'] as &$d)if($d['name']==='adoption')$d['freshness']='stale';unset($d);
    $extra=support451();$extra['ticket_body']='raw';
    $pii=entry451(['summary'=>'Contact person@example.com']);
    $out=[
        'unknown'=>CustomerSuccessOwnerInboxProjection::fromSupport($unknown,VENTURE451,entry451()),
        'stale'=>CustomerSuccessOwnerInboxProjection::fromSnapshotDimension($stale,VENTURE451,'adoption',entry451()),
        'cross'=>blocked451(fn()=>CustomerSuccessOwnerInboxProjection::fromSupport(support451('venture-other'),VENTURE451,entry451())),
        'missing'=>blocked451(fn()=>CustomerSuccessOwnerInboxProjection::fromSnapshotDimension(snapshot451(),VENTURE451,'missing',entry451())),
        'extra'=>blocked451(fn()=>CustomerSuccessOwnerInboxProjection::fromSupport($extra,VENTURE451,entry451())),
        'pii'=>blocked451(fn()=>CustomerSuccessOwnerInboxProjection::fromSupport(support451(),VENTURE451,$pii)),
    ];
}elseif($case==='compatible'){
    $entry=CustomerSuccessOwnerInboxProjection::fromSupport(support451(),VENTURE451,entry451());
    $out=['entry'=>$entry,'again'=>OwnerInbox::entry($entry)];
}elseif($case==='pure'){
    $r=new ReflectionClass(CustomerSuccessOwnerInboxProjection::class);
    $methods=array_map(static fn(ReflectionMethod $m):string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m):bool=>$m->getDeclaringClass()->getName()===CustomerSuccessOwnerInboxProjection::class
    ));sort($methods,SORT_STRING);
    $out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/CustomerSuccessOwnerInboxProjection.php')];
}else{fwrite(STDERR,"Unknown customer success Owner Inbox scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

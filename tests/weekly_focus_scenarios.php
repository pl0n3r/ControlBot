<?php
declare(strict_types=1);

require __DIR__.'/../src/SchedulerSelection.php';
require __DIR__.'/../src/WeeklyFocus.php';

use ControlBot\Scheduler\WeeklyFocus;
use InvalidArgumentException;

function candidate(string $key,int $issue,string $priority,bool $ready=true): array {
    return [
        'version'=>1,'policy_ref'=>'factory-dispatcher-v2','key'=>$key,
        'source_ref'=>'pl0n3r/ControlBot#'.$issue,'priority'=>$priority,'generation'=>1,
        'required_capabilities'=>[],'account_id'=>'controlbot:account/main',
        'readiness'=>[
            'ready'=>$ready,
            'reasons'=>$ready?[]:['open_dependencies'],
            'open_dependencies'=>$ready?[]:['dep-one'],
            'unknown_dependencies'=>[],
        ],
    ];
}
function focus(array $ordered,int $version=1,int $updated=100): array {
    return [
        'focus_id'=>'weekly-main','week_start'=>'2026-09-28','scope'=>'controlbot:scope/global',
        'ordered_refs'=>$ordered,'version'=>$version,'created_at'=>100,'updated_at'=>$updated,
        'updated_by'=>'controlbot:actor/owner',
    ];
}
function metadata(array $pairs): array {
    $out=[];
    foreach($pairs as $key=>$ref) $out[$key]=['focus_ref'=>$ref,'ref_state'=>'available'];
    return $out;
}

$a='controlbot:project/alpha';
$b='controlbot:project/beta';
$candidates=[candidate('work-a',701,'high'),candidate('work-b',702,'high')];
$meta=metadata(['work-a'=>$a,'work-b'=>$b]);

$first=WeeklyFocus::preference(focus([$b,$a]),$candidates,$meta);
$revised=WeeklyFocus::revise(focus([$b,$a]),[$a,$b],1,200,'controlbot:actor/owner');
$second=WeeklyFocus::preference($revised,$candidates,$meta);

$lowCannotWin=false;
try {
    WeeklyFocus::preference(
        focus([$b,$a]),
        [candidate('work-high',710,'high'),candidate('work-low',711,'low')],
        metadata(['work-high'=>$a,'work-low'=>$b])
    );
} catch(InvalidArgumentException) { $lowCannotWin=true; }

$parallelAuthorityAbsent=
    !array_key_exists('selected_key',$first)
    && array_key_exists('preferred_key',$first)
    && !array_key_exists('policy_rank',$first);

$criticalProtected=false;
try {
    WeeklyFocus::preference(
        focus([$b,$a]),
        [candidate('work-critical',712,'critical'),candidate('work-high',713,'high')],
        metadata(['work-critical'=>$a,'work-high'=>$b])
    );
} catch(InvalidArgumentException) { $criticalProtected=true; }

$blocked=WeeklyFocus::preference(
    focus([$a,$b]),
    [candidate('work-blocked',704,'high',false),candidate('work-b',702,'high')],
    metadata(['work-blocked'=>$a,'work-b'=>$b])
);

$unavailableMeta=$meta;
$unavailableMeta['work-a']['ref_state']='unavailable';
$unavailable=WeeklyFocus::preference(focus([$a,$b]),$candidates,$unavailableMeta);

$untypedRejected=false;
try { WeeklyFocus::normalize(focus(['https://github.com/pl0n3r/ControlBot/issues/7'])); }
catch(InvalidArgumentException) { $untypedRejected=true; }

$shared=WeeklyFocus::preference(
    focus([$a]),
    [candidate('work-a',720,'high'),candidate('work-b',721,'high')],
    metadata(['work-a'=>$a,'work-b'=>$a])
);

$conflict=false;
try { WeeklyFocus::revise(focus([$a,$b]),[$b,$a],2,200,'controlbot:actor/owner'); }
catch(InvalidArgumentException){ $conflict=true; }

$history=[
    focus([$b,$a],1,100),
    WeeklyFocus::revise(focus([$b,$a],1,100),[$a,$b],1,200,'controlbot:actor/owner'),
];
$at150=WeeklyFocus::activeAt($history,150);
$at250=WeeklyFocus::activeAt($history,250);

echo json_encode([
    'first'=>$first,
    'second'=>$second,
    'low_cannot_win'=>$lowCannotWin,
    'parallel_authority_absent'=>$parallelAuthorityAbsent,
    'critical_protected'=>$criticalProtected,
    'blocked'=>$blocked,
    'unavailable'=>$unavailable,
    'untyped_rejected'=>$untypedRejected,
    'shared'=>$shared,
    'conflict_rejected'=>$conflict,
    'history_150'=>$at150,
    'history_250'=>$at250,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

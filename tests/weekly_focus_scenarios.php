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

$a='controlbot:project/alpha'; $b='controlbot:project/beta'; $incident='controlbot:epic/incident';
$candidates=[candidate('work-a',701,'high'),candidate('work-b',702,'high')];
$meta=[
    'work-a'=>['focus_ref'=>$a,'policy_rank'=>10,'ref_state'=>'available'],
    'work-b'=>['focus_ref'=>$b,'policy_rank'=>10,'ref_state'=>'available'],
];

$first=WeeklyFocus::select(focus([$b,$a]),$candidates,$meta);
$revised=WeeklyFocus::revise(focus([$b,$a]),[$a,$b],1,200,'controlbot:actor/owner');
$second=WeeklyFocus::select($revised,$candidates,$meta);

$incidentCandidates=[candidate('work-a',701,'critical'),candidate('work-incident',703,'low')];
$incidentMeta=[
    'work-a'=>['focus_ref'=>$a,'policy_rank'=>10,'ref_state'=>'available'],
    'work-incident'=>['focus_ref'=>$incident,'policy_rank'=>0,'ref_state'=>'available'],
];
$precedence=WeeklyFocus::select(focus([$a,$incident]),$incidentCandidates,$incidentMeta);

$blockedCandidates=[candidate('work-blocked',704,'high',false),candidate('work-b',702,'high')];
$blockedMeta=[
    'work-blocked'=>['focus_ref'=>$a,'policy_rank'=>10,'ref_state'=>'available'],
    'work-b'=>['focus_ref'=>$b,'policy_rank'=>10,'ref_state'=>'available'],
];
$blocked=WeeklyFocus::select(focus([$a,$b]),$blockedCandidates,$blockedMeta);

$unavailableMeta=$meta; $unavailableMeta['work-a']['ref_state']='unavailable';
$unavailable=WeeklyFocus::select(focus([$a,$b]),$candidates,$unavailableMeta);

$conflict=false;
try { WeeklyFocus::revise(focus([$a,$b]),[$b,$a],2,200,'controlbot:actor/owner'); }
catch(InvalidArgumentException){ $conflict=true; }

$history=[
    focus([$b,$a],1,100),
    WeeklyFocus::revise(focus([$b,$a],1,100),[$a,$b],1,200,'controlbot:actor/owner'),
];
$at150=WeeklyFocus::activeAt($history,150);
$at250=WeeklyFocus::activeAt($history,250);
$clear=WeeklyFocus::revise($history[1],[],2,300,'controlbot:actor/owner');
$clearSelection=WeeklyFocus::select($clear,$candidates,$meta);

echo json_encode([
    'first'=>$first,'second'=>$second,'precedence'=>$precedence,'blocked'=>$blocked,
    'unavailable'=>$unavailable,'conflict_rejected'=>$conflict,'history_150'=>$at150,
    'history_250'=>$at250,'clear'=>$clear,'clear_selection'=>$clearSelection,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

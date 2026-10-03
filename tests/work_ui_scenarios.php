<?php
declare(strict_types=1);

require __DIR__ . '/../src/WorkUi.php';

use ControlBot\Scheduler\WorkUi;

function item(string $id, array $replace = []): array
{
    return array_replace([
        'version'=>1,'work_item_id'=>$id,'project_id'=>'project-controlbot',
        'source_ref'=>'pl0n3r/ControlBot#640','type'=>'feature','priority'=>'high','state'=>'queued',
        'dependency_ids'=>['dep-base'],'required_capabilities'=>['php','review'],
        'generation'=>2,'attempt'=>1,'reservation_id'=>null,'assigned_session_id'=>null,
    ], $replace);
}

function context(array $replace = []): array
{
    return array_replace([
        'dependency_states'=>['dep-base'=>'satisfied'],
        'approval_state'=>'approved',
        'freeze_state'=>'clear',
        'reservations'=>[],
        'capacity'=>['account_id'=>'account-main','eligible'=>true,'free_capacity'=>1,'session_ids'=>[]],
        'expected_generation'=>2,
        'expected_owner_session_id'=>null,
    ], $replace);
}

function view(array $rows): array
{
    return ['state'=>'ready','rows'=>$rows,'message'=>null];
}

$scenario = $argv[1] ?? '';
if ($scenario === 'ready') {
    echo WorkUi::render(view([
        ['work_item'=>item('work-feature',['priority'=>'critical']),'context'=>context()],
        ['work_item'=>item('work-incident',['type'=>'incident','priority'=>'medium','dependency_ids'=>[]]),'context'=>context(['dependency_states'=>[]])],
    ]));
    exit;
}
if ($scenario === 'blocked') {
    $reservation = '7c43b4b2-4ec5-4386-ab3b-95dcd9a4a076';
    $active = ['reservation_id'=>$reservation,'owner_session_id'=>'session-owner','generation'=>2,'active'=>true];
    echo WorkUi::render(view([[
        'work_item'=>item('work-blocked',[
            'state'=>'reserved','reservation_id'=>$reservation,'dependency_ids'=>['dep-open','dep-unknown'],
            'required_capabilities'=>['php'],
        ]),
        'context'=>context([
            'dependency_states'=>['dep-open'=>'open','dep-unknown'=>'unknown'],
            'reservations'=>[$active],
            'expected_owner_session_id'=>'session-owner',
        ]),
    ]]));
    exit;
}
if ($scenario === 'guarded') {
    echo WorkUi::render(view([
        ['work_item'=>item('work-approval',['dependency_ids'=>[]]),'context'=>context(['dependency_states'=>[],'approval_state'=>'unknown'])],
        ['work_item'=>item('work-freeze',['dependency_ids'=>[]]),'context'=>context(['dependency_states'=>[],'freeze_state'=>'active'])],
        ['work_item'=>item('work-pending',['dependency_ids'=>[]]),'context'=>context(['dependency_states'=>[],'approval_state'=>'pending'])],
    ]));
    exit;
}
if ($scenario === 'invalid') {
    echo WorkUi::render(view([
        ['work_item'=>item('work-invalid'),'context'=>context(['expected_generation'=>1])],
    ]));
    exit;
}
if ($scenario === 'message_secret') {
    echo WorkUi::render([
        'state'=>'error','rows'=>[],'message'=>'token=supersecretvalue',
    ]);
    exit;
}
if ($scenario === 'ready_empty') {
    echo WorkUi::render(['state'=>'ready','rows'=>[],'message'=>null]);
    exit;
}
if ($scenario === 'states') {
    $out = [];
    foreach (['loading','empty','error'] as $state) {
        $out[$state] = WorkUi::render([
            'state'=>$state,
            'rows'=>[],
            'message'=>$state === 'error' ? '<script>retry</script>' : null,
        ]);
    }
    echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}
fwrite(STDERR, "scenario inválido\n");
exit(2);

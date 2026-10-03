<?php
declare(strict_types=1);

require __DIR__ . '/../src/AgentUi.php';

use ControlBot\Runtime\AgentUi;

function baseView(): array
{
    return [
        'state'=>'ready',
        'message'=>null,
        'agents'=>[
            ['version'=>1,'agent_id'=>'agent-builder','role'=>'ingenieria-software','capabilities'=>['php','implementation']],
            ['version'=>1,'agent_id'=>'agent-reviewer','role'=>'qa','capabilities'=>['review','test']],
        ],
        'sessions'=>[
            [
                'version'=>1,'session_id'=>'session-builder','agent_id'=>'agent-builder','account_id'=>'account-gpt',
                'profile_alias'=>'primary','tab_id'=>'tab-1','status'=>'working','assignment_id'=>'assignment-638',
                'last_heartbeat_at'=>1000,'mode'=>'web','repository'=>'pl0n3r/ControlBot','issue_number'=>638,
            ],
            [
                'version'=>1,'session_id'=>'session-reviewer','agent_id'=>'agent-reviewer','account_id'=>'account-gpt',
                'profile_alias'=>'review','tab_id'=>'tab-2','status'=>'idle','assignment_id'=>null,
                'last_heartbeat_at'=>1001,'mode'=>'web','repository'=>null,'issue_number'=>null,
            ],
        ],
        'assignments'=>[
            [
                'version'=>1,'assignment_id'=>'assignment-638','session_id'=>'session-builder',
                'project_id'=>'project-controlbot','source_ref'=>'pl0n3r/ControlBot#638','status'=>'running',
            ],
        ],
        'handoffs'=>[
            [
                'version'=>1,'handoff_id'=>'handoff-638','assignment_id'=>'assignment-638',
                'from_session_id'=>'session-builder','to_session_id'=>'session-reviewer',
                'objective'=>'Revisar <b>Agent UI</b>','issue_ref'=>'pl0n3r/ControlBot#638',
                'pr_ref'=>'pl0n3r/ControlBot#637','sha'=>str_repeat('a',40),
                'last_result'=>'UI read-only implementada','evidence_ref'=>'https://github.com/pl0n3r/ControlBot/issues/638',
                'blocker'=>null,'next_action'=>'Ejecutar aceptación exact-head',
            ],
        ],
    ];
}

$scenario = $argv[1] ?? '';
if ($scenario === 'ready') {
    echo AgentUi::render(baseView());
    exit;
}
if ($scenario === 'incoherent') {
    $view = baseView();
    $view['sessions'][0]['agent_id'] = 'agent-missing';
    echo AgentUi::render($view);
    exit;
}
if ($scenario === 'handoff_mismatch') {
    $view = baseView();
    $view['handoffs'][0]['from_session_id'] = 'session-reviewer';
    echo AgentUi::render($view);
    exit;
}
if ($scenario === 'secret') {
    $view = baseView();
    $view['handoffs'][0]['objective'] = 'token=supersecretvalue';
    echo AgentUi::render($view);
    exit;
}
if ($scenario === 'message_secret') {
    echo AgentUi::render([
        'state'=>'error','message'=>'token=supersecretvalue',
        'agents'=>[],'sessions'=>[],'assignments'=>[],'handoffs'=>[],
    ]);
    exit;
}
if ($scenario === 'ready_empty') {
    echo AgentUi::render([
        'state'=>'ready','message'=>null,
        'agents'=>[],'sessions'=>[],'assignments'=>[],'handoffs'=>[],
    ]);
    exit;
}
if ($scenario === 'states') {
    $out = [];
    foreach (['loading','empty','error'] as $state) {
        $out[$state] = AgentUi::render([
            'state'=>$state,'message'=>$state === 'error' ? '<script>retry</script>' : null,
            'agents'=>[],'sessions'=>[],'assignments'=>[],'handoffs'=>[],
        ]);
    }
    echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}
fwrite(STDERR, "scenario inválido\n");
exit(2);

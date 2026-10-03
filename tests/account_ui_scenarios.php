<?php
declare(strict_types=1);

require __DIR__ . '/../src/AccountUi.php';

use ControlBot\Runtime\AccountUi;

function readyView(): array
{
    return [
        'state'=>'ready',
        'message'=>null,
        'providers'=>[
            ['version'=>1,'provider_id'=>'chatgpt-web','adapter'=>'autofactory'],
            ['version'=>1,'provider_id'=>'claude-web','adapter'=>'autofactory'],
        ],
        'accounts'=>[
            [
                'version'=>1,'account_id'=>'account-b','provider_id'=>'claude-web',
                'account_alias'=>'Revisión <QA>','plan'=>'pro','capacity'=>8,'status'=>'active',
            ],
            [
                'version'=>1,'account_id'=>'account-a','provider_id'=>'chatgpt-web',
                'account_alias'=>'Principal','plan'=>'plus','capacity'=>4,'status'=>'rate_limited',
            ],
        ],
    ];
}

$scenario=$argv[1]??'';
if($scenario==='ready'){
    echo AccountUi::render(readyView());
    exit;
}
if($scenario==='missing_provider'){
    $view=readyView();
    $view['accounts'][0]['provider_id']='missing-provider';
    echo AccountUi::render($view);
    exit;
}
if($scenario==='message_secret'){
    echo AccountUi::render([
        'state'=>'error','message'=>'token=supersecretvalue','providers'=>[],'accounts'=>[],
    ]);
    exit;
}
if($scenario==='ready_empty'){
    echo AccountUi::render(['state'=>'ready','message'=>null,'providers'=>[['version'=>1,'provider_id'=>'chatgpt-web','adapter'=>'autofactory']],'accounts'=>[]]);
    exit;
}
if($scenario==='states'){
    $out=[];
    foreach(['loading','empty','error'] as $state){
        $out[$state]=AccountUi::render([
            'state'=>$state,
            'message'=>$state==='error'?'<script>retry</script>':null,
            'providers'=>[],
            'accounts'=>[],
        ]);
    }
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),PHP_EOL;
    exit;
}
fwrite(STDERR,"scenario inválido\n");
exit(2);

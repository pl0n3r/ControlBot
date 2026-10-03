<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/OwnerSession.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/ApprovalEndpoint.php';
require __DIR__ . '/../src/GateInbox.php';
require __DIR__ . '/../src/DecisionBatch.php';
require __DIR__ . '/../src/DecisionUi.php';
require __DIR__ . '/../src/DecisionHistory.php';
require __DIR__ . '/../src/DecisionSnooze.php';
require __DIR__ . '/../src/DecisionQuestions.php';
require __DIR__ . '/../src/VentureIdentity.php';
require __DIR__ . '/../src/DecisionRights.php';
require __DIR__ . '/../src/IdentityCenter.php';
require __DIR__ . '/../src/VentureAccessRuntime.php';
require __DIR__ . '/../src/DecisionRuntime.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Approvals\ApprovalEndpoint;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\Gateway;
use ControlBot\GitHub\GateSource;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;

function runtimeGateBody(): string {
    $payload = [
        'category'=>'factory-release','context'=>'Publicar Factory.',
        'options'=>[
            ['id'=>'A','label'=>'Publicar','effect'=>'Publica el SHA.','pros'=>['Desbloquea'],'cons'=>['Mueve v1'],'risk'=>'medium','cost'=>'','reversible'=>true],
            ['id'=>'B','label'=>'No publicar','effect'=>'Mantiene v1.','pros'=>['Sin cambio'],'cons'=>['Sigue bloqueado'],'risk'=>'low','cost'=>'','reversible'=>true],
        ],
        'recommendation'=>'A','safe_default'=>'B','title_simple'=>'¿Publicamos Factory?',
        'summary_simple'=>'El SHA exacto está listo.','why_recommended'=>'Los controles pasaron.',
        'blocks'=>'Bloquea adopciones.',
    ];
    return '<!-- factory-human-gate '.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).' -->';
}

function runtimeBatchGateBody(): string {
    $payload = [
        'category'=>'brand','context'=>'Aplicar un cambio seguro.',
        'options'=>[
            ['id'=>'A','label'=>'Aplicar','effect'=>'Aplica el cambio.','pros'=>['Desbloquea'],'cons'=>['Cambia estado'],'risk'=>'low','cost'=>'','reversible'=>true],
            ['id'=>'B','label'=>'No aplicar','effect'=>'Mantiene el estado.','pros'=>['Sin cambio'],'cons'=>['Sigue pendiente'],'risk'=>'low','cost'=>'','reversible'=>true],
        ],
        'recommendation'=>'A','safe_default'=>'B','title_simple'=>'¿Aplicar cambio seguro?',
        'summary_simple'=>'Cambio de bajo riesgo listo.','why_recommended'=>'Los controles pasaron.',
    ];
    return '<!-- factory-human-gate '.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).' -->';
}

function ventureIdentity(string $id='identity-admin'): array { return ['version'=>1,'identity_id'=>$id,'kind'=>'human','display_name'=>strtoupper($id),'state'=>'active','source_ref'=>'controlbot:identity/'.$id,'observed_at'=>1700000000]; }
function ventureGrant(string $id,string $who,string $cap,string $auth='L2_VENTURE_ADMIN'): array { return ['version'=>1,'grant_id'=>$id,'identity_id'=>$who,'role'=>$auth==='L1_OPERATOR'?'operator':'venture_admin','capability'=>$cap,'scope'=>'venture:alpha','authority_level'=>$auth,'policy_ref'=>'controlbot:policy/business-os-v1','budget_limit'=>null,'granted_at'=>1700000000,'expires_at'=>1900000000]; }
function ventureState(array $grants=[]): array { return ['identity'=>ventureIdentity('identity-user'),'grants'=>$grants,'requests'=>[],'audit'=>[],'mfa'=>['required'=>false,'status'=>'unknown']]; }
function ventureGuard(string $decision='allow',string $reason='eligible'): array { return ['decision'=>$decision,'reason_code'=>$reason]; }
function ventureTrusted(): array { return ['identity'=>ventureIdentity(),'scope'=>'venture:alpha','active_policy_refs'=>['controlbot:policy/business-os-v1'],'grant'=>ventureGrant('grant-authority','identity-admin','identity.access.manage'),'budget_guard'=>ventureGuard(),'production_authority'=>ventureGuard()]; }
function ventureRequest(string $id='capowner'): array { return ['version'=>1,'command_id'=>$id,'idempotency_key'=>'idem_'.$id,'operation'=>'change_capability','reason_code'=>'staff_request','expires_at'=>null,'payload'=>['grant_id'=>'grant-main','capability'=>'venture.read']]; }
function ventureBaseState(): array { return ventureState([ventureGrant('grant-main','identity-user','venture.manage')]); }
function ventureSession(OwnerSessionService $sessions,int $now): array { $s=[]; $sessions->establishTrustedOAuthSession($s,'pl0n3r','fixture-server-value'); $sessions->reauthenticateTotp($s,Totp::code('JBSWY3DPEHPK3PXP',$now),'JBSWY3DPEHPK3PXP',$now); return $s; }

$scenario=$argv[1]??'';
$now=strtotime('2026-09-25T10:00:00Z');
$sha=str_repeat('a',40);
$isBatch=str_starts_with($scenario,'batch');
$gate=[
    'number'=>$isBatch ? 138 : 137,'state'=>'open','author_association'=>'OWNER','body'=>$isBatch ? runtimeBatchGateBody() : runtimeGateBody(),
    'title'=>'Release Factory','created_at'=>'2026-09-25T09:00:00Z',
    'html_url'=>'https://github.com/pl0n3r/factory/issues/137',
];
$workflowCalls=0;
$seen=[];
$materializedIssues=[];
$nextIssue=200;
$createdIssues=0;
$lostResponseThrown=false;

$sender=static function(string $method,string $url,array $headers,?string $body) use (&$workflowCalls,&$seen,&$materializedIssues,&$nextIssue,&$createdIssues,&$lostResponseThrown,$gate,$sha,$scenario): array {
    $seen[]=[$method,$url];
    $base='https://api.github.com/repos/pl0n3r/factory';
    if ($url===$base.'/issues?state=open&per_page=100&page=1') {
        $open=array_values(array_filter($materializedIssues,static fn(array $row):bool=>($row['state']??null)==='open'));
        return ['status'=>200,'body'=>json_encode(array_merge([$gate],$open),JSON_THROW_ON_ERROR)];
    }
    if ($url===$base.'/issues?state=all&labels=factory-human-gate&per_page=100&page=1') {
        $labeled=array_values(array_filter($materializedIssues,static fn(array $row):bool=>in_array('factory-human-gate',$row['labels']??[],true)));
        return ['status'=>200,'body'=>json_encode($labeled,JSON_THROW_ON_ERROR)];
    }
    if ($method==='POST' && $url===$base.'/issues') {
        $payload=json_decode((string)$body,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($payload)||!is_string($payload['title']??null)||!is_string($payload['body']??null)) {
            throw new RuntimeException('fixture Issue inválida');
        }
        $number=$nextIssue++;
        $createdIssues++;
        $materializedIssues[$number]=[
            'number'=>$number,'state'=>'open','author_association'=>'OWNER',
            'body'=>$payload['body'],'title'=>$payload['title'],'labels'=>$payload['labels']??[],
            'created_at'=>'2026-09-25T10:00:01Z',
            'html_url'=>'https://github.com/pl0n3r/factory/issues/'.$number,
        ];
        if ($scenario==='venture-response-lost' && !$lostResponseThrown) {
            $lostResponseThrown=true;
            throw new RuntimeException('fixture: respuesta POST perdida');
        }
        return ['status'=>201,'body'=>json_encode($materializedIssues[$number],JSON_THROW_ON_ERROR)];
    }
    if ($url===$base.'/issues/'.$gate['number']) return ['status'=>200,'body'=>json_encode($gate,JSON_THROW_ON_ERROR)];
    if (preg_match('#^'.preg_quote($base,'#').'/issues/(\\d+)$#',$url,$match)===1) {
        $number=(int)$match[1];
        if (isset($materializedIssues[$number]) && $method==='GET') {
            return ['status'=>200,'body'=>json_encode($materializedIssues[$number],JSON_THROW_ON_ERROR)];
        }
        if (isset($materializedIssues[$number]) && $method==='PATCH') {
            $materializedIssues[$number]['state']='closed';
            return ['status'=>200,'body'=>json_encode($materializedIssues[$number],JSON_THROW_ON_ERROR)];
        }
    }
    if ($url===$base.'/branches/main') return ['status'=>200,'body'=>json_encode(['commit'=>['sha'=>$sha]],JSON_THROW_ON_ERROR)];
    if ($url===$base."/commits/{$sha}/check-runs") return ['status'=>200,'body'=>json_encode(['check_runs'=>[['status'=>'completed','conclusion'=>'success','html_url'=>'https://github.com/check/1']]],JSON_THROW_ON_ERROR)];
    if ($url===$base."/commits/{$sha}") return ['status'=>200,'body'=>json_encode(['html_url'=>"https://github.com/pl0n3r/factory/commit/{$sha}"],JSON_THROW_ON_ERROR)];
    if ($method==='POST' && preg_match('#^'.preg_quote($base,'#').'/issues/(\\d+)/comments$#',$url,$match)===1) {
        return ['status'=>201,'body'=>json_encode(['html_url'=>'https://github.com/comment/'.$match[1]],JSON_THROW_ON_ERROR)];
    }
    if ($method==='PATCH' && $url===$base.'/git/refs/tags/v1') return ['status'=>200,'body'=>json_encode(['ref'=>'https://api.github.com/ref/v1'],JSON_THROW_ON_ERROR)];
    if ($method==='POST' && $url===$base.'/actions/workflows/release-bootstrap.yml/dispatches') return ['status'=>204,'body'=>''];
    if ($method==='PATCH' && $url===$base.'/issues/'.$gate['number']) return ['status'=>200,'body'=>json_encode(['html_url'=>'https://github.com/pl0n3r/factory/issues/'.$gate['number']],JSON_THROW_ON_ERROR)];
    if ($url===$base.'/actions/workflows/release-bootstrap.yml/runs') {
        $workflowCalls++;
        if ($scenario==='ambiguous') {
            $runs=[
                ['id'=>10,'event'=>'workflow_dispatch','head_sha'=>$sha,'created_at'=>'2026-09-25T10:00:01Z','status'=>'completed','conclusion'=>'success','html_url'=>'https://github.com/run/10'],
                ['id'=>11,'event'=>'workflow_dispatch','head_sha'=>$sha,'created_at'=>'2026-09-25T10:00:02Z','status'=>'completed','conclusion'=>'success','html_url'=>'https://github.com/run/11'],
            ];
        } elseif ($workflowCalls===1) {
            $runs=[];
        } else {
            $runs=[['id'=>12,'event'=>'workflow_dispatch','head_sha'=>$sha,'created_at'=>'2026-09-25T10:00:03Z','status'=>'completed','conclusion'=>'success','html_url'=>'https://github.com/run/12']];
        }
        return ['status'=>200,'body'=>json_encode(['workflow_runs'=>$runs],JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException("fixture ausente: {$method} {$url}");
};

$transport=new ApiTransport($sender);
$factory=static function(string $token) use($transport): array {
    $api=new ApiClient($token,$transport);
    return ['api'=>$api,'gateway'=>new Gateway($api),'source'=>new GateSource($api)];
};
$vault=new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$sessions=new OwnerSessionService('pl0n3r',$vault);
$session=ventureSession($sessions,$now);
$auditPath=tempnam(sys_get_temp_dir(),'controlbot-runtime-');
$audit=new AppendOnlyAuditLog($auditPath);
$runtime=new DecisionRuntime(
    $sessions,
    new ApprovalEndpoint($sessions,$audit,$factory),
    $audit,
    $factory,
    ['pl0n3r/factory'],
);

try {
    if ($scenario==='render') {
        $response=$runtime->handle('GET','/decisions',$session,[],$now);
        echo json_encode(['response'=>$response,'seen'=>$seen],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='render-oversized-audit-line') {
        file_put_contents($auditPath,str_repeat('X',9000)."\n");
        try {
            $runtime->handle('GET','/decisions',$session,[],$now);
            echo json_encode(['blocked'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
        } catch (Throwable $exception) {
            echo json_encode(['blocked'=>true,'message'=>$exception->getMessage()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        }
        exit;
    }
    if ($scenario==='render-large-audit') {
        $entry=json_encode([
            'actor'=>'pl0n3r','action'=>'comment','repository'=>'pl0n3r/factory','issue'=>137,
            'category'=>'factory-release','option'=>'A','sha'=>null,'result'=>'success',
            'evidence'=>null,'at'=>$now,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents($auditPath,str_repeat($entry,5001));
        $response=$runtime->handle('GET','/decisions',$session,[],$now);
        echo json_encode(['response'=>$response],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='flow' || $scenario==='ambiguous') {
        $request=[
            '_csrf'=>$sessions->csrfToken($session),'repository'=>'pl0n3r/factory','issue'=>'137',
            'option'=>'A','displayed_sha'=>$sha,'owner'=>'fixture-client-owner',
            'github_token'=>'fixture-client-value','dispatched_at'=>1,
        ];
        $approval=$runtime->handle('POST','/approvals/execute',$session,$request,$now);
        $status=$runtime->handle('GET','/release/status',$session,[],$now+10);
        echo json_encode([
            'approval'=>json_decode($approval['body'],true,32,JSON_THROW_ON_ERROR),
            'status'=>json_decode($status['body'],true,32,JSON_THROW_ON_ERROR),
            'seen'=>$seen,'session_keys'=>array_keys($session),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='snooze-direct-approval') {
        $runtime->handle('POST','/decisions/snooze',$session,[
            '_csrf'=>$sessions->csrfToken($session),
            'repository'=>'pl0n3r/factory',
            'issue'=>'137',
            'duration'=>'tomorrow',
        ],$now);
        try {
            $runtime->handle('POST','/approvals/execute',$session,[
                '_csrf'=>$sessions->csrfToken($session),
                'repository'=>'pl0n3r/factory',
                'issue'=>'137',
                'option'=>'A',
                'displayed_sha'=>$sha,
            ],$now+1);
            echo json_encode(['blocked'=>false,'audit'=>$audit->entries(),'seen'=>$seen],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        } catch (Throwable) {
            echo json_encode(['blocked'=>true,'audit'=>$audit->entries(),'seen'=>$seen],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        }
        exit;
    }
    if ($scenario==='batch' || $scenario==='batch-untrusted-selection' || $scenario==='batch-reauth' || $scenario==='batch-no-csrf' || $scenario==='batch-snoozed') {
        if ($scenario==='batch-snoozed') {
            $audit->record([
                'actor'=>'pl0n3r','action'=>'snooze','repository'=>'pl0n3r/factory','issue'=>138,
                'category'=>'brand','option'=>'S','sha'=>null,'result'=>'success',
                'evidence'=>null,'at'=>$now,'snoozed_until'=>$now+86400,
            ]);
        }
        $request=['_csrf'=>$sessions->csrfToken($session)];
        $at=$now;
        if ($scenario==='batch-untrusted-selection') {
            $request['repository']='evil/example';
            $request['option']='A';
        }
        if ($scenario==='batch-no-csrf') {
            $request=[];
        }
        if ($scenario==='batch-reauth') {
            $at=$now+301;
        }
        try {
            $response=$runtime->handle('POST','/approvals/batch',$session,$request,$at);
            echo json_encode([
                'response'=>json_decode($response['body'],true,32,JSON_THROW_ON_ERROR),
                'seen'=>$seen,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        } catch (Throwable) {
            echo json_encode(['blocked'=>true,'seen'=>$seen],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        }
        exit;
    }
    if (str_starts_with($scenario, 'snooze')) {
        $request=[
            '_csrf'=>$sessions->csrfToken($session),
            'repository'=>'pl0n3r/factory',
            'issue'=>'137',
            'duration'=>$scenario==='snooze-week' ? 'week' : 'tomorrow',
        ];
        $at=$now;
        if ($scenario==='snooze-invalid-duration') $request['duration']='custom-date';
        if ($scenario==='snooze-manipulated') $request['issue']='999';
        if ($scenario==='snooze-no-csrf') unset($request['_csrf']);
        if ($scenario==='snooze-stale-reauth') $at=$now+301;
        try {
            $response=$runtime->handle('POST','/decisions/snooze',$session,$request,$at);
            $before=$runtime->handle('GET','/decisions',$session,[],$at+1);
            $after=$runtime->handle('GET','/decisions',$session,[],$at+604801);
            echo json_encode([
                'response'=>json_decode($response['body'],true,32,JSON_THROW_ON_ERROR),
                'before'=>$before['body'],'after'=>$after['body'],
                'audit'=>$audit->entries(),'seen'=>$seen,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        } catch (Throwable) {
            echo json_encode(['blocked'=>true,'audit'=>$audit->entries(),'seen'=>$seen],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        }
        exit;
    }
    if ($scenario==='venture-materialize') {
        $result=$runtime->executeVentureAccess(
            $session,'pl0n3r/factory',ventureBaseState(),ventureRequest(),ventureTrusted(),$now
        );
        $page=$runtime->handle('GET','/decisions',$session,[],$now);
        echo json_encode([
            'result'=>$result,'page'=>$page,'created_issues'=>$createdIssues,
            'materialized'=>array_values($materializedIssues),'seen'=>$seen,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='venture-replay') {
        $request=ventureRequest('capreplay');
        $first=$runtime->executeVentureAccess(
            $session,'pl0n3r/factory',ventureBaseState(),$request,ventureTrusted(),$now
        );
        $secondSession=ventureSession($sessions,$now+1);
        $second=$runtime->executeVentureAccess(
            $secondSession,'pl0n3r/factory',$first['state'],$request,ventureTrusted(),$now+1
        );
        echo json_encode([
            'first'=>$first,'second'=>$second,'created_issues'=>$createdIssues,
            'materialized'=>array_values($materializedIssues),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='venture-untrusted-recovery') {
        $request=ventureRequest('capuntrusted');
        $marker='<!-- venture-access-materialization '.json_encode([
            'version'=>1,'command_id'=>$request['command_id'],'idempotency_key'=>$request['idempotency_key'],
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).' -->';
        $materializedIssues[199]=[
            'number'=>199,'state'=>'open','author_association'=>'NONE',
            'body'=>runtimeGateBody()."\n".$marker,'title'=>'spoofed','labels'=>['factory-human-gate'],
            'created_at'=>'2026-09-25T09:59:59Z',
            'html_url'=>'https://github.com/pl0n3r/factory/issues/199',
        ];
        $result=$runtime->executeVentureAccess(
            $session,'pl0n3r/factory',ventureBaseState(),$request,ventureTrusted(),$now
        );
        echo json_encode(['result'=>$result,'created_issues'=>$createdIssues,'issues'=>array_values($materializedIssues)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='venture-response-lost') {
        $request=ventureRequest('caplost');
        $firstFailed=false;
        try {
            $runtime->executeVentureAccess($session,'pl0n3r/factory',ventureBaseState(),$request,ventureTrusted(),$now);
        } catch (Throwable) { $firstFailed=true; }
        $secondSession=ventureSession($sessions,$now+1);
        $second=$runtime->executeVentureAccess(
            $secondSession,'pl0n3r/factory',ventureBaseState(),$request,ventureTrusted(),$now+1
        );
        echo json_encode(['first_failed'=>$firstFailed,'second'=>$second,'created_issues'=>$createdIssues,'issues'=>array_values($materializedIssues)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='venture-client-authority') {
        $extra=ventureRequest('capclient');
        $extra['repository']='evil/example';
        $extra['github_token']='fixture-client-value';
        $extraBlocked=false; $repoBlocked=false;
        try {
            $runtime->executeVentureAccess($session,'pl0n3r/factory',ventureBaseState(),$extra,ventureTrusted(),$now);
        } catch (Throwable) { $extraBlocked=true; }
        try {
            $runtime->executeVentureAccess($session,'evil/example',ventureBaseState(),ventureRequest('caprepo'),ventureTrusted(),$now);
        } catch (Throwable) { $repoBlocked=true; }
        echo json_encode([
            'extra_blocked'=>$extraBlocked,'repo_blocked'=>$repoBlocked,
            'created_issues'=>$createdIssues,'seen'=>$seen,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='venture-no-writer') {
        $noWriter=[]; $blocked=false;
        try {
            $runtime->executeVentureAccess($noWriter,'pl0n3r/factory',ventureBaseState(),ventureRequest('capwriter'),ventureTrusted(),$now);
        } catch (Throwable) { $blocked=true; }
        echo json_encode(['blocked'=>$blocked,'created_issues'=>$createdIssues,'seen'=>$seen],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='venture-approve') {
        $request=ventureRequest('capapprove');
        $result=$runtime->executeVentureAccess(
            $session,'pl0n3r/factory',ventureBaseState(),$request,ventureTrusted(),$now
        );
        $issue=(string)$result['owner_decision']['issue'];
        $approval=$runtime->handle('POST','/approvals/execute',$session,[
            '_csrf'=>$sessions->csrfToken($session),'repository'=>'pl0n3r/factory',
            'issue'=>$issue,'option'=>'A','displayed_sha'=>'',
        ],$now);
        $number=(int)$issue;
        echo json_encode([
            'result'=>$result,
            'approval'=>json_decode($approval['body'],true,32,JSON_THROW_ON_ERROR),
            'created_issues'=>$createdIssues,
            'issue_state'=>$materializedIssues[$number]['state']??null,
            'seen'=>$seen,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='history') {
        $audit->record([
            'actor'=>'pl0n3r','action'=>'comment','repository'=>'pl0n3r/factory','issue'=>137,
            'category'=>'factory-release','option'=>'A','sha'=>$sha,'result'=>'success',
            'evidence'=>'https://github.com/pl0n3r/factory/issues/137#issuecomment-1','at'=>$now,
        ]);
        $audit->record([
            'actor'=>'pl0n3r','action'=>'close-issue','repository'=>'pl0n3r/factory','issue'=>137,
            'category'=>'factory-release','option'=>'A','sha'=>$sha,'result'=>'success',
            'evidence'=>'https://github.com/pl0n3r/factory/issues/137','at'=>$now,
        ]);
        $response=$runtime->handle('GET','/decisions/history',$session,[
            'repository'=>'pl0n3r/factory','category'=>'factory-release',
        ],$now);
        echo json_encode(['response'=>$response],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
        exit;
    }
    if ($scenario==='untrusted-repo') {
        $runtime->handle('POST','/approvals/execute',$session,[
            '_csrf'=>$sessions->csrfToken($session),'repository'=>'example.invalid/repo',
            'issue'=>'1','option'=>'A','displayed_sha'=>$sha,
        ],$now);
        exit(3);
    }
    fwrite(STDERR,"scenario inválido\n");
    exit(2);
} finally {
    @unlink($auditPath);
}

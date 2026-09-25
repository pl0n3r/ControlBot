<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/OwnerSession.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/ApprovalEndpoint.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Approvals\ApprovalEndpoint;
use ControlBot\Approvals\GitHubGateway;
use ControlBot\Approvals\HumanGate;
use ControlBot\GitHub\HumanGateSource;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;

final class FakeGateway implements GitHubGateway {
    public array $calls = [];
    public function mainSha(string $repository): string { $this->calls[]=['mainSha',$repository]; return str_repeat('a',40); }
    public function commentIssue(string $repository,int $issue,string $body): string { $this->calls[]=['commentIssue',$repository,$issue,$body]; return 'comment'; }
    public function closeIssue(string $repository,int $issue): string { $this->calls[]=['closeIssue',$repository,$issue]; return 'issue'; }
    public function moveTag(string $repository,string $tag,string $sha): string { $this->calls[]=['moveTag',$repository,$tag,$sha]; return 'tag'; }
    public function dispatchWorkflow(string $repository,string $workflow,array $inputs): string { $this->calls[]=['dispatchWorkflow',$repository,$workflow,$inputs]; return 'run'; }
}
final class FakeSource implements HumanGateSource {
    public array $calls = [];
    public function load(string $repository,int $issue): HumanGate {
        $this->calls[]=[$repository,$issue];
        $payload=[
            'category'=>'factory-release',
            'context'=>'Publish exact Factory release.',
            'title_simple'=>'¿Publicamos Factory?',
            'summary_simple'=>"El SHA está validado.\nLa publicación desbloquea adopciones.",
            'options'=>[
                ['id'=>'A','label'=>'Publicar','effect'=>'Publica el SHA.','pros'=>['Desbloquea trabajo'],'cons'=>['Mueve v1'],'risk'=>'medium','cost'=>'','reversible'=>true],
                ['id'=>'B','label'=>'No publicar','effect'=>'Mantiene v1.','pros'=>['Sin cambio'],'cons'=>['Sigue bloqueado'],'risk'=>'low','cost'=>'','reversible'=>true],
            ],
            'recommendation'=>'A','safe_default'=>'B',
            'why_recommended'=>'El SHA exacto está verde.',
            'blocks'=>'Bloquea adopciones Factory.',
        ];
        return HumanGate::fromIssueBody('<!-- factory-human-gate '.json_encode($payload, JSON_UNESCAPED_SLASHES).' -->');
    }
}

$scenario=$argv[1]??'';
$now=1800000000;
$vault=new TokenVault(base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$sessions=new OwnerSessionService('pl0n3r',$vault);
$session=[];
$sessions->establishTrustedOAuthSession($session,'pl0n3r','ghp_server_secret');
$sessions->reauthenticateTotp($session,Totp::code('JBSWY3DPEHPK3PXP',$now),'JBSWY3DPEHPK3PXP',$now);
$gateway=new FakeGateway();
$source=new FakeSource();
$seenToken=null;
$factory=static function(string $token) use($gateway,$source,&$seenToken): array { $seenToken=$token; return ['gateway'=>$gateway,'source'=>$source]; };
$auditPath=tempnam(sys_get_temp_dir(),'controlbot-audit-');
$endpoint=new ApprovalEndpoint($sessions,new AppendOnlyAuditLog($auditPath),$factory);
$request=[
    '_csrf'=>$sessions->csrfToken($session),
    'repository'=>'pl0n3r/factory',
    'issue'=>'137',
    'option'=>'A',
    'displayed_sha'=>str_repeat('a',40),
    'owner'=>'attacker',
    'reauthenticated_at'=>$now+999,
    'github_token'=>'ghp_attacker',
];

try {
    if ($scenario === 'request') {
        $result=$endpoint->execute($session,$request,$now);
        echo json_encode([
            'result'=>$result,'token'=>$seenToken,'source_calls'=>$source->calls,'gateway_calls'=>$gateway->calls,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } elseif ($scenario === 'flow') {
        $result=$endpoint->execute($session,$request,$now);
        echo json_encode([
            'result'=>$result,'gateway_calls'=>$gateway->calls,
            'audit'=>array_values(array_filter(explode("\n", trim((string)file_get_contents($auditPath))))),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } else {
        fwrite(STDERR,"scenario inválido\n"); exit(2);
    }
} finally {
    @unlink($auditPath);
}

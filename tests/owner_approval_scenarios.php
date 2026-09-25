<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Approvals\GitHubGateway;
use ControlBot\Approvals\HumanGate;
use ControlBot\Approvals\OwnerApprovalService;
use ControlBot\Approvals\OwnerContext;

final class FakeGitHub implements GitHubGateway
{
    public array $calls = [];
    public function __construct(public string $sha) {}
    public function mainSha(string $repository): string { $this->calls[] = ['mainSha', $repository]; return $this->sha; }
    public function commentIssue(string $repository, int $issue, string $body): string { $this->calls[] = ['commentIssue', $repository, $issue, $body]; return "https://github.test/issue/$issue#comment"; }
    public function closeIssue(string $repository, int $issue): string { $this->calls[] = ['closeIssue', $repository, $issue]; return "https://github.test/issue/$issue"; }
    public function moveTag(string $repository, string $tag, string $sha): string { $this->calls[] = ['moveTag', $repository, $tag, $sha]; return "https://github.test/tag/$tag"; }
    public function dispatchWorkflow(string $repository, string $workflow, array $inputs): string { $this->calls[] = ['dispatchWorkflow', $repository, $workflow, $inputs]; return "https://github.test/run/1"; }
}

function gate(string $category): HumanGate {
    $body = '<!-- factory-human-gate ' . json_encode([
        'category' => $category,
        'context' => 'Decisión de prueba controlada.',
        'options' => [['id' => 'A', 'label' => 'Aprobar'], ['id' => 'B', 'label' => 'No aprobar']],
        'recommendation' => 'A',
        'safe_default' => 'B',
    ], JSON_THROW_ON_ERROR) . ' -->';
    return HumanGate::fromIssueBody($body);
}

$scenario = $argv[1] ?? '';
$now = 1_800_000_000;
$shaA = str_repeat('a', 40);
$shaB = str_repeat('b', 40);
$path = tempnam(sys_get_temp_dir(), 'controlbot-audit-');
$github = new FakeGitHub($shaA);
$service = new OwnerApprovalService($github, new AppendOnlyAuditLog($path));
$out = [];

try {
    if ($scenario === 'release') {
        $result = $service->approve(gate('factory-release'), 'A', 'pl0n3r/factory', 114, $shaA, new OwnerContext('pl0n3r', true, $now - 10), $now);
        $out = ['result' => $result, 'calls' => array_column($github->calls, 0)];
    } elseif ($scenario === 'stale') {
        $github->sha = $shaB;
        try { $service->approve(gate('factory-release'), 'A', 'pl0n3r/factory', 114, $shaA, new OwnerContext('pl0n3r', true, $now - 10), $now); }
        catch (Throwable $e) { $out = ['error' => $e->getMessage(), 'calls' => array_column($github->calls, 0)]; }
    } elseif ($scenario === 'reauth') {
        try { $service->approve(gate('legal'), 'A', 'pl0n3r/ControlBot', 20, null, new OwnerContext('pl0n3r', true, $now - 301), $now); }
        catch (Throwable $e) { $out['stale'] = $e->getMessage(); }
        $service->approve(gate('legal'), 'A', 'pl0n3r/ControlBot', 20, null, new OwnerContext('pl0n3r', true, $now - 5), $now);
        $out['audit'] = array_values(array_filter(file($path, FILE_IGNORE_NEW_LINES) ?: []));
        $out['calls'] = array_column($github->calls, 0);
    } elseif ($scenario === 'money') {
        $service->approve(gate('money'), 'A', 'pl0n3r/ControlBot', 99, null, new OwnerContext('pl0n3r', true, $now - 5), $now);
        $out = ['calls' => array_column($github->calls, 0)];
    } elseif ($scenario === 'invalid') {
        try { HumanGate::fromIssueBody('<!-- factory-human-gate {"category":"unknown"} -->'); }
        catch (Throwable $e) { $out = ['error' => $e->getMessage()]; }
    } else {
        throw new RuntimeException('scenario inválido');
    }
} finally {
    @unlink($path);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

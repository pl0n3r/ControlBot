<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Approval/OwnerApprovalService.php';

use ControlBot\Approval\ApprovalDenied;
use ControlBot\Approval\ApprovalPort;
use ControlBot\Approval\OwnerApprovalService;

final class FakePort implements ApprovalPort
{
    public array $calls = [];
    public int $reads = 0;
    public string $main;
    public string $channel;
    public string $gateBody = '';
    public string $association = 'OWNER';
    public bool $failMove = false;
    public bool $changeMain = false;
    public ?string $approvalOption = 'A';

    public function __construct()
    {
        $this->main = str_repeat('a', 40);
        $this->channel = str_repeat('b', 40);
    }
    public function readGate(int $issue): array
    {
        return ['body' => $this->gateBody, 'author_association' => $this->association, 'approval_option' => $this->approvalOption];
    }
    public function mainSha(): string
    {
        $this->reads++;
        return $this->changeMain && $this->reads > 1 ? str_repeat('c', 40) : $this->main;
    }
    public function channelSha(): string
    {
        return $this->channel;
    }
    public function appendOwnerApproval(int $issue, string $sha, string $owner): void
    {
        $this->calls[] = 'approval';
    }
    public function compareAndMoveChannel(string $previousSha, string $sha): void
    {
        if ($this->failMove) throw new RuntimeException('adapter failed');
        if ($previousSha !== $this->channel) throw new RuntimeException('channel changed');
        $this->calls[] = 'move';
        $this->channel = $sha;
    }
    public function dispatchRelease(string $sha, int $issue): void
    {
        $this->calls[] = 'dispatch';
    }
}

$scenario = $argv[1] ?? 'happy';
$port = new FakePort();
$category = in_array($scenario, ['money', 'money_sha'], true) ? 'money' : 'factory-release';
$marker = [
    'category' => $category, 'context' => 'Publicar cambio en Factory',
    'options' => [['id' => 'A', 'label' => 'Aprobar'], ['id' => 'B', 'label' => 'Rechazar']],
    'recommendation' => 'B', 'safe_default' => 'B'
];
if ($scenario === 'go_live') $marker['category'] = 'go-live';
if ($scenario === 'malformed_gate') $marker['extra'] = 'invalid';
$port->gateBody = '<!-- factory-human-gate ' . json_encode($marker, JSON_THROW_ON_ERROR) . ' -->';
if ($scenario === 'untrusted_gate') $port->association = 'NONE';
if ($scenario === 'missing_approval_mapping') $port->approvalOption = null;
if ($scenario === 'invalid_approval_mapping') $port->approvalOption = 'D';
if ($scenario === 'changed_after_approval') $port->changeMain = true;
if ($scenario === 'tag_move_failure') $port->failMove = true;
if ($scenario === 'stale') $port->main = str_repeat('c', 40);

$session = [
    'actor' => $scenario === 'other_actor' ? 'other' : 'pl0n3r',
    'authenticated' => $scenario !== 'not_authenticated',
    'reauthenticated_at' => $scenario === 'expired' ? 100 : 950
];
$audits = [];
$service = new OwnerApprovalService(
    $port,
    static fn (): array => $session,
    static function (array $event) use (&$audits, $scenario): void {
        if ($scenario === 'audit_failure') throw new RuntimeException('audit unavailable');
        $audits[] = $event;
    },
    static fn (): int => 1000
);
$sha = $scenario === 'money' ? null : str_repeat('a', 40);
if ($scenario === 'invalid_sha') $sha = 'short';
$option = $scenario === 'invalid_option' ? 'D' : ($scenario === 'decline' ? 'B' : 'A');
try {
    $outcome = $service->approve(42, $option, $sha);
    $denied = false;
} catch (ApprovalDenied) {
    $outcome = null;
    $denied = true;
}
echo json_encode([
    'denied' => $denied, 'outcome' => $outcome,
    'mutations' => $port->calls, 'audits' => $audits,
    'channel' => $port->channel
], JSON_THROW_ON_ERROR);

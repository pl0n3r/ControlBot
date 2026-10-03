<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/DecisionHistory.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Decisions\DecisionHistory;

$scenario = $argv[1] ?? '';
$path = tempnam(sys_get_temp_dir(), 'controlbot-history-');
$audit = new AppendOnlyAuditLog($path);
$history = new DecisionHistory($audit, ['pl0n3r/ControlBot', 'pl0n3r/factory']);

$record = static function (
    AppendOnlyAuditLog $audit,
    string $repository,
    int $issue,
    string $category,
    string $option,
    string $action,
    string $result,
    int $at,
    ?string $evidence = null,
    string $actor = 'pl0n3r',
): void {
    $audit->record([
        'actor' => $actor,
        'action' => $action,
        'repository' => $repository,
        'issue' => $issue,
        'category' => $category,
        'option' => $option,
        'sha' => null,
        'result' => $result,
        'evidence' => $evidence,
        'at' => $at,
    ]);
};

try {
    if ($scenario === 'history') {
        $record($audit, 'pl0n3r/ControlBot', 40, 'product-direction', 'A', 'comment', 'success', 100, 'https://github.com/pl0n3r/ControlBot/issues/40#issuecomment-1');
        $record($audit, 'pl0n3r/ControlBot', 40, 'product-direction', 'A', 'close-issue', 'success', 100, 'https://github.com/pl0n3r/ControlBot/issues/40');
        $record($audit, 'pl0n3r/factory', 140, 'factory-release', 'A', 'comment', 'success', 200, 'https://github.com/pl0n3r/factory/issues/140#issuecomment-1');
        $record($audit, 'pl0n3r/factory', 140, 'factory-release', 'A', 'dispatch-release', 'failed', 200);
        echo json_encode([
            'all' => $history->load(),
            'filtered' => $history->load('pl0n3r/factory', 'factory-release'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit;
    }
    if ($scenario === 'snooze') {
        $audit->record([
            'actor' => 'pl0n3r',
            'action' => 'snooze',
            'repository' => 'pl0n3r/ControlBot',
            'issue' => 65,
            'category' => 'product-direction',
            'option' => 'S',
            'sha' => null,
            'result' => 'success',
            'evidence' => null,
            'at' => 400,
            'snoozed_until' => 500,
        ]);
        echo json_encode([
            'active' => $history->load(null, null, 450),
            'expired' => $history->load(null, null, 600),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit;
    }
    if ($scenario === 'bad-filter') {
        $history->load('example.invalid/repo', null);
        exit(3);
    }
    if ($scenario === 'bad-category') {
        $history->load(null, 'attacker-category');
        exit(3);
    }
    if ($scenario === 'secret') {
        $record(
            $audit,
            'pl0n3r/ControlBot',
            41,
            'legal',
            'B',
            'comment',
            'success',
            300,
            'https://github.com/pl0n3r/ControlBot/issues/41?token=supersecret',
            'owner-secret-value',
        );
        echo json_encode($history->load(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit;
    }
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
} finally {
    @unlink($path);
}

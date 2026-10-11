<?php
declare(strict_types=1);

require_once __DIR__.'/../src/ProductReadinessChecklist.php';

use ControlBot\Readiness\ProductReadinessChecklist;

$now = 1_800_000_000;
$ref = static fn (): array => [
    'source_ref' => 'https://github.com/pl0n3r/ControlBot/actions/runs/123',
    'revision' => str_repeat('a', 40),
    'observed_at' => $now - 20,
    'valid_for_seconds' => 300,
    'conclusion' => 'pass',
];
$item = static fn (string $id, string $priority, ?int $issue, ?string $blocker, ?array $evidence): array => [
    'id' => $id,
    'priority' => $priority,
    'issue' => $issue,
    'blocker' => $blocker,
    'evidence' => $evidence,
];
$mode = $argv[1] ?? '';
try {
    $data = match ($mode) {
        'macro' => [
            $item('SECURITY', 'P0', 123, null, $ref()),
            $item('LAUNCH', 'P3', null, 'owner_decision_pending', null),
        ],
        'missing' => [$item('OBSERVABILITY', 'P2', 77, null, null)],
        'stale' => [$item('OBSERVABILITY', 'P2', 77, null, array_replace($ref(), ['observed_at' => $now - 400]))],
        'high_missing' => [
            $item('PAYMENT', 'P0', null, 'owner_approval_pending', $ref()),
            $item('PRIVACY', 'P1', null, 'review_pending', $ref()),
        ],
        'candidate' => [$item('SECURITY', 'P0', 123, null, $ref())],
        'hostile' => [array_replace($item('SECURITY', 'P0', 123, null, $ref()), ['token' => 'NEVER_ECHO'])],
        default => throw new InvalidArgumentException('Scenario invalid.'),
    };
    echo json_encode(ProductReadinessChecklist::evaluate($data, $now), JSON_THROW_ON_ERROR), PHP_EOL;
} catch (InvalidArgumentException $error) {
    echo 'blocked', PHP_EOL;
}

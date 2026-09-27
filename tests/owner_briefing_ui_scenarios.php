<?php
declare(strict_types=1);

require __DIR__ . '/../src/OwnerBriefingUi.php';

use ControlBot\Briefing\OwnerBriefingUi;

$name = $argv[1] ?? '';

function brief_item(string $summary, string $evidence): array {
    return ['summary' => $summary, 'evidence' => $evidence];
}

function quiet_snapshot(): array {
    return [
        'delivered' => [],
        'today' => [],
        'broken' => [],
        'decisions' => [],
        'costs' => [],
    ];
}

if ($name === 'attention') {
    echo OwnerBriefingUi::render([
        'delivered' => [brief_item('Budget dashboard integrado', 'https://github.com/pl0n3r/ControlBot/pull/101')],
        'today' => [brief_item('Construir briefing', 'controlbot:issue/102')],
        'broken' => [],
        'decisions' => [brief_item('Revisión legal pendiente', 'https://github.com/pl0n3r/ControlBot/issues/20')],
        'costs' => [],
    ]);
} elseif ($name === 'quiet') {
    echo OwnerBriefingUi::render(quiet_snapshot());
} elseif ($name === 'unknown') {
    echo OwnerBriefingUi::render([
        'delivered' => [],
        'today' => [],
        'broken' => null,
        'decisions' => [],
        'costs' => null,
    ]);
} elseif ($name === 'escape') {
    $snapshot = quiet_snapshot();
    $snapshot['delivered'] = [brief_item('<script>alert("briefing")</script>', 'controlbot:delivery/escape')];
    echo OwnerBriefingUi::render($snapshot);
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

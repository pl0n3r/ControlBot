<?php
declare(strict_types=1);

require __DIR__ . '/../src/LexLifecycle.php';

use ControlBot\Legal\LexLifecycle;
use InvalidArgumentException;

function input(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'scope' => 'venture:condor',
        'stage' => 'construction',
        'legal_state' => 'unknown',
        'material_risk' => false,
        'reversible_work' => true,
        'real_data_requested' => false,
        'human_review' => [
            'state' => 'pending',
            'scope' => 'venture:condor',
            'evidence_refs' => [],
        ],
    ], $overrides);
}

function blocked(array $payload): bool
{
    try {
        LexLifecycle::project($payload);
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

$name = $argv[1] ?? '';
if ($name === 'construction') {
    $out = LexLifecycle::project(input());
} elseif ($name === 'live') {
    $out = [
        'pending' => LexLifecycle::project(input(['stage' => 'live'])),
        'gap' => LexLifecycle::project(input([
            'stage' => 'live',
            'legal_state' => 'gap',
            'human_review' => [
                'state' => 'approved',
                'scope' => 'venture:condor',
                'evidence_refs' => ['controlbot:lex/evidence/legal-review'],
            ],
        ])),
        'approved' => LexLifecycle::project(input([
            'stage' => 'live',
            'legal_state' => 'compliant',
            'human_review' => [
                'state' => 'approved',
                'scope' => 'venture:condor',
                'evidence_refs' => ['controlbot:lex/evidence/legal-review'],
            ],
        ])),
        'real_data' => LexLifecycle::project(input(['real_data_requested' => true])),
        'wrong_scope' => LexLifecycle::project(input([
            'stage' => 'live',
            'legal_state' => 'compliant',
            'human_review' => [
                'state' => 'approved',
                'scope' => 'venture:brvtal',
                'evidence_refs' => ['controlbot:lex/evidence/legal-review'],
            ],
        ])),
        'approved_without_evidence_blocked' => blocked(input([
            'human_review' => [
                'state' => 'approved',
                'scope' => 'venture:condor',
                'evidence_refs' => [],
            ],
        ])),
    ];
} elseif ($name === 'invalid-evidence') {
    $out = [
        'session' => blocked(input([
            'human_review' => [
                'state' => 'approved',
                'scope' => 'venture:condor',
                'evidence_refs' => ['controlbot:lex/evidence/session-credential'],
            ],
        ])),
        'traversal' => blocked(input([
            'human_review' => [
                'state' => 'approved',
                'scope' => 'venture:condor',
                'evidence_refs' => ['controlbot:lex/evidence/../approval'],
            ],
        ])),
    ];
} elseif ($name === 'risk') {
    $out = [
        'material' => LexLifecycle::project(input(['material_risk' => true])),
        'material_approved' => LexLifecycle::project(input([
            'legal_state' => 'compliant',
            'material_risk' => true,
            'human_review' => [
                'state' => 'approved',
                'scope' => 'venture:condor',
                'evidence_refs' => ['controlbot:lex/evidence/legal-review'],
            ],
        ])),
        'rejected' => LexLifecycle::project(input([
            'human_review' => [
                'state' => 'rejected',
                'scope' => 'venture:condor',
                'evidence_refs' => ['controlbot:lex/evidence/legal-review'],
            ],
        ])),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

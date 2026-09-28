<?php
declare(strict_types=1);

require __DIR__ . '/../src/LexGate.php';

use ControlBot\Legal\LexGate;

function lexGateInput(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'kind' => 'material_uncertainty',
        'gap_id' => 'privacy-transfer-basis',
        'scope' => 'venture:condor',
        'question' => '¿La transferencia internacional propuesta cuenta con una base jurídica válida?',
        'freshness' => 'fresh',
        'severity' => 'high',
        'policy_ref' => 'controlbot:policy/lex-colombia-v1',
        'evidence_refs' => ['controlbot:lex/evidence-transfer-review'],
        'observed_at' => 1200,
        'work_type' => 'compliance_review',
        'requested_capabilities' => ['legal-review'],
        'required_roles' => ['legal-privacidad'],
        'group_id' => 'pl0n3r',
        'venture_id' => 'condor',
        'project_id' => 'condor-colombia',
        'repository_ref' => 'pl0n3r/Condor',
        'authority_level' => 'L4_OWNER',
        'producer_ref' => 'controlbot:lex/engine',
    ], $overrides);
}

$name = $argv[1] ?? '';
$now = 1500;

if ($name === 'gate') {
    $out = LexGate::evaluate(lexGateInput(), $now);
} elseif ($name === 'work') {
    $out = LexGate::evaluate(lexGateInput([
        'kind' => 'executable_gap',
        'gap_id' => 'privacy-notice-update',
        'question' => 'Actualizar el aviso de privacidad para reflejar la transferencia documentada.',
        'severity' => 'medium',
        'work_type' => 'compliance_review',
        'requested_capabilities' => ['privacy-documentation'],
        'required_roles' => ['legal-privacidad', 'contenido'],
        'authority_level' => 'L1_OPERATOR',
    ]), $now);
} elseif ($name === 'stale') {
    $stale = LexGate::evaluate(lexGateInput([
        'kind' => 'executable_gap',
        'gap_id' => 'stale-compliance-gap',
        'freshness' => 'stale',
        'work_type' => 'compliance_review',
    ]), $now);
    $unknown = LexGate::evaluate(lexGateInput([
        'kind' => 'executable_gap',
        'gap_id' => 'unknown-compliance-gap',
        'freshness' => 'unknown',
        'work_type' => 'compliance_review',
    ]), $now);
    $out = ['stale' => $stale, 'unknown' => $unknown];
} elseif ($name === 'authority') {
    $out = LexGate::evaluate(lexGateInput(), $now);
} elseif ($name === 'idempotency') {
    $one = lexGateInput([
        'kind' => 'executable_gap',
        'gap_id' => 'privacy-notice-update',
        'work_type' => 'compliance_review',
    ]);
    $same = $one;
    $out = [
        'first' => LexGate::evaluate($one, $now),
        'second' => LexGate::evaluate($same, $now),
        'batch' => LexGate::evaluateBatch([$same, $one], $now),
    ];
} else {
    fwrite(STDERR, "scenario invalid\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;

<?php
declare(strict_types=1);

require __DIR__ . '/../src/AegisPosture.php';

use ControlBot\Security\AegisPosture;

function finding(string $id, string $severity = 'medium', array $overrides = []): array
{
    return array_replace([
        'finding_id' => $id,
        'category' => 'vulnerability',
        'severity' => $severity,
        'scope' => 'venture:condor',
        'state' => 'open',
        'evidence_refs' => ['controlbot:aegis/evidence-' . $id],
        'source_ref' => 'controlbot:aegis/source-scanner',
        'observed_at' => 2000,
        'freshness' => 'fresh',
    ], $overrides);
}

function identitySignal(string $metric, int $value, array $overrides = []): array
{
    return array_replace([
        'metric' => $metric,
        'scope' => 'venture:condor',
        'value' => $value,
        'source_type' => 'authoritative',
        'source_ref' => 'controlbot:aegis/source-identity',
        'observed_at' => 2000,
        'freshness' => 'fresh',
    ], $overrides);
}

function vulnerability(string $severity, int $count, array $overrides = []): array
{
    return array_replace([
        'severity' => $severity,
        'scope' => 'venture:condor',
        'count' => $count,
        'source_ref' => 'controlbot:aegis/source-vulnerability',
        'observed_at' => 2000,
        'freshness' => 'fresh',
    ], $overrides);
}

function basePosture(): array
{
    return [
        'version' => 1,
        'scope' => 'venture:condor',
        'findings' => [
            finding('finding-config', 'medium', [
                'category' => 'infrastructure_security',
                'evidence_refs' => ['controlbot:aegis/evidence-config'],
            ]),
        ],
        'identity_signals' => [
            identitySignal('mfa_coverage', 95),
            identitySignal('privileged_identities', 3),
        ],
        'vulnerability_signals' => [
            vulnerability('high', 0),
            vulnerability('critical', 0),
        ],
        'backup_signal' => [
            'scope' => 'venture:condor',
            'state' => 'available',
            'source_ref' => 'controlbot:aegis/source-backup',
            'observed_at' => 2000,
            'freshness' => 'fresh',
        ],
        'restore_signal' => [
            'scope' => 'venture:condor',
            'state' => 'verified',
            'source_ref' => 'controlbot:aegis/source-restore',
            'observed_at' => 1900,
            'freshness' => 'fresh',
        ],
    ];
}

function rejected(callable $call): string
{
    try {
        $call();
        return 'accepted';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$name = $argv[1] ?? '';

if ($name === 'findings') {
    $good = basePosture();
    $bad = basePosture();
    $bad['findings'][0]['source_ref'] = 'controlbot:aegis/api-token';
    $out = [
        'posture' => AegisPosture::summarize($good),
        'secret_rejected' => rejected(fn() => AegisPosture::summarize($bad)),
    ];
} elseif ($name === 'scope') {
    $mismatch = basePosture();
    $mismatch['findings'][0]['scope'] = 'venture:grindflow';
    $out = [
        'condor' => AegisPosture::summarize(basePosture()),
        'mismatch' => rejected(fn() => AegisPosture::summarize($mismatch)),
    ];
} elseif ($name === 'identity') {
    $input = basePosture();
    $input['identity_signals'] = [
        identitySignal('mfa_coverage', 90),
        identitySignal('privileged_identities', 4, [
            'source_type' => 'derived',
        ]),
        identitySignal('orphan_accounts', 2, [
            'freshness' => 'stale',
        ]),
    ];
    $out = AegisPosture::summarize($input);
} elseif ($name === 'vulnerability') {
    $input = basePosture();
    $input['vulnerability_signals'] = [
        vulnerability('high', 0, ['freshness' => 'unknown']),
        vulnerability('critical', 0),
    ];
    $out = AegisPosture::summarize($input);
} elseif ($name === 'unknown_finding') {
    $input = basePosture();
    $input['identity_signals'][] = identitySignal('orphan_accounts', 0);
    $input['vulnerability_signals'] = [
        vulnerability('info', 0),
        vulnerability('low', 0),
        vulnerability('medium', 0),
        vulnerability('high', 0),
        vulnerability('critical', 0),
    ];
    $input['findings'] = [
        finding('finding-unknown-critical', 'critical', [
            'state' => 'unknown',
        ]),
    ];
    $out = AegisPosture::summarize($input);
} elseif ($name === 'missing_certainty') {
    $identityMissing = basePosture();
    $identityMissing['identity_signals'] = [
        identitySignal('mfa_coverage', 100),
        identitySignal('privileged_identities', 2),
    ];

    $vulnerabilityMissing = basePosture();
    $vulnerabilityMissing['identity_signals'][] = identitySignal('orphan_accounts', 0);
    $vulnerabilityMissing['vulnerability_signals'] = [
        vulnerability('high', 0),
        vulnerability('critical', 0),
    ];

    $out = [
        'identity_missing' => AegisPosture::summarize($identityMissing),
        'vulnerability_missing' => AegisPosture::summarize($vulnerabilityMissing),
    ];
} elseif ($name === 'continuity') {
    $input = basePosture();
    $input['restore_signal'] = [
        'scope' => 'venture:condor',
        'state' => 'unknown',
        'source_ref' => 'controlbot:aegis/source-restore',
        'observed_at' => 1900,
        'freshness' => 'unknown',
    ];
    $out = AegisPosture::summarize($input);
} elseif ($name === 'dedupe') {
    $input = basePosture();
    $input['findings'] = [
        finding('finding-shared', 'high', [
            'evidence_refs' => ['controlbot:aegis/evidence-a'],
        ]),
        finding('finding-shared', 'high', [
            'evidence_refs' => ['controlbot:aegis/evidence-b'],
        ]),
    ];
    $out = AegisPosture::summarize($input);
} elseif ($name === 'deterministic') {
    $input = basePosture();
    $input['findings'][] = finding('finding-access', 'low', [
        'category' => 'identity_access',
        'evidence_refs' => ['controlbot:aegis/evidence-access'],
    ]);
    $first = AegisPosture::summarize($input);

    $reordered = $input;
    $reordered['findings'] = array_reverse($reordered['findings']);
    $reordered['identity_signals'] = array_reverse($reordered['identity_signals']);
    $reordered['vulnerability_signals'] = array_reverse($reordered['vulnerability_signals']);
    $second = AegisPosture::summarize($reordered);

    $out = ['first' => $first, 'second' => $second, 'same' => $first === $second];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

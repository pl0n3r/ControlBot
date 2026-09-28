<?php
declare(strict_types=1);

require __DIR__ . '/../src/AegisEvidence.php';

use ControlBot\Security\AegisEvidence;

function obligation(string $id, string $state = 'compliant', array $overrides = []): array
{
    return array_replace([
        'obligation_id' => $id,
        'state' => $state,
        'scope' => 'venture:condor',
        'evidence_refs' => ['controlbot:aegis/evidence-' . $id],
        'source_ref' => 'controlbot:aegis/source-compliance',
        'observed_at' => 2000,
        'freshness' => 'fresh',
        'version' => 'obligation-v1',
        'owner_ref' => 'controlbot:aegis/owner-security',
        'review_at' => 3000,
        'expires_at' => 4000,
    ], $overrides);
}

function backup(array $overrides = []): array
{
    return array_replace([
        'scope' => 'venture:condor',
        'state' => 'healthy',
        'evidence_refs' => ['controlbot:aegis/evidence-backup'],
        'source_ref' => 'controlbot:aegis/source-backup',
        'observed_at' => 2000,
        'freshness' => 'fresh',
    ], $overrides);
}

function restore(array $overrides = []): array
{
    return array_replace([
        'scope' => 'venture:condor',
        'state' => 'verified',
        'evidence_refs' => ['controlbot:aegis/evidence-restore'],
        'source_ref' => 'controlbot:aegis/source-restore',
        'observed_at' => 1900,
        'freshness' => 'fresh',
    ], $overrides);
}

function incident(string $id, array $overrides = []): array
{
    return array_replace([
        'incident_id' => $id,
        'severity' => 'high',
        'capabilities' => ['checkout', 'inventory'],
        'affected_scopes' => ['venture:condor', 'project:storefront'],
        'evidence_refs' => ['controlbot:aegis/evidence-' . $id],
        'source_ref' => 'controlbot:aegis/source-incident',
        'observed_at' => 2100,
        'freshness' => 'fresh',
    ], $overrides);
}

function baseEvidence(): array
{
    return [
        'version' => 1,
        'scope' => 'venture:condor',
        'compliance' => [
            obligation('privacy-register'),
            obligation('backup-policy', 'gap'),
        ],
        'backup_evidence' => backup(),
        'restore_evidence' => restore(),
        'incidents' => [
            incident('incident-api'),
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

if ($name === 'compliance') {
    $out = AegisEvidence::normalize(baseEvidence());
} elseif ($name === 'stale') {
    $input = baseEvidence();
    $input['compliance'] = [
        obligation('privacy-register', 'compliant', ['freshness' => 'stale']),
    ];
    $out = AegisEvidence::normalize($input);
} elseif ($name === 'continuity') {
    $input = baseEvidence();
    $input['restore_evidence'] = restore(['state' => 'unknown', 'freshness' => 'unknown']);
    $out = AegisEvidence::normalize($input);
} elseif ($name === 'impact') {
    $input = baseEvidence();
    $input['incidents'] = [
        incident('incident-api', [
            'capabilities' => ['inventory', 'checkout', 'inventory'],
            'affected_scopes' => ['project:storefront', 'venture:condor', 'institution:pl0n3r'],
        ]),
    ];
    $out = AegisEvidence::normalize($input);
} elseif ($name === 'obligation') {
    $input = baseEvidence();
    $input['compliance'] = [
        obligation('privacy-register', 'gap', [
            'version' => 'resolution-001-v3',
            'owner_ref' => 'controlbot:aegis/owner-legal',
            'review_at' => 5000,
            'expires_at' => 7000,
        ]),
    ];
    $out = AegisEvidence::normalize($input);
} elseif ($name === 'sensitive') {
    $ref = baseEvidence();
    $ref['compliance'][0]['evidence_refs'] = ['controlbot:aegis/api-token-value'];
    $payload = baseEvidence();
    $payload['incidents'][0]['payload'] = ['secret' => 'raw'];
    $out = [
        'ref_rejected' => rejected(fn() => AegisEvidence::normalize($ref)),
        'payload_rejected' => rejected(fn() => AegisEvidence::normalize($payload)),
    ];
} elseif ($name === 'deterministic') {
    $input = baseEvidence();
    $input['compliance'][] = obligation('access-review', 'not_applicable', [
        'evidence_refs' => ['controlbot:aegis/evidence-z', 'controlbot:aegis/evidence-a'],
    ]);
    $input['incidents'][] = incident('incident-worker', [
        'capabilities' => ['jobs'],
        'affected_scopes' => ['project:workers'],
    ]);
    $first = AegisEvidence::normalize($input);

    $reordered = $input;
    $reordered['compliance'] = array_reverse($reordered['compliance']);
    $reordered['incidents'] = array_reverse($reordered['incidents']);
    $reordered['incidents'][1]['capabilities'] = array_reverse($reordered['incidents'][1]['capabilities']);
    $reordered['incidents'][1]['affected_scopes'] = array_reverse($reordered['incidents'][1]['affected_scopes']);
    $second = AegisEvidence::normalize($reordered);

    $out = ['first' => $first, 'second' => $second, 'same' => $first === $second];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

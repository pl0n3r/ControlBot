<?php
declare(strict_types=1);

require __DIR__ . '/../src/VentureIdentity.php';
require __DIR__ . '/../src/DecisionRights.php';
require __DIR__ . '/../src/AegisRemediation.php';

use ControlBot\Security\AegisRemediation;

function remediationIdentity(): array
{
    return [
        'version' => 1,
        'identity_id' => 'agent-aegis',
        'kind' => 'agent',
        'display_name' => 'AEGIS Agent',
        'state' => 'active',
        'source_ref' => 'controlbot:identity/agent-aegis',
        'observed_at' => 1000,
    ];
}

function remediationContext(array $overrides = []): array
{
    return array_replace([
        'identity' => remediationIdentity(),
        'scope' => 'venture:condor',
        'active_policy_refs' => ['controlbot:policy/aegis-safe-remediation'],
    ], $overrides);
}

function remediationGrant(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'grant_id' => 'grant-aegis',
        'identity_id' => 'agent-aegis',
        'role' => 'operator',
        'capability' => 'restart-worker',
        'scope' => 'venture:condor',
        'authority_level' => 'L0_AI_AUTONOMOUS',
        'policy_ref' => 'controlbot:policy/aegis-safe-remediation',
        'budget_limit' => 0,
        'granted_at' => 900,
        'expires_at' => 3000,
    ], $overrides);
}

function remediationProposal(array $overrides = []): array
{
    return array_replace([
        'action' => 'restart-worker',
        'capability' => 'restart-worker',
        'scope' => 'venture:condor',
        'reversible' => true,
        'blast_radius' => 'low',
        'risk_categories' => [],
        'preauthorized' => true,
        'required_authority_level' => 'L0_AI_AUTONOMOUS',
        'policy_ref' => 'controlbot:policy/aegis-safe-remediation',
        'budget_amount' => null,
    ], $overrides);
}

function remediationInput(array $overrides = []): array
{
    return array_replace([
        'version' => 1,
        'finding' => [
            'finding_id' => 'finding-worker-down',
            'scope' => 'venture:condor',
        ],
        'proposal' => remediationProposal(),
        'verified_context' => remediationContext(),
        'grant' => remediationGrant(),
        'verification' => [
            'status' => 'not_attempted',
            'evidence_refs' => [],
        ],
    ], $overrides);
}

$name = $argv[1] ?? '';
$now = 1200;

if ($name === 'auto') {
    $out = AegisRemediation::plan(remediationInput(), $now);
} elseif ($name === 'authority') {
    $unknownPolicy = remediationInput([
        'verified_context' => remediationContext(['active_policy_refs' => []]),
    ]);
    $insufficient = remediationInput([
        'proposal' => remediationProposal(['required_authority_level' => 'L2_VENTURE_ADMIN']),
        'grant' => remediationGrant(['authority_level' => 'L0_AI_AUTONOMOUS']),
    ]);
    $mismatch = remediationInput([
        'proposal' => remediationProposal(['policy_ref' => 'controlbot:policy/other-policy']),
    ]);
    $groupScope = remediationInput([
        'finding' => [
            'finding_id' => 'finding-group-policy',
            'scope' => 'group:pl0n3r',
        ],
        'proposal' => remediationProposal(['scope' => 'group:pl0n3r']),
        'verified_context' => remediationContext(['scope' => 'group:pl0n3r']),
        'grant' => remediationGrant(['scope' => 'group:pl0n3r']),
    ]);
    $shortScopeRejected = false;
    try {
        AegisRemediation::plan(remediationInput([
            'finding' => [
                'finding_id' => 'finding-short-scope',
                'scope' => 'venture:a',
            ],
            'proposal' => remediationProposal(['scope' => 'venture:a']),
            'verified_context' => remediationContext(['scope' => 'venture:a']),
            'grant' => remediationGrant(['scope' => 'venture:a']),
        ]), $now);
    } catch (Throwable) {
        $shortScopeRejected = true;
    }
    $out = [
        'unknown_policy' => AegisRemediation::plan($unknownPolicy, $now),
        'insufficient' => AegisRemediation::plan($insufficient, $now),
        'policy_mismatch' => AegisRemediation::plan($mismatch, $now),
        'group_scope' => AegisRemediation::plan($groupScope, $now),
        'short_scope_rejected' => $shortScopeRejected,
    ];
} elseif ($name === 'high_risk') {
    $out = [];
    foreach (['privileged', 'secrets', 'deletion', 'money', 'privacy', 'legal'] as $risk) {
        $out[$risk] = AegisRemediation::plan(remediationInput([
            'proposal' => remediationProposal(['risk_categories' => [$risk]]),
        ]), $now);
    }
    $out['irreversible'] = AegisRemediation::plan(remediationInput([
        'proposal' => remediationProposal(['reversible' => false]),
    ]), $now);
    $out['high_blast'] = AegisRemediation::plan(remediationInput([
        'proposal' => remediationProposal(['blast_radius' => 'high']),
    ]), $now);
} elseif ($name === 'idempotency') {
    $one = remediationInput();
    $same = remediationInput();
    $different = remediationInput([
        'proposal' => remediationProposal(['action' => 'restart-cron']),
    ]);
    $plans = AegisRemediation::planBatch([$same, $different, $one], $now);
    $out = [
        'first' => AegisRemediation::plan($one, $now),
        'second' => AegisRemediation::plan($same, $now),
        'different' => AegisRemediation::plan($different, $now),
        'batch' => $plans,
    ];
} elseif ($name === 'verification') {
    $pending = AegisRemediation::plan(remediationInput([
        'verification' => ['status' => 'attempted', 'evidence_refs' => []],
    ]), $now);
    $verified = AegisRemediation::plan(remediationInput([
        'verification' => [
            'status' => 'verified',
            'evidence_refs' => ['controlbot:aegis/verification-worker'],
        ],
    ]), $now);
    $rejected = false;
    try {
        AegisRemediation::plan(remediationInput([
            'verification' => ['status' => 'verified', 'evidence_refs' => []],
        ]), $now);
    } catch (Throwable) {
        $rejected = true;
    }
    $out = [
        'pending' => $pending,
        'verified' => $verified,
        'verified_without_evidence_rejected' => $rejected,
    ];
} elseif ($name === 'no_provider') {
    $out = AegisRemediation::plan(remediationInput(), $now);
} elseif ($name === 'deterministic') {
    $firstInput = remediationInput([
        'proposal' => remediationProposal(['risk_categories' => ['privacy', 'legal']]),
        'verification' => [
            'status' => 'verified',
            'evidence_refs' => [
                'controlbot:aegis/z-evidence',
                'controlbot:aegis/a-evidence',
                'controlbot:aegis/z-evidence',
            ],
        ],
    ]);
    $secondInput = $firstInput;
    $secondInput['proposal']['risk_categories'] = array_reverse(
        $secondInput['proposal']['risk_categories'],
    );
    $secondInput['verification']['evidence_refs'] = array_reverse(
        $secondInput['verification']['evidence_refs'],
    );
    $first = AegisRemediation::plan($firstInput, $now);
    $second = AegisRemediation::plan($secondInput, $now);
    $out = ['first' => $first, 'second' => $second, 'same' => $first === $second];
} else {
    fwrite(STDERR, "scenario invalid\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

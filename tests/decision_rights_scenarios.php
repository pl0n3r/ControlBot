<?php
declare(strict_types=1);

require __DIR__ . '/../src/VentureIdentity.php';
require __DIR__ . '/../src/DecisionRights.php';

use ControlBot\Business\DecisionRights;

$name = $argv[1] ?? '';
const NOW = 2000;
const POLICY = 'controlbot:policy/business-os-v1';

function identity(string $id = 'identity-admin', string $state = 'active'): array
{
    return [
        'version' => 1, 'identity_id' => $id, 'kind' => 'human',
        'display_name' => strtoupper(str_replace('-', ' ', $id)), 'state' => $state,
        'source_ref' => 'controlbot:identity/' . $id, 'observed_at' => 1900,
    ];
}

function context(
    string $id = 'identity-admin',
    string $scope = 'venture:grindflow',
    array $policies = [POLICY],
    string $state = 'active',
): array {
    return ['identity' => identity($id, $state), 'scope' => $scope, 'active_policy_refs' => $policies];
}

function grant(
    string $id = 'identity-admin',
    string $scope = 'venture:grindflow',
    string $capability = 'venture.manage',
    string $authority = 'L2_VENTURE_ADMIN',
    ?float $budget = 1000.0,
    ?int $expires = 3000,
    array $extra = [],
): array {
    return array_replace([
        'version' => 1, 'grant_id' => 'grant-main', 'identity_id' => $id,
        'role' => $authority === 'L4_OWNER' ? 'owner' : 'venture_admin',
        'capability' => $capability, 'scope' => $scope, 'authority_level' => $authority,
        'policy_ref' => POLICY, 'budget_limit' => $budget, 'granted_at' => 1800, 'expires_at' => $expires,
    ], $extra);
}

function action(
    string $capability = 'venture.manage',
    string $authority = 'L2_VENTURE_ADMIN',
    ?float $budget = null,
    array $extra = [],
): array {
    return array_replace([
        'capability' => $capability, 'required_authority_level' => $authority, 'budget_amount' => $budget,
    ], $extra);
}

if ($name === 'allow-exact') {
    $out = [
        'allowed' => DecisionRights::evaluate(context(), grant(), action(), NOW),
        'wrong_capability' => DecisionRights::evaluate(context(), grant(), action('venture.delete'), NOW),
    ];
} elseif ($name === 'invalid') {
    $out = [
        'unknown_scope' => DecisionRights::evaluate(context(scope: 'venture:*'), grant(), action(), NOW),
        'expired_grant' => DecisionRights::evaluate(context(), grant(expires: 1900), action(), NOW),
        'unknown_authority' => DecisionRights::evaluate(context(), grant(), action(authority: 'L9_UNKNOWN'), NOW),
    ];
} elseif ($name === 'cross-venture') {
    $out = ['result' => DecisionRights::evaluate(
        context(scope: 'venture:condor'), grant(scope: 'venture:grindflow'), action(), NOW
    )];
} elseif ($name === 'restrictions') {
    $out = [
        'policy' => DecisionRights::evaluate(context(policies: []), grant(), action(), NOW),
        'budget' => DecisionRights::evaluate(context(), grant(), action(budget: 1200.0), NOW),
        'authority' => DecisionRights::evaluate(
            context(), grant(authority: 'L1_OPERATOR', extra: ['role' => 'operator']),
            action(authority: 'L2_VENTURE_ADMIN'), NOW
        ),
    ];
} elseif ($name === 'owner-decision') {
    $out = ['result' => DecisionRights::evaluate(
        context('identity-owner', 'group:group-pl0n3r'),
        grant('identity-owner', 'group:group-pl0n3r', 'group.policy.change', 'L4_OWNER', null, null),
        action('group.policy.change', 'L4_OWNER'),
        NOW
    )];
} elseif ($name === 'untrusted') {
    $secret = 'super-secret-client-claim';
    $badAction = action(extra: ['identity_id' => 'identity-owner', 'scope' => 'group:group-pl0n3r', 'token' => $secret]);
    $badContext = [...context(), 'token' => $secret];
    $first = DecisionRights::evaluate(context(), grant(), $badAction, NOW);
    $second = DecisionRights::evaluate($badContext, grant(), action(), NOW);
    $out = ['action_claims' => $first, 'context_secret' => $second,
        'leaked' => str_contains(json_encode([$first, $second], JSON_THROW_ON_ERROR), $secret)];
} elseif ($name === 'deterministic') {
    $ctx = context('identity-other', 'venture:condor', []);
    $first = DecisionRights::evaluate($ctx, grant(), action('venture.read'), NOW);
    $second = DecisionRights::evaluate($ctx, grant(), action('venture.read'), NOW);
    $out = ['first' => $first, 'second' => $second, 'same' => $first === $second];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

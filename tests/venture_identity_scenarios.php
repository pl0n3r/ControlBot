<?php
declare(strict_types=1);

require __DIR__ . '/../src/VentureIdentity.php';

use ControlBot\Business\VentureIdentity;

$name = $argv[1] ?? '';
const NOW = 2000;

function identity(string $id, string $kind = 'human', array $extra = []): array
{
    return array_replace([
        'version' => 1,
        'identity_id' => $id,
        'kind' => $kind,
        'display_name' => strtoupper(str_replace('-', ' ', $id)),
        'state' => 'active',
        'source_ref' => 'controlbot:identity/' . $id,
        'observed_at' => 1900,
    ], $extra);
}

function venture(string $id, string $responsible, string $project): array
{
    return [
        'version' => 1,
        'venture_id' => $id,
        'group_id' => 'group-pl0n3r',
        'slug' => $id,
        'title' => ucfirst($id),
        'strategy_role' => $id === 'condor' ? 'core-business' : ($id === 'grindflow' ? 'secondary-business' : 'brand-events'),
        'state' => 'active',
        'responsible_identity_id' => $responsible,
        'project_refs' => ['controlbot:project/' . $project],
    ];
}

function grant(
    string $id,
    string $identityId,
    string $scope,
    string $authority = 'L2_VENTURE_ADMIN',
    ?int $expiresAt = 3000,
    array $extra = [],
): array {
    return array_replace([
        'version' => 1,
        'grant_id' => $id,
        'identity_id' => $identityId,
        'role' => $authority === 'L4_OWNER' ? 'owner' : 'venture_admin',
        'capability' => 'venture.manage',
        'scope' => $scope,
        'authority_level' => $authority,
        'policy_ref' => 'controlbot:policy/business-os-v1',
        'budget_limit' => 1000.0,
        'granted_at' => 1800,
        'expires_at' => $expiresAt,
    ], $extra);
}

function event(string $id, string $action, array $grant, string $actor = 'identity-owner'): array
{
    return [
        'version' => 1,
        'event_id' => $id,
        'action' => $action,
        'grant_id' => $grant['grant_id'],
        'identity_id' => $grant['identity_id'],
        'actor_identity_id' => $actor,
        'reason' => $action === 'revoke' ? 'Cambio de responsabilidad del Venture.' : 'Delegación aprobada.',
        'scope' => $grant['scope'],
        'occurred_at' => $action === 'revoke' ? 1950 : 1800,
        'expires_at' => $grant['expires_at'],
    ];
}

function blocked(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

if ($name === 'distinct-admins') {
    $admins = [
        'condor' => 'identity-condor-admin',
        'grindflow' => 'identity-grindflow-admin',
        'brvtal' => 'identity-brvtal-admin',
    ];
    $ventures = [];
    $grants = [];
    foreach ($admins as $slug => $admin) {
        $ventures[$slug] = VentureIdentity::normalizeVenture(venture($slug, $admin, 'project-' . $slug));
        $grants[] = grant('grant-' . $slug, $admin, 'venture:' . $slug);
    }
    $scoped = [];
    foreach (array_keys($admins) as $slug) {
        $scoped[$slug] = array_column(VentureIdentity::scopedGrants($grants, 'venture:' . $slug, NOW), 'identity_id');
    }
    $out = ['ventures' => $ventures, 'scoped' => $scoped];
} elseif ($name === 'authority') {
    $owner = VentureIdentity::normalizeIdentity(identity('identity-owner'));
    $admin = VentureIdentity::normalizeIdentity(identity('identity-grindflow-admin'));
    $group = VentureIdentity::normalizeGroup([
        'version' => 1,
        'group_id' => 'group-pl0n3r',
        'title' => 'pl0n3r Group',
        'owner_identity_id' => $owner['identity_id'],
        'venture_ids' => ['brvtal', 'condor', 'grindflow'],
    ]);
    $out = [
        'group' => $group,
        'owner' => VentureIdentity::normalizeGrant(grant(
            'grant-owner',
            $owner['identity_id'],
            'group:group-pl0n3r',
            'L4_OWNER',
            null,
            ['budget_limit' => null],
        ), NOW),
        'venture_admin' => VentureIdentity::normalizeGrant(grant(
            'grant-grindflow-admin',
            $admin['identity_id'],
            'venture:grindflow',
        ), NOW),
    ];
} elseif ($name === 'identity-kinds') {
    $out = [
        'kinds' => [
            VentureIdentity::normalizeIdentity(identity('identity-human', 'human'))['kind'],
            VentureIdentity::normalizeIdentity(identity('identity-agent', 'agent'))['kind'],
            VentureIdentity::normalizeIdentity(identity('identity-service', 'service'))['kind'],
        ],
        'blocked_sensitive' => [],
    ];
    foreach (['password', 'password_hash', 'token', 'session_cookie', 'otp', 'recovery_code', 'two_factor_secret', 'reset_token', 'session_id'] as $field) {
        $out['blocked_sensitive'][$field] = blocked(static function () use ($field): void {
            $raw = identity('identity-sensitive');
            $raw[$field] = 'never-store-this';
            VentureIdentity::normalizeIdentity($raw);
        });
    }
} elseif ($name === 'invalid-grants') {
    $base = grant('grant-valid', 'identity-admin', 'venture:grindflow');
    $cases = [];
    foreach ([
        'unknown-authority' => [...$base, 'authority_level' => 'UNKNOWN'],
        'expired' => [...$base, 'expires_at' => 1900],
        'invalid-scope' => [...$base, 'scope' => 'venture:*'],
        'missing-capability' => array_diff_key($base, ['capability' => true]),
        'secret-field' => [...$base, 'token' => 'secret'],
    ] as $key => $raw) {
        $cases[$key] = blocked(static fn (): array => VentureIdentity::normalizeGrant($raw, NOW));
    }
    $out = ['blocked' => $cases];
} elseif ($name === 'revoke') {
    $id = VentureIdentity::normalizeIdentity(identity('identity-admin'));
    $target = grant('grant-grindflow', $id['identity_id'], 'venture:grindflow');
    $other = grant('grant-condor-view', $id['identity_id'], 'venture:condor', 'L1_OPERATOR', 3000, [
        'role' => 'viewer',
        'capability' => 'venture.read',
        'budget_limit' => null,
    ]);
    $prior = event('event-grant-grindflow', 'grant', $target);
    $result = VentureIdentity::revokeGrant(
        $id,
        [$target, $other],
        [$prior],
        $target['grant_id'],
        event('event-revoke-grindflow', 'revoke', $target),
        NOW,
    );
    $out = $result;
} elseif ($name === 'events') {
    $g = grant('grant-grindflow', 'identity-admin', 'venture:grindflow');
    $valid = VentureIdentity::normalizeEvent(event('event-grant-grindflow', 'grant', $g));
    $future = event('event-future', 'grant', $g);
    $future['occurred_at'] = 4000;
    $future['expires_at'] = 5000;
    $badExpiry = event('event-bad-expiry', 'grant', $g);
    $badExpiry['expires_at'] = 1700;
    $beforeGrant = event('event-before-grant', 'revoke', $g);
    $beforeGrant['occurred_at'] = 1700;
    $out = [
        'valid' => $valid,
        'future_history_blocked' => blocked(static fn (): array => VentureIdentity::revokeGrant(
            identity('identity-admin'),
            [$g],
            [$future],
            $g['grant_id'],
            event('event-revoke-grindflow', 'revoke', $g),
            NOW,
        )),
        'bad_expiry_blocked' => blocked(static fn (): array => VentureIdentity::normalizeEvent($badExpiry)),
        'before_grant_revoke_blocked' => blocked(static fn (): array => VentureIdentity::revokeGrant(
            identity('identity-admin'),
            [$g],
            [event('event-grant-grindflow', 'grant', $g)],
            $g['grant_id'],
            $beforeGrant,
            NOW,
        )),
    ];
} elseif ($name === 'optional-fields') {
    $grant = grant('grant-no-expiry', 'identity-admin', 'venture:grindflow', 'L2_VENTURE_ADMIN', null);
    unset($grant['budget_limit'], $grant['expires_at']);
    $event = event('event-no-expiry', 'grant', [
        ...grant('grant-event-no-expiry', 'identity-admin', 'venture:grindflow', 'L2_VENTURE_ADMIN', null),
        'expires_at' => null,
    ]);
    unset($event['expires_at']);
    $out = [
        'grant' => VentureIdentity::normalizeGrant($grant, NOW),
        'event' => VentureIdentity::normalizeEvent($event),
    ];
} elseif ($name === 'history-capacity') {
    $g = grant('grant-capacity', 'identity-admin', 'venture:grindflow');
    $history = [];
    for ($i = 1; $i <= 500; $i++) {
        $row = event('event-history-' . $i, 'grant', $g);
        $row['occurred_at'] = 1800;
        $history[] = $row;
    }
    $out = [
        'blocked' => blocked(static fn (): array => VentureIdentity::revokeGrant(
            identity('identity-admin'),
            [$g],
            $history,
            $g['grant_id'],
            event('event-revoke-capacity', 'revoke', $g),
            NOW,
        )),
    ];
} elseif ($name === 'deterministic') {
    $raw = grant('grant-grindflow', 'identity-admin', 'venture:grindflow');
    $first = VentureIdentity::normalizeGrant($raw, NOW);
    $second = VentureIdentity::normalizeGrant($raw, NOW);
    $out = [
        'same' => $first === $second,
        'extra_blocked' => blocked(static fn (): array => VentureIdentity::normalizeGrant([...$raw, 'unexpected' => true], NOW)),
        'duplicate_blocked' => blocked(static fn (): array => VentureIdentity::scopedGrants([$raw, $raw], 'venture:grindflow', NOW)),
    ];
} else {
    fwrite(STDERR, "scenario inválido\n");
    exit(2);
}

echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;

<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

/**
 * Fixture-only issuer for an owner-gated approval boundary.
 * Deliberately never issues authority, a grant or an executable decision.
 * The caller must keep its HMAC key and trusted context server-side.
 */
final class GitHubIntentOfflineIssuer
{
    private const TYPES = [
        'issue.create' => 'github.issue.write',
        'issue.update' => 'github.issue.write',
        'issue.close' => 'github.issue.write',
        'issue.reserve' => 'github.issue.write',
        'issue.release' => 'github.issue.write',
        'pr.review' => 'github.pr.review',
        'pr.merge' => 'github.pr.merge',
        'workflow.dispatch' => 'github.workflow.dispatch',
        'release.approve' => 'github.release.approve',
        'project.freeze' => 'github.project.write',
        'project.unfreeze' => 'github.project.write',
    ];

    public static function issue(
        array $requirements,
        array $approval,
        array $trusted,
        array $seenNonces,
        array $revokedNonces,
        int $now,
        string $fixtureKey
    ): array {
        try {
            self::exactFields($requirements, [
                'version', 'state', 'type', 'capability', 'scope',
                'scope_ref', 'scope_parameters', 'approval_class',
                'authorized', 'grant_issued', 'approval_issued',
                'approval_verified', 'execution',
            ]);
            self::exactFields($approval, [
                'origin', 'test_only', 'owner', 'owner_verified',
                'action_type', 'scope_ref', 'approved', 'approved_at',
                'approval_ref', 'revoked',
            ]);
            self::exactFields($trusted, [
                'origin', 'mode', 'issuer', 'owner', 'project_ref',
                'repository_ref', 'nonce', 'ttl_seconds',
            ]);

            $type = $requirements['type'];
            if (!is_string($type) || !isset(self::TYPES[$type])
                || $requirements['version'] !== 1
                || $requirements['state'] !== 'requirements'
                || $requirements['capability'] !== self::TYPES[$type]
                || $requirements['approval_class'] !== 'owner_required') {
                return self::deny('invalid_catalog');
            }
            foreach (['authorized', 'grant_issued', 'approval_issued',
                'approval_verified', 'execution'] as $key) {
                if ($requirements[$key] !== false) {
                    return self::deny('invalid_authority');
                }
            }
            $scope = in_array($type, ['project.freeze', 'project.unfreeze'], true)
                ? 'project' : 'repository';
            $ref = $scope === 'project' ? $trusted['project_ref'] : $trusted['repository_ref'];
            if (!is_string($ref) || $ref === ''
                || $requirements['scope'] !== $scope
                || $requirements['scope_ref'] !== $ref
                || $requirements['scope_parameters'] !== [$scope . '_ref' => $ref]) {
                return self::deny('invalid_scope');
            }
            if ($trusted['origin'] !== 'server' || $trusted['mode'] !== 'offline_test_only'
                || !is_string($trusted['owner']) || $trusted['owner'] === ''
                || !is_string($trusted['issuer']) || $trusted['issuer'] === ''
                || !is_string($trusted['project_ref']) || $trusted['project_ref'] === ''
                || !is_string($trusted['repository_ref']) || $trusted['repository_ref'] === ''
                || !is_string($trusted['nonce'])
                || !preg_match('/^[a-f0-9]{32}$/D', $trusted['nonce'])
                || !is_int($trusted['ttl_seconds'])
                || $trusted['ttl_seconds'] < 1 || $trusted['ttl_seconds'] > 300
                || $now < 1 || strlen($fixtureKey) < 32) {
                return self::deny('invalid_fixture');
            }
            if ($approval['origin'] !== 'server' || $approval['test_only'] !== true
                || $approval['owner_verified'] !== true || $approval['approved'] !== true
                || $approval['revoked'] !== false
                || $approval['owner'] !== $trusted['owner']
                || $approval['action_type'] !== $type
                || $approval['scope_ref'] !== $ref
                || !is_string($approval['approval_ref'])
                || !preg_match('/^fixture:[a-f0-9]{16}$/D', $approval['approval_ref'])
                || !is_int($approval['approved_at'])
                || $approval['approved_at'] > $now
                || $approval['approved_at'] < $now - 300) {
                return self::deny('approval_required');
            }
            if (!array_is_list($seenNonces) || !array_is_list($revokedNonces)
                || count($seenNonces) > 1000 || count($revokedNonces) > 1000
                || in_array($trusted['nonce'], $seenNonces, true)
                || in_array($trusted['nonce'], $revokedNonces, true)) {
                return self::deny('revoked_or_replayed');
            }
            // The verifier signs exactly these eight positional fields.
            $proof = [
                'capability' => $requirements['capability'],
                'scope' => $scope, 'scope_ref' => $ref,
                'owner' => $trusted['owner'],
                'issued_at' => $now,
                'expires_at' => $now + $trusted['ttl_seconds'],
                'nonce' => $trusted['nonce'], 'issuer' => $trusted['issuer'],
            ];
            $canonical = json_encode(array_values($proof),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $proof['signature'] = hash_hmac('sha256', $canonical, $fixtureKey);
            return self::deny('offline_only', $proof);
        } catch (\Throwable) {
            return self::deny('invalid_fixture');
        }
    }

    private static function exactFields(array $data, array $expected): void
    {
        if (array_is_list($data)) {
            throw new \InvalidArgumentException('invalid_structure');
        }
        $actual = array_keys($data);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('invalid_structure');
        }
    }

    private static function deny(string $reason, ?array $proof = null): array
    {
        return [
            'decision' => 'deny', 'reason' => $reason, 'proof' => $proof,
            'authorized' => false, 'grant_issued' => false,
            'approval_issued' => false, 'approval_verified' => false,
            'execution' => false,
        ];
    }
}

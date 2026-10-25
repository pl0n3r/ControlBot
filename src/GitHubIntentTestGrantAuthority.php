<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

require_once __DIR__ . '/GitHubIntentOfflineIssuer.php';
require_once __DIR__ . '/GitHubIntentGrantVerification.php';

/**
 * Offline TEST fixture only. This is not a runtime grant or a GitHub adapter.
 * Trusted inputs and fixture key must be supplied by a test harness, never HTTP.
 */
final class GitHubIntentTestGrantAuthority
{
    private const CATALOG = [
        'issue.create' => 'github.issue.write',
        'issue.update' => 'github.issue.write',
        'issue.close' => 'github.issue.write',
        'issue.reserve' => 'github.issue.write',
        'issue.release' => 'github.issue.write',
        'pr.review' => 'github.pr.review',
        'pr.merge' => 'github.pr.merge',
        'release.approve' => 'github.release.approve',
        'workflow.dispatch' => 'github.workflow.dispatch',
        'project.freeze' => 'github.project.write',
        'project.unfreeze' => 'github.project.write',
    ];

    public static function evaluate(
        array $requirements,
        array $approval,
        array $trusted,
        array $seenNonces,
        array $revokedNonces,
        int $now,
        string $fixtureKey
    ): array {
        try {
            $type = $requirements['type'] ?? null;
            if (!is_string($type) || !isset(self::CATALOG[$type])
                || ($requirements['capability'] ?? null) !== self::CATALOG[$type]
                || ($trusted['origin'] ?? null) !== 'server'
                || ($trusted['mode'] ?? null) !== 'offline_test_only') {
                return self::deny('invalid_catalog_or_mode');
            }

            // The issuer rejects untrusted owner approvals, scope, TTL and nonce.
            $issued = GitHubIntentOfflineIssuer::issue(
                $requirements, $approval, $trusted, $seenNonces,
                $revokedNonces, $now, $fixtureKey
            );
            $proof = $issued['proof'] ?? null;
            if (($issued['decision'] ?? null) !== 'deny'
                || ($issued['reason'] ?? null) !== 'offline_only'
                || ($issued['execution'] ?? null) !== false
                || !is_array($proof)) {
                return self::deny('approval_or_fixture_invalid');
            }

            // Independently verify the HMAC against the exact server fixture.
            $verified = GitHubIntentGrantVerification::inspect(
                $requirements, $proof,
                [
                    'origin' => 'server',
                    'issuer' => $trusted['issuer'],
                    'owner' => $trusted['owner'],
                    'project_ref' => $trusted['project_ref'],
                    'repository_ref' => $trusted['repository_ref'],
                ],
                $seenNonces, $now, $fixtureKey
            );
            if (($verified['proof_valid'] ?? null) !== true
                || ($verified['reason'] ?? null) !== 'offline_only'
                || ($verified['decision'] ?? null) !== 'deny'
                || ($verified['execution'] ?? null) !== false) {
                return self::deny('proof_not_verified');
            }
            return [
                'decision' => 'deny',
                'reason' => 'verified_test_only',
                'catalog_class' => 'owner_required',
                'test_grant_valid' => true,
                'fixture_proof' => $proof,
                'runtime_authorized' => false,
                'authorized' => false,
                'grant_issued' => false,
                'approval_issued' => false,
                'approval_verified' => false,
                'execution' => false,
            ];
        } catch (\Throwable) {
            return self::deny('invalid_fixture');
        }
    }

    private static function deny(string $reason): array
    {
        return [
            'decision' => 'deny', 'reason' => $reason,
            'catalog_class' => 'forbidden',
            'test_grant_valid' => false, 'fixture_proof' => null,
            'runtime_authorized' => false, 'authorized' => false,
            'grant_issued' => false, 'approval_issued' => false,
            'approval_verified' => false, 'execution' => false,
        ];
    }
}

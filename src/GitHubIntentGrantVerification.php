<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

require_once __DIR__ . '/CapabilityPolicy.php';

use ControlBot\Production\CapabilityPolicy;

/**
 * Validación PURA de un candidato de grant, no autoridad de ejecución.
 * Contexto, reloj, claves y nonces vistos deben proceder del servidor.
 */
final class GitHubIntentGrantVerification
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
    private const PROOF_FIELDS = [
        'capability', 'scope', 'scope_ref', 'owner', 'issued_at',
        'expires_at', 'nonce', 'issuer', 'signature',
    ];

    public static function inspect(
        array $requirements,
        array $proof,
        array $trustedContext,
        array $seenNonces,
        int $trustedNow,
        string $verificationKey
    ): array {
        try {
            self::exactFields($trustedContext, [
                'origin', 'issuer', 'owner', 'project_ref', 'repository_ref',
            ]);
            self::exactFields($proof, self::PROOF_FIELDS);
            self::exactFields($requirements, [
                'version', 'state', 'type', 'capability', 'scope',
                'scope_ref', 'scope_parameters', 'approval_class',
                'authorized', 'grant_issued', 'approval_issued',
                'approval_verified', 'execution',
            ]);
            if ($trustedContext['origin'] !== 'server'
                || !is_string($trustedContext['issuer'])
                || !is_string($trustedContext['owner'])
                || !is_string($trustedContext['project_ref'])
                || !is_string($trustedContext['repository_ref'])
                || !is_string($proof['issuer'])
                || !is_string($proof['owner'])
                || !is_string($proof['capability'])
                || !is_string($proof['scope'])
                || !is_string($proof['scope_ref'])
                || !is_string($proof['nonce'])
                || !is_string($proof['signature'])
                || strlen($verificationKey) < 32
                || $trustedNow < 1) {
                return self::deny('invalid_evidence');
            }
            $type = $requirements['type'];
            if (!is_string($type) || !isset(self::TYPES[$type])
                || $requirements['version'] !== 1
                || $requirements['state'] !== 'requirements'
                || $requirements['capability'] !== self::TYPES[$type]
                || $requirements['approval_class'] !== 'owner_required') {
                return self::deny('unregistered_capability');
            }
            foreach (['authorized', 'grant_issued', 'approval_issued',
                'approval_verified', 'execution'] as $flag) {
                if ($requirements[$flag] !== false) {
                    return self::deny('invalid_authority');
                }
            }
            $scope = in_array($type, ['project.freeze', 'project.unfreeze'], true)
                ? 'project' : 'repository';
            $ref = $scope === 'project'
                ? $trustedContext['project_ref'] : $trustedContext['repository_ref'];
            if ($ref === '' || $requirements['scope'] !== $scope
                || $requirements['scope_ref'] !== $ref
                || $requirements['scope_parameters'] !== [$scope . '_ref' => $ref]
                || $proof['scope'] !== $scope
                || $proof['scope_ref'] !== $ref
                || $proof['capability'] !== $requirements['capability']
                || $proof['owner'] !== $trustedContext['owner']
                || $proof['issuer'] !== $trustedContext['issuer']) {
                return self::deny('scope_or_owner_mismatch');
            }
            // Catalogar tipos de evidencia NO registra privilegios GitHub.
            $catalog = CapabilityPolicy::classify($proof['capability']);
            if ($catalog['known'] !== false || $catalog['decision'] !== 'forbidden'
                || $catalog['auto_grant_allowed'] !== false) {
                return self::deny('authority_catalog_changed');
            }
            if (!is_int($proof['issued_at']) || !is_int($proof['expires_at'])
                || $proof['issued_at'] < 1
                || $proof['issued_at'] > $trustedNow
                || $trustedNow - $proof['issued_at'] > 300
                || $proof['expires_at'] <= $trustedNow
                || $proof['expires_at'] <= $proof['issued_at']
                || $proof['expires_at'] - $proof['issued_at'] > 300
                || !preg_match('/^[a-f0-9]{32}$/D', $proof['nonce'])
                || !array_is_list($seenNonces) || count($seenNonces) > 1000
                || in_array($proof['nonce'], $seenNonces, true)) {
                return self::deny('stale_or_replayed_evidence');
            }
            if (!preg_match('/^[a-f0-9]{64}$/D', $proof['signature'])) {
                return self::deny('invalid_signature');
            }
            $canonical = json_encode([
                $proof['capability'], $proof['scope'], $proof['scope_ref'],
                $proof['owner'], $proof['issued_at'], $proof['expires_at'],
                $proof['nonce'], $proof['issuer'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $expected = hash_hmac('sha256', $canonical, $verificationKey);
            if (!hash_equals($expected, $proof['signature'])) {
                return self::deny('invalid_signature');
            }
            // Firma verificable no es autorización. Sin efectos, jamás allow.
            return self::deny('offline_only', true);
        } catch (\Throwable) {
            return self::deny('invalid_evidence');
        }
    }

    private static function exactFields(array $data, array $expected): void
    {
        if (array_is_list($data)) {
            throw new \InvalidArgumentException('invalid structure');
        }
        $actual = array_keys($data);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('invalid structure');
        }
    }

    private static function deny(string $reason, bool $proofValid = false): array
    {
        return [
            'decision' => 'deny', 'reason' => $reason,
            'proof_valid' => $proofValid, 'authorized' => false,
            'grant_issued' => false, 'approval_issued' => false,
            'approval_verified' => false, 'execution' => false,
        ];
    }
}

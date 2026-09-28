<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use Throwable;

final class DecisionRights
{
    private const AUTHORITY_RANK = [
        'L0_AI_AUTONOMOUS' => 0,
        'L1_OPERATOR' => 1,
        'L2_VENTURE_ADMIN' => 2,
        'L3_GROUP_INSTITUTION' => 3,
        'L4_OWNER' => 4,
    ];

    /**
     * Evaluates one verified identity/grant against a server-defined action.
     *
     * The caller must derive $verifiedContext and $serverAction from trusted
     * server-side sources. Client claims such as identity_id or scope are not
     * accepted by this contract.
     */
    public static function evaluate(
        array $verifiedContext,
        array $grant,
        array $serverAction,
        int $now,
    ): array {
        try {
            self::now($now);
            self::fields($verifiedContext, ['identity', 'scope', 'active_policy_refs'], 'VerifiedContext');
            $serverAction += ['budget_amount' => null];
            self::fields(
                $serverAction,
                ['capability', 'required_authority_level', 'budget_amount'],
                'ServerAction',
            );

            if (!is_array($verifiedContext['identity'])) {
                throw new InvalidArgumentException('identity invalid.');
            }

            $identity = VentureIdentity::normalizeIdentity($verifiedContext['identity']);
            $scope = self::scope($verifiedContext['scope']);
            $activePolicies = self::policyRefs($verifiedContext['active_policy_refs']);
            $capability = self::capability($serverAction['capability']);
            $requiredAuthority = self::authority($serverAction['required_authority_level']);
            $budgetAmount = self::nullableMoney($serverAction['budget_amount']);
        } catch (Throwable) {
            return self::result('deny', ['invalid_input']);
        }

        try {
            $grant = VentureIdentity::normalizeGrant($grant, $now);
        } catch (Throwable) {
            return self::result('deny', ['invalid_grant']);
        }

        $denyReasons = [];
        if ($identity['state'] !== 'active') {
            $denyReasons[] = 'identity_inactive';
        }
        if ($grant['identity_id'] !== $identity['identity_id']) {
            $denyReasons[] = 'identity_mismatch';
        }
        if ($grant['scope'] !== $scope) {
            $denyReasons[] = 'scope_mismatch';
        }
        if ($grant['capability'] !== $capability) {
            $denyReasons[] = 'capability_not_granted';
        }
        if (!in_array($grant['policy_ref'], $activePolicies, true)) {
            $denyReasons[] = 'policy_not_active';
        }

        if ($denyReasons !== []) {
            return self::result('deny', $denyReasons);
        }

        $escalationReasons = [];
        $grantRank = self::AUTHORITY_RANK[$grant['authority_level']];
        $requiredRank = self::AUTHORITY_RANK[$requiredAuthority];

        if ($requiredRank > $grantRank) {
            $escalationReasons[] = 'authority_escalation_required';
        }
        if (
            $budgetAmount !== null
            && ($grant['budget_limit'] === null || $budgetAmount > $grant['budget_limit'])
        ) {
            $escalationReasons[] = 'budget_approval_required';
        }
        if ($requiredAuthority === 'L4_OWNER') {
            $escalationReasons[] = 'owner_authority_required';
        }

        if ($escalationReasons !== []) {
            return self::result('owner_decision_required', array_values(array_unique($escalationReasons)));
        }

        return self::result('allow', ['authorized']);
    }

    private static function result(string $decision, array $reasons): array
    {
        return [
            'decision' => $decision,
            'reasons' => $reasons,
        ];
    }

    private static function policyRefs(mixed $refs): array
    {
        if (!is_array($refs) || !array_is_list($refs) || count($refs) > 50) {
            throw new InvalidArgumentException('active_policy_refs invalid.');
        }
        $out = [];
        foreach ($refs as $ref) {
            if (
                !is_string($ref)
                || preg_match('#^controlbot:policy/[a-z][a-z0-9._/-]{1,119}$#D', $ref) !== 1
            ) {
                throw new InvalidArgumentException('active_policy_refs invalid.');
            }
            if (isset($out[$ref])) {
                throw new InvalidArgumentException('active_policy_refs duplicated.');
            }
            $out[$ref] = true;
        }
        $refs = array_keys($out);
        sort($refs);
        return $refs;
    }

    private static function capability(mixed $value): string
    {
        if (
            !is_string($value)
            || strlen($value) > 120
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('capability invalid.');
        }
        return $value;
    }

    private static function scope(mixed $value): string
    {
        if (
            !is_string($value)
            || preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
    }

    private static function authority(mixed $value): string
    {
        if (!is_string($value) || !array_key_exists($value, self::AUTHORITY_RANK)) {
            throw new InvalidArgumentException('authority invalid.');
        }
        return $value;
    }

    private static function nullableMoney(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new InvalidArgumentException('budget_amount invalid.');
        }
        return (float) $value;
    }

    private static function now(int $value): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException('now invalid.');
        }
        return $value;
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
}

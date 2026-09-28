<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

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
     * Evaluate one verified identity/grant against a server-defined action.
     * Client identity/scope claims are deliberately outside this contract.
     */
    public static function evaluate(array $context, array $grant, array $action, int $now): array
    {
        try {
            self::validNow($now);
            self::fields($context, ['identity', 'scope', 'active_policy_refs'], 'VerifiedContext');
            $action += ['budget_amount' => null];
            self::fields($action, ['capability', 'required_authority_level', 'budget_amount'], 'ServerAction');
            if (!is_array($context['identity'])) {
                throw new InvalidArgumentException('identity invalid.');
            }

            $identity = VentureIdentity::normalizeIdentity($context['identity']);
            $scope = self::scope($context['scope']);
            $policies = self::policyRefs($context['active_policy_refs']);
            $capability = self::capability($action['capability']);
            $required = self::authority($action['required_authority_level']);
            $budget = self::money($action['budget_amount']);
        } catch (InvalidArgumentException) {
            return self::result('deny', ['invalid_input']);
        }

        try {
            $grant = VentureIdentity::normalizeGrant($grant, $now);
        } catch (InvalidArgumentException) {
            return self::result('deny', ['invalid_grant']);
        }

        $deny = [];
        if ($identity['state'] !== 'active') $deny[] = 'identity_inactive';
        if ($grant['identity_id'] !== $identity['identity_id']) $deny[] = 'identity_mismatch';
        if ($grant['scope'] !== $scope) $deny[] = 'scope_mismatch';
        if ($grant['capability'] !== $capability) $deny[] = 'capability_not_granted';
        if (!in_array($grant['policy_ref'], $policies, true)) $deny[] = 'policy_not_active';
        if ($deny !== []) return self::result('deny', $deny);

        $escalate = [];
        if (self::AUTHORITY_RANK[$required] > self::AUTHORITY_RANK[$grant['authority_level']]) {
            $escalate[] = 'authority_escalation_required';
        }
        if ($budget !== null && ($grant['budget_limit'] === null || $budget > $grant['budget_limit'])) {
            $escalate[] = 'budget_approval_required';
        }
        if ($required === 'L4_OWNER') $escalate[] = 'owner_authority_required';

        return $escalate === []
            ? self::result('allow', ['authorized'])
            : self::result('owner_decision_required', array_values(array_unique($escalate)));
    }

    private static function result(string $decision, array $reasons): array
    {
        return ['decision' => $decision, 'reasons' => $reasons];
    }

    private static function policyRefs(mixed $refs): array
    {
        if (!is_array($refs) || !array_is_list($refs) || count($refs) > 50) {
            throw new InvalidArgumentException('active_policy_refs invalid.');
        }
        $seen = [];
        foreach ($refs as $ref) {
            if (!is_string($ref) || preg_match('#^controlbot:policy/[a-z][a-z0-9._/-]{1,119}$#D', $ref) !== 1) {
                throw new InvalidArgumentException('active_policy_refs invalid.');
            }
            if (isset($seen[$ref])) throw new InvalidArgumentException('active_policy_refs duplicated.');
            $seen[$ref] = true;
        }
        $refs = array_keys($seen);
        sort($refs);
        return $refs;
    }

    private static function capability(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 120
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1) {
            throw new InvalidArgumentException('capability invalid.');
        }
        return $value;
    }

    private static function scope(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
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

    private static function money(mixed $value): ?float
    {
        if ($value === null) return null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new InvalidArgumentException('budget_amount invalid.');
        }
        return (float) $value;
    }

    private static function validNow(int $value): void
    {
        if ($value < 1) throw new InvalidArgumentException('now invalid.');
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        if (array_is_list($row)) throw new InvalidArgumentException($label . ' invalid.');
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) throw new InvalidArgumentException($label . ' fields invalid.');
    }
}

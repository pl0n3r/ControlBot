<?php
declare(strict_types=1);

namespace ControlBot\Security;

use ControlBot\Business\DecisionRights;
use InvalidArgumentException;

final class AegisRemediation
{
    private const BLAST_RADIUS = ['low', 'medium', 'high'];
    private const RISK_CATEGORIES = ['privileged', 'secrets', 'deletion', 'money', 'privacy', 'legal'];
    private const VERIFICATION_STATES = ['not_attempted', 'attempted', 'verified', 'failed'];
    private const AUTHORITY_LEVELS = [
        'L0_AI_AUTONOMOUS', 'L1_OPERATOR', 'L2_VENTURE_ADMIN',
        'L3_GROUP_INSTITUTION', 'L4_OWNER',
    ];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code|session|credential)/i';

    /**
     * Construye un plan determinista. Nunca ejecuta la acción propuesta.
     */
    public static function plan(array $input, int $now): array
    {
        self::fields($input, [
            'version', 'finding', 'proposal', 'verified_context', 'grant', 'verification',
        ], 'remediation');
        if (($input['version'] ?? null) !== 1 || $now < 1) {
            throw new InvalidArgumentException('remediation version/now invalid.');
        }

        $finding = self::finding($input['finding'] ?? null);
        $proposal = self::proposal($input['proposal'] ?? null, $finding['scope']);
        $verification = self::verification($input['verification'] ?? null);
        $context = $input['verified_context'] ?? null;
        $grant = $input['grant'] ?? null;
        if (!is_array($context) || array_is_list($context) || !is_array($grant) || array_is_list($grant)) {
            throw new InvalidArgumentException('authority input invalid.');
        }

        $rights = DecisionRights::evaluate(
            $context,
            $grant,
            [
                'capability' => $proposal['capability'],
                'required_authority_level' => $proposal['required_authority_level'],
                'budget_amount' => $proposal['budget_amount'],
            ],
            $now,
        );

        $policyBound = self::policyBound($proposal['policy_ref'], $context, $grant);
        $reasons = [];
        $ownerRequired = false;

        if (!$proposal['reversible']) {
            $ownerRequired = true;
            $reasons[] = 'irreversible_action';
        }
        if ($proposal['blast_radius'] === 'high') {
            $ownerRequired = true;
            $reasons[] = 'high_blast_radius';
        }
        foreach ($proposal['risk_categories'] as $risk) {
            $ownerRequired = true;
            $reasons[] = 'risk_' . $risk;
        }
        if ($proposal['budget_amount'] !== null && !in_array('money', $proposal['risk_categories'], true)) {
            $ownerRequired = true;
            $reasons[] = 'risk_money';
        }

        foreach ($rights['reasons'] as $reason) {
            if ($reason !== 'authorized') {
                $reasons[] = 'authority_' . $reason;
            }
        }
        if (!$policyBound) {
            $reasons[] = 'policy_binding_invalid';
        }
        if ($rights['decision'] === 'owner_decision_required') {
            $ownerRequired = true;
        }

        $autoEligible = $policyBound
            && !$ownerRequired
            && $rights['decision'] === 'allow'
            && $proposal['preauthorized']
            && $proposal['reversible']
            && $proposal['blast_radius'] === 'low'
            && $proposal['required_authority_level'] === 'L0_AI_AUTONOMOUS'
            && $proposal['risk_categories'] === [];

        if ($rights['decision'] === 'deny' || !$policyBound) {
            $decision = 'deny';
            $reasons[] = 'authority_or_policy_denied';
        } elseif ($ownerRequired) {
            $decision = 'owner_decision_required';
        } elseif ($autoEligible) {
            $decision = 'auto_eligible';
            $reasons[] = 'preauthorized_reversible_low_blast_radius';
        } else {
            $decision = 'manual_required';
            if (!$proposal['preauthorized']) $reasons[] = 'not_preauthorized';
            if ($proposal['blast_radius'] !== 'low') $reasons[] = 'blast_radius_not_low';
            if ($proposal['required_authority_level'] !== 'L0_AI_AUTONOMOUS') {
                $reasons[] = 'human_authority_required';
            }
        }

        $reasons = array_values(array_unique($reasons));
        sort($reasons, SORT_STRING);

        return [
            'version' => 1,
            'finding_id' => $finding['finding_id'],
            'scope' => $finding['scope'],
            'action' => $proposal['action'],
            'idempotency_key' => self::idempotencyKey(
                $finding['finding_id'],
                $proposal['action'],
                $finding['scope'],
            ),
            'decision' => $decision,
            'auto_eligible' => $decision === 'auto_eligible',
            'reasons' => $reasons,
            'proposal' => $proposal,
            'authority' => $rights,
            'verification' => $verification,
            'remediation_state' => self::remediationState($verification),
        ];
    }

    /**
     * Deduplica propuestas idénticas sin ejecutar ninguna.
     */
    public static function planBatch(array $inputs, int $now): array
    {
        if (!array_is_list($inputs) || count($inputs) > 200) {
            throw new InvalidArgumentException('remediation batch invalid.');
        }

        $byKey = [];
        foreach ($inputs as $input) {
            if (!is_array($input)) {
                throw new InvalidArgumentException('remediation batch item invalid.');
            }
            $plan = self::plan($input, $now);
            $key = $plan['idempotency_key'];
            if (isset($byKey[$key]) && $byKey[$key] !== $plan) {
                throw new InvalidArgumentException('idempotency conflict.');
            }
            $byKey[$key] = $plan;
        }

        ksort($byKey, SORT_STRING);
        return array_values($byKey);
    }

    private static function finding(mixed $row): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('finding invalid.');
        }
        self::fields($row, ['finding_id', 'scope'], 'finding');
        return [
            'finding_id' => self::id($row['finding_id'] ?? null, 'finding_id'),
            'scope' => self::scope($row['scope'] ?? null),
        ];
    }

    private static function proposal(mixed $row, string $scope): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('proposal invalid.');
        }
        self::fields($row, [
            'action', 'capability', 'scope', 'reversible', 'blast_radius',
            'risk_categories', 'preauthorized', 'required_authority_level',
            'policy_ref', 'budget_amount',
        ], 'proposal');

        $proposalScope = self::scope($row['scope'] ?? null);
        if ($proposalScope !== $scope) {
            throw new InvalidArgumentException('proposal scope mismatch.');
        }
        if (!is_bool($row['reversible'] ?? null) || !is_bool($row['preauthorized'] ?? null)) {
            throw new InvalidArgumentException('proposal flags invalid.');
        }

        $budget = $row['budget_amount'] ?? null;
        if ($budget !== null && (
            (!is_int($budget) && !is_float($budget))
            || !is_finite((float) $budget)
            || $budget < 0
        )) {
            throw new InvalidArgumentException('budget_amount invalid.');
        }

        return [
            'action' => self::id($row['action'] ?? null, 'action'),
            'capability' => self::id($row['capability'] ?? null, 'capability'),
            'scope' => $proposalScope,
            'reversible' => $row['reversible'],
            'blast_radius' => self::enum($row['blast_radius'] ?? null, self::BLAST_RADIUS, 'blast_radius'),
            'risk_categories' => self::enums(
                $row['risk_categories'] ?? null,
                self::RISK_CATEGORIES,
                'risk_categories',
            ),
            'preauthorized' => $row['preauthorized'],
            'required_authority_level' => self::enum(
                $row['required_authority_level'] ?? null,
                self::AUTHORITY_LEVELS,
                'required_authority_level',
            ),
            'policy_ref' => self::policyRef($row['policy_ref'] ?? null),
            'budget_amount' => $budget === null ? null : (float) $budget,
        ];
    }

    private static function verification(mixed $row): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('verification invalid.');
        }
        self::fields($row, ['status', 'evidence_refs'], 'verification');

        $status = self::enum(
            $row['status'] ?? null,
            self::VERIFICATION_STATES,
            'verification status',
        );
        $refs = self::refs($row['evidence_refs'] ?? null);
        if ($status === 'verified' && $refs === []) {
            throw new InvalidArgumentException('verified remediation requires evidence.');
        }

        return ['status' => $status, 'evidence_refs' => $refs];
    }

    private static function policyBound(string $policyRef, array $context, array $grant): bool
    {
        $active = $context['active_policy_refs'] ?? null;
        return is_array($active)
            && array_is_list($active)
            && in_array($policyRef, $active, true)
            && ($grant['policy_ref'] ?? null) === $policyRef;
    }

    private static function remediationState(array $verification): string
    {
        if ($verification['status'] === 'verified' && $verification['evidence_refs'] !== []) return 'success';
        if ($verification['status'] === 'failed') return 'failed';
        return 'not_verified';
    }

    private static function idempotencyKey(string $findingId, string $action, string $scope): string
    {
        return 'aegis-remediation:' . hash('sha256', $findingId . "\n" . $action . "\n" . $scope);
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        if (array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $keys = array_keys($row);
        if (array_diff($expected, $keys) !== [] || array_diff($keys, $expected) !== []) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function scope(mixed $value): string
    {
        return self::validatedText(
            $value,
            'scope',
            '/^(?:venture|project|institution):[a-z][a-z0-9-]{0,63}$/D',
            80,
        );
    }

    private static function id(mixed $value, string $label): string
    {
        return self::validatedText(
            $value,
            $label,
            '/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D',
            120,
        );
    }

    private static function policyRef(mixed $value): string
    {
        return self::validatedText(
            $value,
            'policy_ref',
            '#^controlbot:policy/[a-z][a-z0-9._/-]{1,119}$#D',
            180,
        );
    }

    private static function validatedText(
        mixed $value,
        string $label,
        string $pattern,
        int $maxLength
    ): string {
        $invalid = !is_string($value)
            || $value === ''
            || strlen($value) > $maxLength
            || preg_match($pattern, $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1;

        if ($invalid) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function refs(mixed $value): array
    {
        return self::normalizedSet(
            $value,
            50,
            'evidence_refs',
            static fn(mixed $item): string => self::evidenceRef($item),
        );
    }

    private static function evidenceRef(mixed $value): string
    {
        $invalid = !is_string($value)
            || strlen($value) < 8
            || strlen($value) > 180
            || str_contains($value, '@')
            || str_contains($value, '..')
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\\#-]+$#D', $value) !== 1;

        if ($invalid) {
            throw new InvalidArgumentException('evidence_ref invalid.');
        }
        return $value;
    }

    private static function enums(mixed $value, array $allowed, string $label): array
    {
        return self::normalizedSet(
            $value,
            count($allowed),
            $label,
            static fn(mixed $item): string => self::enum($item, $allowed, $label),
        );
    }

    private static function normalizedSet(
        mixed $value,
        int $limit,
        string $label,
        callable $normalize
    ): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $seen = [];
        foreach ($value as $item) {
            $seen[$normalize($item)] = true;
        }

        $normalized = array_keys($seen);
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
}

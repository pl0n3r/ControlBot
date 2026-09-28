<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use LogicException;

final class CapitalPolicy
{
    private const MAX_MINOR_UNITS = 9_000_000_000_000_000;
    private const AUTHORITY_LEVELS = [
        'L0_AI_AUTONOMOUS',
        'L1_OPERATOR',
        'L2_VENTURE_ADMIN',
        'L3_GROUP_INSTITUTION',
        'L4_OWNER',
    ];
    private const FINANCIAL_STATES = ['known', 'unknown'];
    private const DECISIONS = ['allow', 'deny', 'owner_decision_required'];

    public static function evaluate(array $input): array
    {
        try {
            self::fields($input, [
                'version', 'scope', 'currency', 'budget', 'reserve', 'proposal',
                'budget_guard', 'decision_rights',
            ], 'capital');

            if (($input['version'] ?? null) !== 1) {
                throw new InvalidArgumentException('capital version invalid.');
            }

            $scope = self::scope($input['scope'] ?? null);
            $currency = self::currency($input['currency'] ?? null);
            $budget = self::budget($input['budget'] ?? null, $scope, $currency);
            $reserve = self::reserve($input['reserve'] ?? null, $scope, $currency);
            $proposal = self::proposal($input['proposal'] ?? null, $scope, $currency);
            $budgetGuard = self::budgetGuard($input['budget_guard'] ?? null);
            $rights = self::decisionRights($input['decision_rights'] ?? null);
        } catch (InvalidArgumentException) {
            return self::failed('deny', ['invalid_input']);
        }

        if ($budget['state'] === 'unknown' || $reserve['state'] === 'unknown') {
            return self::result(
                $scope,
                $currency,
                'deny',
                ['unknown_financial_state'],
                $budget,
                $reserve,
                $proposal,
                $budgetGuard,
                $rights,
            );
        }

        if ($rights['decision'] === 'deny') {
            return self::result(
                $scope,
                $currency,
                'deny',
                ['decision_rights_denied', ...$rights['reasons']],
                $budget,
                $reserve,
                $proposal,
                $budgetGuard,
                $rights,
            );
        }

        if (!$budgetGuard['auto_executable']) {
            return self::result(
                $scope,
                $currency,
                'deny',
                ['budget_guard_restricted', $budgetGuard['reason']],
                $budget,
                $reserve,
                $proposal,
                $budgetGuard,
                $rights,
            );
        }

        $ownerReasons = [];
        if ($rights['decision'] === 'owner_decision_required') {
            $ownerReasons[] = 'decision_rights_owner_gate';
            array_push($ownerReasons, ...$rights['reasons']);
        }

        $budgetAfter = $budget['spent_minor'] + $budget['committed_minor'] + $proposal['amount_minor'];
        if ($budgetAfter > $budget['limit_minor']) {
            $ownerReasons[] = 'budget_limit_exceeded';
        }

        $cashAfter = $reserve['cash_available_minor'] - $proposal['amount_minor'];
        if ($cashAfter < $reserve['minimum_reserve_minor']) {
            $ownerReasons[] = 'reserve_floor_breached';
        }

        if ($proposal['required_authority_level'] === 'L4_OWNER') {
            $ownerReasons[] = 'owner_authority_required';
        }
        if ($proposal['irreversible']) {
            $ownerReasons[] = 'irreversible_action';
        }

        $decision = $ownerReasons === [] ? 'allow' : 'owner_decision_required';
        $reasons = $ownerReasons === [] ? ['proposal_within_policy'] : array_values(array_unique($ownerReasons));

        return self::result(
            $scope,
            $currency,
            $decision,
            $reasons,
            $budget,
            $reserve,
            $proposal,
            $budgetGuard,
            $rights,
        );
    }

    public static function executePayment(array $payload): never
    {
        if ($payload === []) {
            throw new InvalidArgumentException('payment payload invalid.');
        }
        throw new LogicException('CAPITAL does not execute payments.');
    }

    private static function result(
        string $scope,
        string $currency,
        string $decision,
        array $reasons,
        array $budget,
        array $reserve,
        array $proposal,
        array $budgetGuard,
        array $rights,
    ): array {
        $budgetAfter = $budget['spent_minor'] + $budget['committed_minor'] + $proposal['amount_minor'];
        $cashAfter = $reserve['cash_available_minor'] - $proposal['amount_minor'];

        return [
            'version' => 1,
            'scope' => $scope,
            'currency' => $currency,
            'decision' => $decision,
            'reasons' => array_values(array_unique($reasons)),
            'execution' => false,
            'proposal' => [
                'proposal_id' => $proposal['proposal_id'],
                'amount_minor' => $proposal['amount_minor'],
                'required_authority_level' => $proposal['required_authority_level'],
                'irreversible' => $proposal['irreversible'],
            ],
            'budget' => [
                'limit_minor' => $budget['limit_minor'],
                'spent_minor' => $budget['spent_minor'],
                'committed_minor' => $budget['committed_minor'],
                'after_proposal_minor' => $budgetAfter,
                'source_ref' => $budget['source_ref'],
                'observed_at' => $budget['observed_at'],
            ],
            'reserve' => [
                'cash_available_minor' => $reserve['cash_available_minor'],
                'minimum_reserve_minor' => $reserve['minimum_reserve_minor'],
                'cash_after_proposal_minor' => $cashAfter,
                'runway_months' => $reserve['runway_months'],
                'source_ref' => $reserve['source_ref'],
                'observed_at' => $reserve['observed_at'],
            ],
            'shared_costs' => $proposal['shared_costs'],
            'authority_evidence' => [
                'budget_guard' => [
                    'auto_executable' => $budgetGuard['auto_executable'],
                    'reason' => $budgetGuard['reason'],
                ],
                'decision_rights' => [
                    'decision' => $rights['decision'],
                    'reasons' => $rights['reasons'],
                ],
            ],
        ];
    }

    private static function failed(string $decision, array $reasons): array
    {
        return [
            'version' => 1,
            'decision' => $decision,
            'reasons' => $reasons,
            'execution' => false,
        ];
    }

    private static function budget(mixed $value, string $scope, string $currency): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('budget invalid.');
        }
        self::fields($value, [
            'scope', 'currency', 'state', 'limit_minor', 'spent_minor',
            'committed_minor', 'source_ref', 'observed_at',
        ], 'budget');

        $normalized = [
            'scope' => self::scope($value['scope'] ?? null),
            'currency' => self::currency($value['currency'] ?? null),
            'state' => self::enum($value['state'] ?? null, self::FINANCIAL_STATES, 'budget.state'),
            'limit_minor' => self::money($value['limit_minor'] ?? null, 'budget.limit_minor'),
            'spent_minor' => self::money($value['spent_minor'] ?? null, 'budget.spent_minor'),
            'committed_minor' => self::money($value['committed_minor'] ?? null, 'budget.committed_minor'),
            'source_ref' => self::ref($value['source_ref'] ?? null, 'budget.source_ref'),
            'observed_at' => self::timestamp($value['observed_at'] ?? null, 'budget.observed_at'),
        ];
        if ($normalized['scope'] !== $scope || $normalized['currency'] !== $currency) {
            throw new InvalidArgumentException('budget scope/currency mismatch.');
        }
        return $normalized;
    }

    private static function reserve(mixed $value, string $scope, string $currency): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('reserve invalid.');
        }
        self::fields($value, [
            'scope', 'currency', 'state', 'cash_available_minor',
            'minimum_reserve_minor', 'runway_months', 'source_ref', 'observed_at',
        ], 'reserve');

        $runway = $value['runway_months'] ?? null;
        if (!is_int($runway) || $runway < 0 || $runway > 1200) {
            throw new InvalidArgumentException('reserve.runway_months invalid.');
        }

        $normalized = [
            'scope' => self::scope($value['scope'] ?? null),
            'currency' => self::currency($value['currency'] ?? null),
            'state' => self::enum($value['state'] ?? null, self::FINANCIAL_STATES, 'reserve.state'),
            'cash_available_minor' => self::money($value['cash_available_minor'] ?? null, 'reserve.cash_available_minor'),
            'minimum_reserve_minor' => self::money($value['minimum_reserve_minor'] ?? null, 'reserve.minimum_reserve_minor'),
            'runway_months' => $runway,
            'source_ref' => self::ref($value['source_ref'] ?? null, 'reserve.source_ref'),
            'observed_at' => self::timestamp($value['observed_at'] ?? null, 'reserve.observed_at'),
        ];
        if ($normalized['scope'] !== $scope || $normalized['currency'] !== $currency) {
            throw new InvalidArgumentException('reserve scope/currency mismatch.');
        }
        return $normalized;
    }

    private static function proposal(mixed $value, string $scope, string $currency): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('proposal invalid.');
        }
        self::fields($value, [
            'proposal_id', 'scope', 'currency', 'amount_minor',
            'required_authority_level', 'irreversible', 'shared_costs',
        ], 'proposal');

        $normalized = [
            'proposal_id' => self::id($value['proposal_id'] ?? null, 'proposal_id'),
            'scope' => self::scope($value['scope'] ?? null),
            'currency' => self::currency($value['currency'] ?? null),
            'amount_minor' => self::money($value['amount_minor'] ?? null, 'proposal.amount_minor'),
            'required_authority_level' => self::enum(
                $value['required_authority_level'] ?? null,
                self::AUTHORITY_LEVELS,
                'proposal.required_authority_level',
            ),
            'irreversible' => self::boolean($value['irreversible'] ?? null, 'proposal.irreversible'),
            'shared_costs' => self::sharedCosts($value['shared_costs'] ?? null, $currency),
        ];
        if ($normalized['scope'] !== $scope || $normalized['currency'] !== $currency) {
            throw new InvalidArgumentException('proposal scope/currency mismatch.');
        }
        return $normalized;
    }

    private static function sharedCosts(mixed $value, string $currency): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new InvalidArgumentException('shared_costs invalid.');
        }

        $seen = [];
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('shared_cost invalid.');
            }
            self::fields($row, [
                'cost_id', 'currency', 'amount_minor', 'target_scope',
                'rule_ref', 'provenance_ref',
            ], 'shared_cost');

            $costId = self::id($row['cost_id'] ?? null, 'shared_cost.cost_id');
            if (isset($seen[$costId])) {
                throw new InvalidArgumentException('shared_cost duplicated.');
            }
            $seen[$costId] = true;

            $rowCurrency = self::currency($row['currency'] ?? null);
            if ($rowCurrency !== $currency) {
                throw new InvalidArgumentException('shared_cost currency mismatch.');
            }

            $target = self::nullableScope($row['target_scope'] ?? null);
            $rule = self::nullableRef($row['rule_ref'] ?? null, 'shared_cost.rule_ref');
            $provenance = self::nullableRef($row['provenance_ref'] ?? null, 'shared_cost.provenance_ref');
            $allocated = $target !== null && $rule !== null && $provenance !== null;

            $out[] = [
                'cost_id' => $costId,
                'currency' => $rowCurrency,
                'amount_minor' => self::money($row['amount_minor'] ?? null, 'shared_cost.amount_minor'),
                'status' => $allocated ? 'allocated' : 'unallocated',
                'target_scope' => $allocated ? $target : null,
                'rule_ref' => $allocated ? $rule : null,
                'provenance_ref' => $allocated ? $provenance : null,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['cost_id'] <=> $b['cost_id']);
        return $out;
    }

    private static function budgetGuard(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('budget_guard invalid.');
        }
        self::fields($value, [
            'auto_executable', 'pause_noncritical', 'preserve_pr_fail_closed', 'reason',
        ], 'budget_guard');

        foreach (['auto_executable', 'pause_noncritical', 'preserve_pr_fail_closed'] as $key) {
            self::boolean($value[$key] ?? null, 'budget_guard.' . $key);
        }
        $reason = self::id($value['reason'] ?? null, 'budget_guard.reason');

        return [
            'auto_executable' => $value['auto_executable'],
            'pause_noncritical' => $value['pause_noncritical'],
            'preserve_pr_fail_closed' => $value['preserve_pr_fail_closed'],
            'reason' => $reason,
        ];
    }

    private static function decisionRights(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('decision_rights invalid.');
        }
        self::fields($value, ['decision', 'reasons'], 'decision_rights');
        $decision = self::enum($value['decision'] ?? null, self::DECISIONS, 'decision_rights.decision');
        $reasons = $value['reasons'] ?? null;
        if (!is_array($reasons) || !array_is_list($reasons) || $reasons === [] || count($reasons) > 20) {
            throw new InvalidArgumentException('decision_rights.reasons invalid.');
        }
        $normalized = [];
        foreach ($reasons as $reason) {
            $normalized[] = self::id($reason, 'decision_rights.reason');
        }
        return ['decision' => $decision, 'reasons' => $normalized];
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        $actual = array_keys($row);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function money(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX_MINOR_UNITS) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function boolean(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
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

    private static function nullableScope(mixed $value): ?string
    {
        return $value === null ? null : self::scope($value);
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new InvalidArgumentException('currency invalid.');
        }
        return $value;
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || strlen($value) > 120
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function ref(mixed $value, string $label): string
    {
        if (!is_string($value)
            || strlen($value) > 180
            || str_contains($value, '@')
            || preg_match('#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\#-]{1,178}$#D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::ref($value, $label);
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
}

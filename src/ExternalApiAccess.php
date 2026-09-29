<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use ControlBot\Business\DecisionRights;
use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class ExternalApiAccess
{
    private const OWNER_AUTHORITY = 'L4_OWNER';
    private const BASE_REQUIRED_AUTHORITY = 'L3_GROUP_INSTITUTION';

    /**
     * Authorize one server-resolved API operation against a nominal access context.
     *
     * Mutations remain fail-closed until a later slice provides verified step-up.
     */
    public static function authorize(
        VerifiedAccessContext $context,
        string $method,
        string $pathTemplate,
        string $expectedScope,
        int $now,
    ): array {
        if ($now < 1) {
            throw new InvalidArgumentException('now invalid.');
        }

        $operation = ExternalApiContract::operation($method, $pathTemplate);
        $expectedScope = self::scope($expectedScope);
        $summary = $context->safeSummary();
        $scope = self::scope($summary['scope'] ?? null);

        if (!hash_equals($expectedScope, $scope)) {
            return self::result('deny', ['resource_scope_mismatch'], $operation, $scope);
        }

        $base = DecisionRights::evaluate(
            $context->decisionContext(),
            $context->grant(),
            [
                'capability' => $operation['auth_scope'],
                'required_authority_level' => self::BASE_REQUIRED_AUTHORITY,
                'budget_amount' => null,
            ],
            $now,
        );

        if (($base['decision'] ?? null) !== 'allow') {
            $reasons = is_array($base['reasons'] ?? null)
                ? $base['reasons']
                : ['decision_rights_denied'];
            return self::result('deny', $reasons, $operation, $scope);
        }

        if (($context->decisionContext()['identity']['kind'] ?? null) !== 'human'
            || ($context->grant()['role'] ?? null) !== 'owner'
            || ($summary['authority_level'] ?? null) !== self::OWNER_AUTHORITY) {
            return self::result('deny', ['owner_identity_required'], $operation, $scope);
        }

        if (($operation['mutation'] ?? false) === true) {
            return self::result(
                'step_up_required',
                ['verified_step_up_required'],
                $operation,
                $scope,
            );
        }

        return self::result('allow', ['authorized'], $operation, $scope);
    }

    private static function result(
        string $decision,
        array $reasons,
        array $operation,
        string $scope,
    ): array {
        $normalized = [];
        foreach ($reasons as $reason) {
            if (!is_string($reason) || preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $reason) !== 1) {
                throw new InvalidArgumentException('authorization reason invalid.');
            }
            $normalized[$reason] = true;
        }
        $reasons = array_keys($normalized);
        sort($reasons, SORT_STRING);

        return [
            'decision' => $decision,
            'reasons' => $reasons,
            'operation_id' => $operation['operation_id'],
            'capability' => $operation['auth_scope'],
            'scope' => $scope,
            'mutation' => $operation['mutation'],
        ];
    }

    private static function scope(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
    }
}

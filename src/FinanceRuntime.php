<?php
declare(strict_types=1);

namespace ControlBot\Business;

use ControlBot\Approvals\HumanGate;
use InvalidArgumentException;

final class FinanceRuntime
{
    private const READ_ACTION = [
        'capability' => 'finance.read',
        'required_authority_level' => 'L1_OPERATOR',
        'budget_amount' => null,
    ];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const WORK_TYPES = [
        'engineering', 'operations', 'data_analytics', 'product',
        'finance_analysis', 'compliance_review',
    ];
    private const PRIORITIES = ['critical', 'high', 'medium'];
    private const AUTHORITY_TO_FACTORY = [
        'L0_AI_AUTONOMOUS' => 'autonomous',
        'L1_OPERATOR' => 'operational',
        'L2_VENTURE_ADMIN' => 'venture_admin',
        'L3_GROUP_INSTITUTION' => 'group_institution',
        'L4_OWNER' => 'owner',
    ];

    public static function read(array $input, int $now): array
    {
        try {
            self::exact($input, ['scope', 'context', 'grant', 'snapshot', 'planning'], 'finance_read');
            if ($now < 1 || !is_array($input['context']) || !is_array($input['grant'])
                || !is_array($input['snapshot']) || !is_array($input['planning'])) {
                throw new InvalidArgumentException('finance_read invalid.');
            }

            $scope = self::scope($input['scope']);
            $contextScope = $input['context']['scope'] ?? null;
            if ($contextScope !== $scope) {
                return self::readDenied('scope_mismatch');
            }

            $authority = DecisionRights::evaluate(
                $input['context'],
                $input['grant'],
                self::READ_ACTION,
                $now,
            );
            if (($authority['decision'] ?? 'deny') !== 'allow') {
                return self::readDenied(
                    $authority['decision'] === 'owner_decision_required'
                        ? 'owner_decision_required'
                        : 'decision_rights_denied',
                    $authority,
                );
            }

            $snapshot = VentureFinancialSnapshot::normalize($input['snapshot']);
            $planning = FinancialPlanning::compare($input['planning']);
            $ventureScope = 'venture:' . $snapshot['venture_id'];

            if ($scope !== $ventureScope
                || ($planning['venture_id'] ?? null) !== $snapshot['venture_id']
                || ($planning['period'] ?? null) !== $snapshot['period']
                || ($planning['currency'] ?? null) !== $snapshot['currency']) {
                return self::readDenied('financial_scope_mismatch', $authority);
            }

            return [
                'status' => 'allow',
                'state' => self::evidenceState($snapshot, $planning),
                'scope' => $scope,
                'authority' => $authority,
                'snapshot' => $snapshot,
                'planning' => $planning,
            ];
        } catch (InvalidArgumentException) {
            return self::readDenied('invalid_input');
        }
    }

    public static function routeAction(array $input, int $now): array
    {
        try {
            self::exact($input, ['scope', 'capital'], 'finance_action');
            if ($now < 1 || !is_array($input['capital'])) {
                throw new InvalidArgumentException('finance_action invalid.');
            }

            $scope = self::scope($input['scope']);
            if (($input['capital']['scope'] ?? null) !== $scope) {
                return self::actionResult('deny', ['scope_mismatch'], null);
            }

            $capital = CapitalPolicy::evaluate($input['capital'], $now);
            $decision = $capital['decision'] ?? 'deny';
            $reasons = is_array($capital['reasons'] ?? null)
                ? $capital['reasons']
                : ['invalid_capital_result'];

            if ($decision === 'owner_decision_required') {
                return self::actionResult(
                    $decision,
                    $reasons,
                    self::ownerGate($scope, $reasons),
                    $capital,
                );
            }

            return self::actionResult(
                $decision === 'allow' ? 'allow' : 'deny',
                $reasons,
                null,
                $capital,
            );
        } catch (InvalidArgumentException) {
            return self::actionResult('deny', ['invalid_input'], null);
        }
    }

    public static function factoryHandoff(array $input, int $now): array
    {
        try {
            self::exact($input, ['read', 'work'], 'factory_handoff');
            if (!is_array($input['read']) || !is_array($input['work'])) {
                throw new InvalidArgumentException('factory_handoff invalid.');
            }

            $read = self::read($input['read'], $now);
            if (($read['status'] ?? null) !== 'allow') {
                return [
                    'queue' => 'factory',
                    'ready_hint' => false,
                    'reason' => 'finance_read_denied',
                    'freshness' => 'unknown',
                    'work_item' => null,
                ];
            }

            $work = self::workRequest($input['work']);
            $snapshot = $read['snapshot'];
            if ($work['venture_id'] !== $snapshot['venture_id']) {
                throw new InvalidArgumentException('work venture mismatch.');
            }

            $freshness = self::handoffFreshness($snapshot, $read['planning']);
            $workItem = [
                'work_id' => $work['work_id'],
                'origin_mode' => $work['origin_mode'],
                'origin_system' => 'capital',
                'group_id' => $work['group_id'],
                'venture_id' => $work['venture_id'],
                'repository_ref' => $work['repository_ref'],
                'work_type' => $work['work_type'],
                'requested_capabilities' => $work['requested_capabilities'],
                'required_roles' => $work['required_roles'],
                'authority_level' => $work['authority_level'],
                'producer_ref' => 'controlbot:finance/runtime',
                'priority_class' => $work['priority_class'],
                'depends_on' => $work['depends_on'],
                'claims' => $work['claims'],
                'policy_ref' => $work['policy_ref'],
                'evidence_refs' => self::evidenceRefs($snapshot, $read['planning']),
                'idempotency_key' => $work['idempotency_key'],
                'observed_at' => $snapshot['observed_at'],
            ];
            if ($work['budget_ref'] !== null) {
                $workItem['budget_ref'] = $work['budget_ref'];
            }
            if ($work['approval_ref'] !== null) {
                $workItem['approval_ref'] = $work['approval_ref'];
            }

            return [
                'queue' => 'factory',
                'ready_hint' => $freshness === 'fresh' && $read['state'] === 'green',
                'reason' => $freshness === 'fresh' && $read['state'] === 'green'
                    ? 'finance_evidence_fresh'
                    : 'finance_evidence_not_green',
                'freshness' => $freshness,
                'work_item' => $workItem,
            ];
        } catch (InvalidArgumentException) {
            return [
                'queue' => 'factory',
                'ready_hint' => false,
                'reason' => 'invalid_handoff',
                'freshness' => 'unknown',
                'work_item' => null,
            ];
        }
    }

    private static function readDenied(string $reason, ?array $authority = null): array
    {
        return [
            'status' => $reason === 'owner_decision_required' ? 'owner_decision_required' : 'deny',
            'state' => 'blocked',
            'reason' => $reason,
            'authority' => $authority,
            'snapshot' => null,
            'planning' => null,
        ];
    }

    private static function actionResult(
        string $status,
        array $reasons,
        ?string $gate,
        ?array $capital = null,
    ): array {
        return [
            'status' => $status,
            'execution' => false,
            'authority_source' => 'CapitalPolicy',
            'decision_rights_source' => 'DecisionRights',
            'reasons' => array_values(array_unique($reasons)),
            'owner_decision_gate' => $gate,
            'capital' => $capital,
        ];
    }

    private static function ownerGate(string $scope, array $reasons): string
    {
        $reason = implode(',', array_map(
            static fn(mixed $value): string => self::reason($value),
            $reasons,
        ));
        $payload = [
            'category' => 'money',
            'context' => "Finance action in {$scope} requires Owner authority. Reasons: {$reason}.",
            'options' => [
                [
                    'id' => 'A',
                    'label' => 'Approve a separately validated finance follow-up',
                    'effect' => 'No money moves now; approval only permits a new validated follow-up.',
                    'risk' => 'medium',
                    'reversible' => true,
                ],
                [
                    'id' => 'B',
                    'label' => 'Keep current financial state unchanged',
                    'effect' => 'No financial mutation or payment is performed.',
                    'risk' => 'low',
                    'reversible' => true,
                ],
            ],
            'recommendation' => 'B',
            'safe_default' => 'B',
            'title_simple' => 'Finance owner decision',
            'summary_simple' => "A finance proposal in {$scope} exceeded autonomous authority.",
            'why_recommended' => 'The safe default preserves current financial state.',
            'blocks' => 'The proposed finance action.',
        ];
        $body = '<!-- factory-human-gate '
            . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . ' -->';
        HumanGate::fromIssueBody($body);
        return $body;
    }

    private static function evidenceState(array $snapshot, array $planning): string
    {
        if (($planning['status'] ?? null) === 'unavailable') {
            return 'unavailable';
        }

        $freshness = self::handoffFreshness($snapshot, $planning);
        return match ($freshness) {
            'fresh' => ($planning['status'] ?? null) === 'available' ? 'green' : 'unknown',
            'stale' => 'stale',
            default => 'unknown',
        };
    }

    private static function handoffFreshness(array $snapshot, array $planning): string
    {
        $values = [$snapshot['freshness'] ?? 'unknown'];
        foreach (($planning['series'] ?? []) as $series) {
            if (is_array($series)) {
                $values[] = $series['freshness'] ?? 'unknown';
            }
        }
        if (in_array('unknown', $values, true)) {
            return 'unknown';
        }
        return in_array('stale', $values, true) ? 'stale' : 'fresh';
    }

    private static function evidenceRefs(array $snapshot, array $planning): array
    {
        $refs = [$snapshot['source_ref']];
        foreach (($planning['series'] ?? []) as $series) {
            if (is_array($series) && is_string($series['source_ref'] ?? null)) {
                $refs[] = $series['source_ref'];
            }
        }
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);
        return $refs;
    }

    private static function workRequest(array $work): array
    {
        self::exact($work, [
            'work_id', 'origin_mode', 'group_id', 'venture_id', 'repository_ref',
            'work_type', 'requested_capabilities', 'required_roles', 'authority_level',
            'priority_class', 'depends_on', 'claims', 'policy_ref', 'budget_ref',
            'approval_ref', 'idempotency_key',
        ], 'work');

        $originMode = self::oneOf($work['origin_mode'] ?? null, ['directed', 'automatic'], 'origin_mode');
        $type = self::oneOf($work['work_type'] ?? null, self::WORK_TYPES, 'work_type');
        $priority = self::oneOf($work['priority_class'] ?? null, self::PRIORITIES, 'priority_class');
        $controlAuthority = self::oneOf(
            $work['authority_level'] ?? null,
            array_keys(self::AUTHORITY_TO_FACTORY),
            'authority_level',
        );

        return [
            'work_id' => self::refText($work['work_id'] ?? null, 'work_id', 128),
            'origin_mode' => $originMode,
            'group_id' => self::refText($work['group_id'] ?? null, 'group_id', 120),
            'venture_id' => self::slug($work['venture_id'] ?? null, 'venture_id'),
            'repository_ref' => self::repository($work['repository_ref'] ?? null),
            'work_type' => $type,
            'requested_capabilities' => self::list($work['requested_capabilities'] ?? null, 'requested_capabilities', true),
            'required_roles' => self::list($work['required_roles'] ?? null, 'required_roles', true),
            'authority_level' => self::AUTHORITY_TO_FACTORY[$controlAuthority],
            'priority_class' => $priority,
            'depends_on' => self::list($work['depends_on'] ?? null, 'depends_on', false),
            'claims' => self::list($work['claims'] ?? null, 'claims', false),
            'policy_ref' => self::refText($work['policy_ref'] ?? null, 'policy_ref', 180),
            'budget_ref' => self::nullableRef($work['budget_ref'] ?? null, 'budget_ref'),
            'approval_ref' => self::nullableRef($work['approval_ref'] ?? null, 'approval_ref'),
            'idempotency_key' => self::refText($work['idempotency_key'] ?? null, 'idempotency_key', 128),
        ];
    }

    private static function exact(array $row, array $expected, string $label): void
    {
        $allowed = array_fill_keys($expected, true);
        if (count($row) !== count($allowed) || array_diff_key($row, $allowed) !== []) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function scope(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^venture:[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $value;
    }

    private static function slug(mixed $value, string $label): string
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function oneOf(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function list(mixed $value, string $label, bool $required): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50
            || ($required && $value === [])) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $out = [];
        foreach ($value as $item) {
            $normalized = self::refText($item, $label, 180);
            $out[$normalized] = true;
        }
        $result = array_keys($out);
        sort($result, SORT_STRING);
        return $result;
    }

    private static function repository(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('repository_ref invalid.');
        }
        return $value;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::refText($value, $label, 180);
    }

    private static function refText(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\r\n\x00]/', $value) === 1
            || str_contains($value, '@')
            || preg_match('/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|iban|account[_ -]?number)/i', $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }

    private static function reason(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            return 'invalid_reason';
        }
        return $value;
    }
}

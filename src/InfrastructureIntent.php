<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use ControlBot\Business\CapitalPolicy;
use ControlBot\Production\CapabilityPolicy;
use ControlBot\Runner\RunnerGateway;
use InvalidArgumentException;

final class InfrastructureIntent
{
    private const TYPES = ['read', 'plan', 'restart', 'capacity', 'rollback', 'reconcile'];
    private const MUTATING = ['restart', 'capacity', 'rollback', 'reconcile'];
    private const BLAST = ['low', 'medium', 'high', 'unknown'];
    private const AUTHORITY = ['allow', 'deny', 'owner_decision_required', 'unknown'];
    private const PRIORITIES = ['critical', 'high', 'medium'];
    private const FACTORY_ROLES = [
        'arquitectura',
        'infraestructura',
        'ingenieria-software',
        'qa',
        'seguridad',
        'sre',
    ];

    public static function plan(array $raw, array $authorityRaw, ?array $capitalInput, int $now): array
    {
        if ($now < 1) {
            throw new InvalidArgumentException('InfrastructureIntent clock invalid.');
        }

        $intent = self::intent($raw);
        $authority = self::authority($authorityRaw);
        $basePolicy = CapabilityPolicy::classify($intent['capability']);
        $policy = CapabilityPolicy::classify(
            $intent['capability'],
            $authority['policy_restrictions'],
        );
        $mutating = in_array($intent['intent_type'], self::MUTATING, true);
        $capital = null;
        $deny = [];
        $owner = [];

        if ($authority['scope'] !== $intent['scope']) {
            $deny[] = 'authority_scope_mismatch';
        } elseif ($authority['decision'] === 'deny') {
            $deny[] = $authority['reason_code'];
        } elseif ($authority['decision'] === 'owner_decision_required') {
            $owner[] = $authority['reason_code'];
        } elseif ($authority['decision'] === 'unknown') {
            $deny[] = 'authority_unknown';
        }

        if (!$basePolicy['known'] || $basePolicy['decision'] === 'forbidden') {
            $deny[] = $basePolicy['reason'];
        } else {
            if ($mutating && $basePolicy['decision'] === 'automatic') {
                $deny[] = 'mutating_capability_too_weak';
            }
            if (!$mutating && $basePolicy['decision'] !== 'automatic') {
                $deny[] = 'readonly_intent_with_write_capability';
            }
        }

        if (!$policy['known'] || $policy['decision'] === 'forbidden') {
            $deny[] = $policy['reason'];
        } elseif ($policy['requires_owner_approval']) {
            $owner[] = 'policy_owner_required';
        }

        if ($intent['blast_radius'] === 'high') {
            $owner[] = 'high_blast_radius';
        } elseif ($intent['blast_radius'] === 'unknown') {
            $deny[] = 'blast_radius_unknown';
        }
        if ($intent['evidence']['irreversible']) {
            $owner[] = 'irreversible_action';
        }
        if ($policy['requires_backup'] && $intent['evidence']['safe_point_ref'] === null) {
            $deny[] = 'backup_required_without_safe_point';
        }

        if ($intent['cost_applicable']) {
            if ($intent['budget_ref'] === null || $intent['cost_ref'] === null || $capitalInput === null) {
                $deny[] = 'financial_evidence_unknown';
            } else {
                if (($capitalInput['scope'] ?? null) !== $intent['scope']) {
                    $deny[] = 'capital_scope_mismatch';
                }
                $capital = CapitalPolicy::evaluate($capitalInput, $now);
                $capitalDecision = $capital['decision'] ?? null;
                if ($capitalDecision === 'deny') {
                    $deny[] = 'capital_policy_denied';
                    array_push($deny, ...($capital['reasons'] ?? []));
                } elseif ($capitalDecision === 'owner_decision_required') {
                    array_push($owner, ...($capital['reasons'] ?? ['capital_owner_decision']));
                } elseif ($capitalDecision !== 'allow') {
                    $deny[] = 'capital_policy_unknown';
                }
            }
        } elseif ($capitalInput !== null) {
            throw new InvalidArgumentException('capital policy applicability mismatch.');
        }

        $deny = self::reasons($deny);
        $owner = self::reasons($owner);
        $status = $deny !== []
            ? 'denied'
            : ($owner !== [] ? 'owner_decision_required' : 'planned');

        $workItem = null;
        $runnerRequest = null;
        if ($status !== 'denied') {
            if ($status === 'owner_decision_required' && $intent['approval_ref'] === null) {
                $intent['approval_ref'] = 'controlbot:approval/infra-' . $intent['intent_id'];
            }
            $workItem = self::factoryWorkItem($intent, $authority, $capital);
            if ($status === 'planned') {
                $runnerRequest = self::runnerRequest([
                    'version' => 1,
                    'work_item_id' => $workItem['work_id'],
                    'capability' => $intent['capability'],
                    'scope' => $intent['scope'],
                    'instruction_ref' => $intent['instruction_ref'],
                    'evidence_refs' => $workItem['evidence_refs'],
                    'verify_after_write_ref' => $intent['evidence']['verify_ref'],
                ]);
            }
        }

        return [
            'version' => 1,
            'status' => $status,
            'execution' => false,
            'intent' => [
                'intent_id' => $intent['intent_id'],
                'intent_type' => $intent['intent_type'],
                'scope' => $intent['scope'],
                'blast_radius' => $intent['blast_radius'],
                'capability' => $intent['capability'],
                'mutating' => $mutating,
                'cost_applicable' => $intent['cost_applicable'],
            ],
            'authority' => [
                'decision' => $authority['decision'],
                'reason_code' => $authority['reason_code'],
                'scope' => $authority['scope'],
                'evidence_ref' => $authority['evidence_ref'],
                'policy' => $policy,
            ],
            'capital' => $capital,
            'evidence_contract' => $intent['evidence'],
            'reasons' => $deny !== [] ? $deny : $owner,
            'work_item' => $workItem,
            'runner_request' => $runnerRequest,
            'owner_decision_gate' => $status === 'owner_decision_required'
                ? self::ownerGate(
                    $intent['intent_id'],
                    $intent['scope'],
                    $owner,
                    $workItem['approval_ref'],
                )
                : null,
        ];
    }

    public static function toRunnerOrder(array $runnerRequestRaw, array $assignment, int $now): array
    {
        $request = self::runnerRequest($runnerRequestRaw);
        self::fields($assignment, [
            'order_id', 'attempt_id', 'generation', 'runner_id', 'attempt', 'expires_at',
        ], 'RunnerAssignment');
        if ($now < 1 || !is_int($assignment['expires_at']) || $assignment['expires_at'] <= $now) {
            throw new InvalidArgumentException('RunnerAssignment timing invalid.');
        }

        return RunnerGateway::order([
            'version' => 1,
            'order_id' => self::uuid($assignment['order_id'], 'order_id'),
            'attempt_id' => self::uuid($assignment['attempt_id'], 'attempt_id'),
            'generation' => self::positiveInt($assignment['generation'], 'generation'),
            'work_item_id' => $request['work_item_id'],
            'runner_id' => self::uuid($assignment['runner_id'], 'runner_id'),
            'capability' => $request['capability'],
            'attempt' => self::positiveInt($assignment['attempt'], 'attempt'),
            'scope' => $request['scope'],
            'issued_at' => $now,
            'expires_at' => $assignment['expires_at'],
            'instruction_ref' => $request['instruction_ref'],
        ]);
    }

    private static function intent(array $raw): array
    {
        self::fields($raw, [
            'version', 'intent_id', 'origin_mode', 'group_id', 'venture_id', 'project_id',
            'repository_ref', 'intent_type', 'priority', 'scope', 'blast_radius', 'capability',
            'depends_on', 'claims', 'policy_ref', 'evidence_refs', 'idempotency_key',
            'authority_level', 'budget_ref', 'approval_ref', 'instruction_ref',
            'cost_applicable', 'cost_ref', 'evidence',
        ], 'InfrastructureIntent');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('InfrastructureIntent version invalid.');
        }
        if (!is_bool($raw['cost_applicable'])) {
            throw new InvalidArgumentException('cost_applicable invalid.');
        }

        $type = self::enum($raw['intent_type'], self::TYPES, 'intent_type');
        $repository = self::safeText($raw['repository_ref'], 'repository_ref', 160);
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository) !== 1) {
            throw new InvalidArgumentException('repository_ref invalid.');
        }
        $instruction = self::ref($raw['instruction_ref'], 'instruction_ref');
        if (!str_starts_with($instruction, 'controlbot:')) {
            throw new InvalidArgumentException('instruction_ref must belong to ControlBot.');
        }

        return [
            'version' => 1,
            'intent_id' => self::id($raw['intent_id'], 'intent_id'),
            'origin_mode' => self::enum($raw['origin_mode'], ['directed', 'automatic'], 'origin_mode'),
            'group_id' => self::factorySlug($raw['group_id'], 'group_id'),
            'venture_id' => self::safeText($raw['venture_id'], 'venture_id', 120),
            'project_id' => self::safeText($raw['project_id'], 'project_id', 120),
            'repository_ref' => $repository,
            'intent_type' => $type,
            'priority' => self::enum($raw['priority'], self::PRIORITIES, 'priority'),
            'scope' => self::ref($raw['scope'], 'scope'),
            'blast_radius' => self::enum($raw['blast_radius'], self::BLAST, 'blast_radius'),
            'capability' => self::capability($raw['capability']),
            'depends_on' => self::strings($raw['depends_on'], 'depends_on', 50),
            'claims' => self::strings($raw['claims'], 'claims', 50),
            'policy_ref' => self::ref($raw['policy_ref'], 'policy_ref'),
            'evidence_refs' => self::strings($raw['evidence_refs'], 'evidence_refs', 50),
            'idempotency_key' => self::safeText($raw['idempotency_key'], 'idempotency_key', 128),
            'authority_level' => self::factorySlug($raw['authority_level'], 'authority_level'),
            'budget_ref' => self::nullableRef($raw['budget_ref'], 'budget_ref'),
            'approval_ref' => self::nullableRef($raw['approval_ref'], 'approval_ref'),
            'instruction_ref' => $instruction,
            'cost_applicable' => $raw['cost_applicable'],
            'cost_ref' => self::nullableRef($raw['cost_ref'], 'cost_ref'),
            'evidence' => self::evidence($raw['evidence'], $type),
        ];
    }

    private static function authority(array $raw): array
    {
        self::fields($raw, [
            'decision', 'reason_code', 'scope', 'evidence_ref', 'policy_restrictions',
        ], 'AuthorityProjection');
        if (!is_array($raw['policy_restrictions']) || !array_is_list($raw['policy_restrictions'])
            || count($raw['policy_restrictions']) > 8) {
            throw new InvalidArgumentException('policy_restrictions invalid.');
        }
        return [
            'decision' => self::enum($raw['decision'], self::AUTHORITY, 'authority.decision'),
            'reason_code' => self::factorySlug($raw['reason_code'], 'authority.reason_code'),
            'scope' => self::ref($raw['scope'], 'authority.scope'),
            'evidence_ref' => self::ref($raw['evidence_ref'], 'authority.evidence_ref'),
            'policy_restrictions' => $raw['policy_restrictions'],
        ];
    }

    private static function evidence(mixed $raw, string $type): array
    {
        self::fields($raw, [
            'plan_ref', 'impact_ref', 'rollback_ref', 'safe_point_ref', 'verify_ref', 'irreversible',
        ], 'InfrastructureEvidence');
        if (!is_bool($raw['irreversible'])) {
            throw new InvalidArgumentException('evidence.irreversible invalid.');
        }
        $out = [
            'plan_ref' => self::nullableRef($raw['plan_ref'], 'evidence.plan_ref'),
            'impact_ref' => self::nullableRef($raw['impact_ref'], 'evidence.impact_ref'),
            'rollback_ref' => self::nullableRef($raw['rollback_ref'], 'evidence.rollback_ref'),
            'safe_point_ref' => self::nullableRef($raw['safe_point_ref'], 'evidence.safe_point_ref'),
            'verify_ref' => self::nullableRef($raw['verify_ref'], 'evidence.verify_ref'),
            'irreversible' => $raw['irreversible'],
        ];

        $mutating = in_array($type, self::MUTATING, true);
        if ($mutating) {
            if ($out['plan_ref'] === null || $out['impact_ref'] === null || $out['verify_ref'] === null) {
                throw new InvalidArgumentException(
                    'Mutating intent requires plan, impact and verify-after-write evidence.'
                );
            }
            if ($out['rollback_ref'] === null && $out['safe_point_ref'] === null) {
                throw new InvalidArgumentException('Mutating intent requires rollback or safe point.');
            }
        } elseif (
            $out['irreversible']
            || array_filter(
                [$out['plan_ref'], $out['impact_ref'], $out['rollback_ref'], $out['safe_point_ref'], $out['verify_ref']],
                static fn(mixed $value): bool => $value !== null,
            ) !== []
        ) {
            throw new InvalidArgumentException('Read-only intent cannot carry mutation evidence.');
        }
        return $out;
    }

    private static function factoryWorkItem(array $intent, array $authority, ?array $capital): array
    {
        $evidence = $intent['evidence_refs'];
        $evidence[] = $authority['evidence_ref'];
        foreach ([
            $intent['cost_ref'],
            $intent['evidence']['plan_ref'],
            $intent['evidence']['impact_ref'],
            $intent['evidence']['safe_point_ref'],
            $intent['evidence']['rollback_ref'],
            $intent['evidence']['verify_ref'],
            $capital['budget']['source_ref'] ?? null,
            $capital['reserve']['source_ref'] ?? null,
        ] as $ref) {
            if (is_string($ref)) {
                $evidence[] = $ref;
            }
        }
        $evidence = self::strings($evidence, 'factory.evidence_refs', 50);

        $work = [
            'work_id' => 'infra-' . $intent['intent_id'],
            'origin_mode' => $intent['origin_mode'],
            'origin_system' => 'controlbot',
            'group_id' => $intent['group_id'],
            'work_type' => 'infrastructure',
            'requested_capabilities' => [$intent['capability']],
            'required_roles' => self::FACTORY_ROLES,
            'authority_level' => $intent['authority_level'],
            'producer_ref' => 'controlbot:infrastructure-intent/' . $intent['intent_id'],
            'priority_class' => $intent['priority'],
            'depends_on' => $intent['depends_on'],
            'claims' => $intent['claims'],
            'policy_ref' => $intent['policy_ref'],
            'evidence_refs' => $evidence,
            'idempotency_key' => $intent['idempotency_key'],
            'venture_id' => $intent['venture_id'],
            'project_id' => $intent['project_id'],
            'repository_ref' => $intent['repository_ref'],
        ];
        if ($intent['budget_ref'] !== null) {
            $work['budget_ref'] = $intent['budget_ref'];
        }
        if ($intent['approval_ref'] !== null) {
            $work['approval_ref'] = $intent['approval_ref'];
        }
        return $work;
    }

    private static function runnerRequest(array $raw): array
    {
        self::fields($raw, [
            'version', 'work_item_id', 'capability', 'scope', 'instruction_ref',
            'evidence_refs', 'verify_after_write_ref',
        ], 'RunnerRequest');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('RunnerRequest version invalid.');
        }
        $instruction = self::ref($raw['instruction_ref'], 'runner.instruction_ref');
        if (!str_starts_with($instruction, 'controlbot:')) {
            throw new InvalidArgumentException('runner instruction_ref must belong to ControlBot.');
        }
        return [
            'version' => 1,
            'work_item_id' => self::safeText($raw['work_item_id'], 'runner.work_item_id', 160),
            'capability' => self::capability($raw['capability']),
            'scope' => self::ref($raw['scope'], 'runner.scope'),
            'instruction_ref' => $instruction,
            'evidence_refs' => self::strings($raw['evidence_refs'], 'runner.evidence_refs', 50),
            'verify_after_write_ref' => self::nullableRef(
                $raw['verify_after_write_ref'],
                'runner.verify_after_write_ref',
            ),
        ];
    }

    private static function ownerGate(
        string $id,
        string $scope,
        array $reasons,
        string $approvalRef,
    ): string
    {
        $money = false;
        foreach ($reasons as $reason) {
            if (preg_match('/(?:budget|capital|reserve|cost|money)/', $reason) === 1) {
                $money = true;
                break;
            }
        }
        $payload = [
            'category' => $money ? 'money' : 'product-direction',
            'context' => "Infrastructure intent {$id} in {$scope} requires Owner Decision before dispatch.",
            'options' => [
                [
                    'id' => 'A',
                    'label' => 'Authorize a separately validated follow-up',
                    'effect' => 'Keeps the current intent non-executable; a fresh validated follow-up may proceed.',
                    'pros' => ['Allows the requested change after explicit authority review.'],
                    'cons' => ['Requires fresh policy, impact, cost and verification evidence.'],
                    'risk' => 'medium',
                    'cost' => 'Only the cost already evaluated by CapitalPolicy, if any.',
                    'reversible' => true,
                ],
                [
                    'id' => 'B',
                    'label' => 'Keep the intent blocked',
                    'effect' => 'No Runner order is emitted and no mutation is authorized.',
                    'pros' => ['Preserves current infrastructure and authority boundaries.'],
                    'cons' => ['The requested change remains pending.'],
                    'risk' => 'low',
                    'cost' => 'No additional execution cost.',
                    'reversible' => true,
                ],
            ],
            'recommendation' => 'B',
            'safe_default' => 'B',
            'title_simple' => 'Infrastructure change needs owner approval',
            'summary_simple' => "Intent {$id} cannot be dispatched automatically.",
            'why_recommended' => 'The safe default preserves infrastructure until authority, impact and cost are explicitly accepted.',
            'blocks' => "Infrastructure intent {$id}.",
            'approval_ref' => $approvalRef,
        ];
        return '<!-- factory-human-gate '
            . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . " -->\n<!-- controlbot-infrastructure-intent "
            . json_encode(
                [
                    'intent_id' => $id,
                    'scope' => $scope,
                    'reasons' => $reasons,
                    'approval_ref' => $approvalRef,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )
            . ' -->';
    }

    private static function reasons(array $values): array
    {
        $out = array_values(array_unique(array_filter(
            $values,
            static fn(mixed $value): bool => is_string($value) && $value !== '',
        )));
        sort($out);
        return $out;
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

    private static function strings(mixed $values, string $label, int $limit): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > $limit) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $out = [];
        foreach ($values as $value) {
            $normalized = self::safeText($value, $label, 240);
            $out[$normalized] = true;
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }

    private static function safeText(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match(
                '/(?:-----BEGIN [^-]*PRIVATE KEY-----|\bbearer\s+\S+|(?:password|passwd|secret|token|api[_-]?key|private[_-]?key)\s*[:=\/]|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{10,}|\bsk-[A-Za-z0-9_-]{12,})/i',
                $value,
            ) === 1) {
            throw new InvalidArgumentException($label . ' invalid or sensitive.');
        }
        return $value;
    }

    private static function ref(mixed $value, string $label): string
    {
        $value = self::safeText($value, $label, 240);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/#-]{0,239}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function nullableRef(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::ref($value, $label);
    }

    private static function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function factorySlug(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function capability(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1) {
            throw new InvalidArgumentException('capability invalid.');
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function uuid(mixed $value, string $label): string
    {
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return strtolower($value);
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Legal;

use InvalidArgumentException;

final class LexGate
{
    private const KINDS = ['material_uncertainty', 'executable_gap'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    private const WORK_TYPES = [
        'compliance_review',
        'security',
        'product',
        'knowledge_documentation',
        'marketing_growth',
    ];
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code|session|credential)/i';

    public static function evaluate(array $input, int $now): array
    {
        self::fields($input, [
            'version', 'kind', 'gap_id', 'scope', 'question', 'freshness',
            'severity', 'policy_ref', 'evidence_refs', 'observed_at',
            'work_type', 'requested_capabilities', 'required_roles',
            'group_id', 'venture_id', 'project_id', 'repository_ref',
            'authority_level', 'producer_ref',
        ], 'lex gate');

        if (($input['version'] ?? null) !== 1 || $now < 1) {
            throw new InvalidArgumentException('lex gate version/now invalid.');
        }

        $kind = self::enum($input['kind'] ?? null, self::KINDS, 'kind');
        $gapId = self::id($input['gap_id'] ?? null, 'gap_id');
        $scope = self::scope($input['scope'] ?? null);
        $question = self::question($input['question'] ?? null);
        $freshness = self::enum($input['freshness'] ?? null, self::FRESHNESS, 'freshness');
        $severity = self::enum($input['severity'] ?? null, self::SEVERITIES, 'severity');
        $policyRef = self::ref($input['policy_ref'] ?? null, 'policy_ref');
        $evidenceRefs = self::refs($input['evidence_refs'] ?? null, 'evidence_refs', true);
        $observedAt = self::time($input['observed_at'] ?? null, 'observed_at');
        if ($observedAt > $now) {
            throw new InvalidArgumentException('observed_at in the future.');
        }

        $base = [
            'version' => 1,
            'kind' => $kind,
            'gap_id' => $gapId,
            'scope' => $scope,
            'freshness' => $freshness,
            'severity' => $severity,
            'policy_ref' => $policyRef,
            'evidence_refs' => $evidenceRefs,
            'observed_at' => $observedAt,
            'ready_hint' => $freshness === 'fresh' && $kind !== 'material_uncertainty',
            'authority_effect' => 'none',
            'auto_execute' => false,
        ];

        if ($kind === 'material_uncertainty') {
            return $base + [
                'human_gate' => self::humanGate(
                    $gapId,
                    $scope,
                    $question,
                    $severity,
                    $policyRef,
                    $evidenceRefs,
                ),
                'work_item' => null,
            ];
        }

        return $base + [
            'human_gate' => null,
            'work_item' => self::workItem($input, $gapId, $scope, $severity, $policyRef, $evidenceRefs, $observedAt),
        ];
    }

    public static function evaluateBatch(array $inputs, int $now): array
    {
        if (!array_is_list($inputs) || count($inputs) > 200) {
            throw new InvalidArgumentException('lex gate batch invalid.');
        }

        $byKey = [];
        foreach ($inputs as $input) {
            if (!is_array($input)) {
                throw new InvalidArgumentException('lex gate batch item invalid.');
            }
            $result = self::evaluate($input, $now);
            $item = $result['work_item'];
            if ($item === null) {
                continue;
            }
            $key = $item['idempotency_key'];
            if (isset($byKey[$key]) && $byKey[$key] !== $item) {
                throw new InvalidArgumentException('work item idempotency conflict.');
            }
            $byKey[$key] = $item;
        }

        ksort($byKey, SORT_STRING);
        return array_values($byKey);
    }

    private static function humanGate(
        string $gapId,
        string $scope,
        string $question,
        string $severity,
        string $policyRef,
        array $evidenceRefs,
    ): array {
        $context = sprintf(
            'LEX detectó una incertidumbre jurídica material en %s (%s). La decisión humana no amplía autoridad y solo afecta ese scope.',
            $scope,
            $gapId,
        );

        return [
            'category' => 'legal',
            'context' => $context,
            'title_simple' => $question,
            'summary_simple' => 'Existe una interpretación jurídica no demostrada. El scope afectado permanece bloqueado hasta una decisión humana explícita.',
            'why_recommended' => 'Mantener el scope bloqueado evita tratar una interpretación incierta como aprobación jurídica.',
            'blocks' => $scope,
            'options' => [
                [
                    'id' => 'A',
                    'label' => 'Registrar decisión jurídica humana',
                    'effect' => 'Permite registrar una decisión explícita para reevaluar únicamente el scope afectado.',
                    'pros' => ['Desbloquea una reevaluación trazable'],
                    'cons' => ['Requiere decisión humana explícita'],
                    'risk' => $severity === 'critical' || $severity === 'high' ? 'high' : 'medium',
                    'cost' => 'Sin costo técnico',
                    'reversible' => true,
                ],
                [
                    'id' => 'B',
                    'label' => 'Mantener scope bloqueado',
                    'effect' => 'No se concede aprobación y el scope afectado continúa bloqueado.',
                    'pros' => ['No amplía autoridad', 'Conserva el default fail-closed'],
                    'cons' => ['El trabajo dependiente permanece bloqueado'],
                    'risk' => 'low',
                    'cost' => 'Sin costo',
                    'reversible' => true,
                ],
            ],
            'recommendation' => 'B',
            'safe_default' => 'B',
        ];
    }

    private static function workItem(
        array $input,
        string $gapId,
        string $scope,
        string $severity,
        string $policyRef,
        array $evidenceRefs,
        int $observedAt,
    ): array {
        $workType = self::enum($input['work_type'] ?? null, self::WORK_TYPES, 'work_type');
        $capabilities = self::ids($input['requested_capabilities'] ?? null, 'requested_capabilities', false);
        $roles = self::ids($input['required_roles'] ?? null, 'required_roles', false);
        $groupId = self::id($input['group_id'] ?? null, 'group_id');
        $ventureId = self::nullableId($input['venture_id'] ?? null, 'venture_id');
        $projectId = self::nullableId($input['project_id'] ?? null, 'project_id');
        $repositoryRef = self::nullableRepository($input['repository_ref'] ?? null);
        $authorityLevel = self::id($input['authority_level'] ?? null, 'authority_level');
        $producerRef = self::ref($input['producer_ref'] ?? null, 'producer_ref');

        $idempotency = 'lex-gap:' . hash('sha256', implode("\n", [
            $scope,
            $gapId,
            $workType,
            $policyRef,
        ]));

        $item = [
            'work_id' => 'lex:' . $gapId,
            'origin_mode' => 'automatic',
            'origin_system' => 'controlbot',
            'group_id' => $groupId,
            'work_type' => $workType,
            'requested_capabilities' => $capabilities,
            'required_roles' => $roles,
            'authority_level' => $authorityLevel,
            'producer_ref' => $producerRef,
            'priority_class' => self::priority($severity),
            'severity' => $severity,
            'depends_on' => [],
            'claims' => [$scope],
            'policy_ref' => $policyRef,
            'evidence_refs' => $evidenceRefs,
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z', $observedAt),
            'idempotency_key' => $idempotency,
        ];

        if ($ventureId !== null) $item['venture_id'] = $ventureId;
        if ($projectId !== null) $item['project_id'] = $projectId;
        if ($repositoryRef !== null) $item['repository_ref'] = $repositoryRef;

        return $item;
    }

    private static function priority(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'high' => 'high',
            default => 'medium',
        };
    }

    private static function fields(array $row, array $expected, string $label): void
    {
        if (array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $actual = array_keys($row);
        $missing = array_diff($expected, $actual);
        $unexpected = array_diff($actual, $expected);
        if ($missing !== [] || $unexpected !== []) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function scope(mixed $value): string
    {
        return self::token(
            $value,
            'scope',
            '/^(?:group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D',
            3,
            80,
        );
    }

    private static function id(mixed $value, string $label): string
    {
        return self::token(
            $value,
            $label,
            '/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,9}$/D',
            1,
            140,
        );
    }

    private static function nullableId(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::id($value, $label);
    }

    private static function question(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('question invalid.');
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($normalized === ''
            || strlen($normalized) > 140
            || preg_match(self::SENSITIVE, $normalized) === 1) {
            throw new InvalidArgumentException('question invalid.');
        }

        return $normalized;
    }

    private static function ids(mixed $value, string $label, bool $allowEmpty): array
    {
        return self::uniqueList($value, $label, $allowEmpty, 'id');
    }

    private static function refs(mixed $value, string $label, bool $allowEmpty): array
    {
        return self::uniqueList($value, $label, $allowEmpty, 'ref');
    }

    private static function uniqueList(
        mixed $value,
        string $label,
        bool $allowEmpty,
        string $kind,
    ): array {
        if (!is_array($value)
            || !array_is_list($value)
            || count($value) > 50
            || (!$allowEmpty && $value === [])) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $unique = [];
        foreach ($value as $item) {
            $normalized = $kind === 'id'
                ? self::id($item, $label)
                : self::ref($item, $label);
            $unique[$normalized] = true;
        }

        $items = array_keys($unique);
        sort($items, SORT_STRING);
        return $items;
    }

    private static function ref(mixed $value, string $label): string
    {
        $ref = self::token(
            $value,
            $label,
            '#^controlbot:[A-Za-z0-9][A-Za-z0-9._:/\\#-]+$#D',
            8,
            220,
        );
        if (str_contains($ref, '..')) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $ref;
    }

    private static function nullableRepository(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)
            || strlen($value) > 160
            || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('repository_ref invalid.');
        }

        return $value;
    }

    private static function token(
        mixed $value,
        string $label,
        string $pattern,
        int $minLength,
        int $maxLength,
    ): string {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $length = strlen($value);
        if ($length < $minLength
            || $length > $maxLength
            || preg_match($pattern, $value) !== 1
            || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function time(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($label . ' invalid.');
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

}

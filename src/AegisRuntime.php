<?php
declare(strict_types=1);

namespace ControlBot\Security;

use InvalidArgumentException;

final class AegisRuntime
{
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|otp|session|credential)/i';
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    private const TYPES = ['security', 'compliance_review', 'infrastructure'];

    public static function integrate(array $input, int $now): array
    {
        self::exact($input, ['version','group_id','venture_id','project_id','repository_ref','finding','remediation_input']);
        if (($input['version'] ?? null) !== 1 || $now < 1) throw new InvalidArgumentException('runtime invalid.');

        $finding = self::finding($input['finding'] ?? null);
        $remediation = $input['remediation_input'] ?? null;
        if (!is_array($remediation) || array_is_list($remediation)) throw new InvalidArgumentException('remediation invalid.');

        $plan = AegisRemediation::plan($remediation, $now);
        if (($plan['finding_id'] ?? null) !== $finding['finding_id']
            || ($plan['scope'] ?? null) !== $finding['scope']
            || ($plan['proposal']['policy_ref'] ?? null) !== $finding['policy_ref']) {
            throw new InvalidArgumentException('provenance mismatch.');
        }

        $group = self::slug($input['group_id'] ?? null, 'group_id');
        $venture = self::slug($input['venture_id'] ?? null, 'venture_id');
        $project = self::slug($input['project_id'] ?? null, 'project_id');
        $repo = self::text($input['repository_ref'] ?? null, 'repository_ref', '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D');
        $type = $finding['category'];
        $key = 'aegis:' . hash('sha256', implode("\n", [
            $group,
            $venture,
            $project,
            $finding['finding_id'],
            $finding['scope'],
            $type,
            $finding['policy_ref'],
            $plan['proposal']['action'],
        ]));
        $evidence = self::set(array_merge($finding['evidence_refs'], $plan['verification']['evidence_refs'] ?? []), 'evidence_refs');
        $owner = ($plan['decision'] ?? null) === 'owner_decision_required';
        $approval = $owner ? 'controlbot:owner-gate/' . $finding['finding_id'] : null;

        $item = [
            'work_id' => 'aegis-work:' . substr(hash('sha256', $key), 0, 32),
            'origin_mode' => 'automatic',
            'origin_system' => 'aegis',
            'group_id' => $group,
            'venture_id' => $venture,
            'project_id' => $project,
            'repository_ref' => $repo,
            'work_type' => $type,
            'requested_capabilities' => [match ($type) {
                'security' => 'security_remediation',
                'compliance_review' => 'compliance_review',
                'infrastructure' => 'infrastructure_remediation',
            }],
            'required_roles' => match ($type) {
                'security' => ['seguridad','sre'],
                'compliance_review' => ['legal_privacidad','seguridad'],
                'infrastructure' => ['infraestructura','sre'],
            },
            'authority_level' => self::authority($plan['proposal']['required_authority_level'] ?? null),
            'producer_ref' => 'controlbot:aegis',
            'priority_class' => in_array($finding['severity'], ['critical','high'], true) ? $finding['severity'] : 'medium',
            'severity' => $finding['severity'],
            'depends_on' => [],
            'claims' => ['aegis:' . $finding['scope']],
            'policy_ref' => $finding['policy_ref'],
            'evidence_refs' => $evidence,
            'observed_at' => $finding['observed_at'],
            'idempotency_key' => $key,
        ];
        if ($approval !== null) $item['approval_ref'] = $approval;

        return [
            'work_item' => $item,
            'source_freshness' => $finding['freshness'],
            'ready_hint' => $finding['freshness'] === 'fresh'
                && ($plan['decision'] ?? null) === 'auto_eligible'
                && ($plan['auto_eligible'] ?? false) === true,
            'owner_gate' => $owner ? [
                'gate_ref' => $approval,
                'required_authority_level' => 'L4_OWNER',
                'reason_codes' => self::set($plan['reasons'] ?? [], 'reason_codes'),
                'auto_execute' => false,
            ] : null,
            'living_feedback' => [
                'version' => 1,
                'candidate_id' => 'aegis-feedback:' . hash('sha256', $key),
                'source_ref' => $item['work_id'],
                'scope' => $finding['scope'],
                'kind' => 'lesson_candidate',
                'lesson_key' => 'aegis.' . $type . '.' . $finding['severity'],
                'evidence_refs' => $evidence,
                'freshness' => $finding['freshness'],
                'authority_effect' => 'none',
                'policy_effect' => 'none',
                'idempotency_key' => 'living:' . hash('sha256', $key),
            ],
            'verification' => [
                'state' => $plan['remediation_state'],
                'evidence_refs' => self::set($plan['verification']['evidence_refs'] ?? [], 'verification.evidence_refs'),
                'verified' => ($plan['remediation_state'] ?? null) === 'success',
            ],
        ];
    }

    public static function integrateBatch(array $inputs, int $now): array
    {
        if (!array_is_list($inputs) || count($inputs) > 200) throw new InvalidArgumentException('batch invalid.');
        $items = [];
        foreach ($inputs as $input) {
            if (!is_array($input)) throw new InvalidArgumentException('batch item invalid.');
            $result = self::integrate($input, $now);
            $key = $result['work_item']['idempotency_key'];
            if (isset($items[$key]) && $items[$key] !== $result) throw new InvalidArgumentException('idempotency conflict.');
            $items[$key] = $result;
        }
        ksort($items, SORT_STRING);
        return array_values($items);
    }

    private static function finding(mixed $row): array
    {
        if (!is_array($row) || array_is_list($row)) throw new InvalidArgumentException('finding invalid.');
        self::exact($row, ['finding_id','scope','category','severity','freshness','policy_ref','evidence_refs','observed_at']);
        $observed = $row['observed_at'] ?? null;
        if (!is_string($observed)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $observed) !== 1
            || strtotime($observed) === false) {
            throw new InvalidArgumentException('observed_at invalid.');
        }
        return [
            'finding_id' => self::slug($row['finding_id'] ?? null, 'finding_id'),
            'scope' => self::text($row['scope'] ?? null, 'scope', '/^(?:group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D'),
            'category' => self::enum($row['category'] ?? null, self::TYPES, 'category'),
            'severity' => self::enum($row['severity'] ?? null, self::SEVERITIES, 'severity'),
            'freshness' => self::enum($row['freshness'] ?? null, self::FRESHNESS, 'freshness'),
            'policy_ref' => self::text($row['policy_ref'] ?? null, 'policy_ref', '#^controlbot:policy/[a-z][a-z0-9._/-]{1,119}$#D'),
            'evidence_refs' => self::set($row['evidence_refs'] ?? null, 'evidence_refs'),
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($observed)),
        ];
    }

    private static function authority(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^L[0-4]_[A-Z][A-Z0-9_]{1,63}$/D', $value) !== 1) throw new InvalidArgumentException('authority invalid.');
        return strtolower($value);
    }

    private static function slug(mixed $value, string $label): string
    {
        return self::text($value, $label, '/^[a-z][a-z0-9_.:-]*(?:-[a-z0-9]+)*$/D');
    }

    private static function text(mixed $value, string $label, string $pattern): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 240 || preg_match($pattern, $value) !== 1 || preg_match(self::SENSITIVE, $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function enum(mixed $value, array $allowed, string $label): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) throw new InvalidArgumentException($label . ' invalid.');
        return $value;
    }

    private static function set(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) throw new InvalidArgumentException($label . ' invalid.');
        $set = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || strlen($item) > 240 || preg_match(self::SENSITIVE, $item) === 1 || str_contains($item, '@') || str_contains($item, '..')) {
                throw new InvalidArgumentException($label . ' invalid.');
            }
            $set[$item] = true;
        }
        $out = array_keys($set); sort($out, SORT_STRING); return $out;
    }

    private static function exact(array $row, array $expected): void
    {
        if (array_diff($expected, array_keys($row)) !== [] || array_diff(array_keys($row), $expected) !== []) {
            throw new InvalidArgumentException('fields invalid.');
        }
    }
}

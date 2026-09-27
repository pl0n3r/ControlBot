<?php
declare(strict_types=1);

namespace ControlBot\Budget;

use InvalidArgumentException;
use LogicException;

final class BudgetGuard
{
    private const STATES = ['normal', 'warning', 'critical', 'exhausted', 'blocked', 'unknown'];
    private const MODES = ['alert_only', 'hard_stop', 'unknown'];
    private const CAPACITY = ['available', 'blocked', 'unknown'];
    private const VISIBILITY = ['public', 'private', 'internal', 'unknown'];

    public function summarize(array $account, array $projects): array
    {
        $a = $this->account($account);
        $rows = $this->projects($projects, $a);
        $baseline = 0.0;
        $attributed = 0.0;
        foreach ($rows as $row) {
            if ($row['counts_toward_scope']) {
                $baseline += $row['projected_baseline_monthly'];
                $attributed += $row['attributed_used'];
            }
        }

        $complete = $a['included'] !== null
            && $a['used'] !== null
            && $a['included'] > 0
            && $a['reset_at'] !== null;
        $utilization = $complete ? round(($a['used'] / $a['included']) * 100, 2) : null;
        $baselinePct = $complete ? round(($baseline / $a['included']) * 100, 2) : null;
        $headroom = $complete
            ? max(0.0, $a['included'] - max($a['used'], $baseline))
            : null;
        $variable = $a['used'] === null ? null : max(0.0, $a['used'] - $attributed);

        return [
            'provider' => $a['provider'],
            'account_scope' => $a['account_scope'],
            'resource' => $a['resource'],
            'included' => $a['included'],
            'used' => $a['used'],
            'billable_used' => $a['billable_used'],
            'reset_at' => $a['reset_at'],
            'budget_limit' => $a['budget_limit'],
            'budget_mode' => $a['budget_mode'],
            'capacity' => $a['capacity'],
            'observed_at' => $a['observed_at'],
            'source' => $a['source'],
            'state' => $this->state($a, $utilization, $baselinePct),
            'utilization_percent' => $utilization,
            'projected_baseline_monthly' => round($baseline, 2),
            'variable_usage' => $variable === null ? null : round($variable, 2),
            'headroom_for_change' => $headroom === null ? null : round($headroom, 2),
            'projects' => $rows,
        ];
    }

    public function workPolicy(array $summary, array $work): array
    {
        $state = $summary['state'] ?? null;
        if (!is_string($state) || !in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Resumen de presupuesto inválido.');
        }
        $allowed = ['critical', 'requires_private_runner', 'pr_open'];
        if (array_diff(array_keys($work), $allowed) !== []) {
            throw new InvalidArgumentException('Trabajo presupuestario inválido.');
        }
        foreach ($allowed as $key) {
            if (!is_bool($work[$key] ?? null)) {
                throw new InvalidArgumentException("Campo {$key} inválido.");
            }
        }

        $capacityRisk = in_array($state, ['blocked', 'exhausted', 'unknown'], true);
        $dependent = $work['requires_private_runner'];
        $blocked = $capacityRisk && $dependent;
        return [
            'auto_executable' => !$blocked,
            'pause_noncritical' => $blocked && !$work['critical'],
            'preserve_pr_fail_closed' => $blocked && $work['pr_open'],
            'reason' => $blocked ? "budget_{$state}" : 'eligible',
        ];
    }

    public function recovery(array $before, array $after, string $sha, ?array $canary = null): array
    {
        $beforeState = $before['state'] ?? null;
        $afterState = $after['state'] ?? null;
        if (!in_array($beforeState, ['blocked', 'exhausted'], true)
            || !in_array($afterState, ['normal', 'warning', 'critical'], true)
            || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new InvalidArgumentException('Recuperación presupuestaria inválida.');
        }

        $release = false;
        if ($canary !== null) {
            $keys = array_keys($canary);
            sort($keys);
            if ($keys !== ['conclusion', 'sha']) {
                throw new InvalidArgumentException('Canario inválido.');
            }
            $release = ($canary['sha'] ?? null) === $sha
                && ($canary['conclusion'] ?? null) === 'success';
        }
        return [
            'required_canaries' => 1,
            'canary_sha' => $sha,
            'release_queue' => $release,
        ];
    }

    public function mutateFinancial(string $action): never
    {
        if ($action === '' || strlen($action) > 80) {
            throw new InvalidArgumentException('Acción financiera inválida.');
        }
        throw new LogicException('Mutaciones financieras no soportadas.');
    }

    private function account(array $raw): array
    {
        $allowed = [
            'provider', 'account_scope', 'resource', 'included', 'used', 'billable_used',
            'reset_at', 'budget_limit', 'budget_mode', 'capacity', 'observed_at', 'source',
        ];
        if (array_diff(array_keys($raw), $allowed) !== []) {
            throw new InvalidArgumentException('Campo de presupuesto no permitido.');
        }
        foreach (['provider', 'account_scope', 'resource'] as $key) {
            if (!is_string($raw[$key] ?? null)
                || preg_match('/^[A-Za-z0-9._\/-]{1,120}$/', $raw[$key]) !== 1) {
                throw new InvalidArgumentException("Campo {$key} inválido.");
            }
        }
        if (!in_array($raw['source'] ?? null, ['github_api', 'provider_api', 'manual_fixture', 'unknown'], true)) {
            throw new InvalidArgumentException('source inválido.');
        }
        foreach (['included', 'used', 'billable_used', 'budget_limit'] as $key) {
            $value = $raw[$key] ?? null;
            if ($value !== null && (!is_numeric($value) || $value < 0)) {
                throw new InvalidArgumentException("Campo {$key} inválido.");
            }
            $raw[$key] = $value === null ? null : (float) $value;
        }
        $reset = $raw['reset_at'] ?? null;
        if ($reset !== null && (!is_string($reset) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $reset) !== 1)) {
            throw new InvalidArgumentException('reset_at inválido.');
        }
        $mode = $raw['budget_mode'] ?? 'unknown';
        $capacity = $raw['capacity'] ?? 'unknown';
        if (!in_array($mode, self::MODES, true) || !in_array($capacity, self::CAPACITY, true)) {
            throw new InvalidArgumentException('Modo/capacidad inválidos.');
        }
        if (!is_int($raw['observed_at'] ?? null) || $raw['observed_at'] < 1) {
            throw new InvalidArgumentException('observed_at inválido.');
        }
        return $raw + ['reset_at' => null, 'budget_mode' => 'unknown', 'capacity' => 'unknown'];
    }

    private function projects(array $rows, array $account): array
    {
        if (!array_is_list($rows) || count($rows) > 50) {
            throw new InvalidArgumentException('Atribuciones de proyecto inválidas.');
        }
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('Atribución inválida.');
            }
            $allowed = ['project', 'repository', 'repository_visibility', 'billing_scope_observed_at', 'attributed_used', 'projected_baseline_monthly'];
            if (array_diff(array_keys($row), $allowed) !== []) {
                throw new InvalidArgumentException('Campo de atribución no permitido.');
            }
            if (!is_string($row['project'] ?? null) || trim($row['project']) === '' || strlen($row['project']) > 120
                || !is_string($row['repository'] ?? null) || preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $row['repository']) !== 1
                || !in_array($row['repository_visibility'] ?? null, self::VISIBILITY, true)
                || !is_int($row['billing_scope_observed_at'] ?? null) || $row['billing_scope_observed_at'] < 1
                || !is_numeric($row['attributed_used'] ?? null) || $row['attributed_used'] < 0
                || !is_numeric($row['projected_baseline_monthly'] ?? null) || $row['projected_baseline_monthly'] < 0) {
                throw new InvalidArgumentException('Atribución inválida.');
            }
            if (isset($seen[$row['repository']])) {
                throw new InvalidArgumentException('Repositorio duplicado en atribución.');
            }
            $seen[$row['repository']] = true;
            $inScope = !($account['provider'] === 'github_actions' && $account['resource'] === 'private_minutes')
                || $row['repository_visibility'] === 'private';
            $out[] = [
                'project' => trim($row['project']),
                'repository' => $row['repository'],
                'repository_visibility' => $row['repository_visibility'],
                'billing_scope_observed_at' => $row['billing_scope_observed_at'],
                'attributed_used' => (float) $row['attributed_used'],
                'projected_baseline_monthly' => (float) $row['projected_baseline_monthly'],
                'counts_toward_scope' => $inScope,
            ];
        }
        return $out;
    }

    private function state(array $a, ?float $utilization, ?float $baselinePct): string
    {
        if ($utilization === null || $baselinePct === null) {
            return 'unknown';
        }
        if ($a['capacity'] === 'blocked') {
            return 'blocked';
        }
        if ($a['used'] >= $a['included']) {
            return $a['budget_mode'] === 'hard_stop' ? 'blocked' : 'exhausted';
        }
        if ($a['capacity'] === 'unknown') {
            return 'unknown';
        }
        $pressure = max($utilization, $baselinePct);
        return $pressure >= 90 ? 'critical' : ($pressure >= 80 ? 'warning' : 'normal');
    }
}

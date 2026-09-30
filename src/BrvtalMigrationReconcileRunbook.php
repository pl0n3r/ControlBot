<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;

final class BrvtalMigrationReconcileRunbook
{
    private const STEPS = [
        'migration.status',
        'database.backup',
        'migration.registry.reconcile',
        'migration.verify',
        'health.check',
        'smoke.run',
    ];

    public static function project(array $input): array
    {
        self::validate($input);

        if ($input['failed_step'] !== null) {
            return self::terminal(
                'recoverable',
                'step_failed',
                $input,
                ['failed_step' => $input['failed_step']]
            );
        }

        if ($input['schema_state'] === 'ambiguous') {
            return self::terminal(
                'blocked',
                'ambiguous_schema',
                $input,
                ['baseline_allowed' => false]
            );
        }

        $done = array_fill_keys($input['completed_steps'], true);
        if (!isset($done['migration.status'])) {
            return self::next('migration.status', 'read', $input);
        }

        if ($input['schema_state'] === 'clean') {
            return self::verificationOrFinish($input, $done);
        }

        if (!isset($done['database.backup'])) {
            return self::next('database.backup', 'write', $input);
        }

        if (!isset($done['migration.registry.reconcile'])) {
            $scope = [
                'project' => 'brvtal',
                'environment' => 'production',
                'resource' => 'database:migrations',
                'run_id' => $input['run_id'],
                'issue' => 'pl0n3r/brvtal#681',
            ];
            $gate = BackupGate::evaluate(
                'migration.registry.reconcile',
                $scope,
                $input['backup_receipt'],
                $input['now'],
            );
            if (!($gate['allowed'] ?? false)) {
                return [
                    'version' => 1,
                    'status' => 'blocked',
                    'reason' => (string) ($gate['reason'] ?? 'backup_denied'),
                    'next_intent' => null,
                    'evidence' => $gate['evidence'] ?? null,
                    'continuation' => ['resume_from' => 'database.backup'],
                    'revoke_grant' => true,
                ];
            }
            return self::next(
                'migration.registry.reconcile',
                'write',
                $input,
                ['backup_evidence' => $gate['evidence']]
            );
        }

        return self::verificationOrFinish($input, $done);
    }

    private static function verificationOrFinish(array $input, array $done): array
    {
        foreach (['migration.verify', 'health.check', 'smoke.run'] as $step) {
            if (!isset($done[$step])) {
                $extra = [];
                if ($step === 'migration.verify') {
                    $extra['expect'] = ['pending_migrations' => []];
                } elseif ($step === 'health.check') {
                    $extra['expect'] = ['schema_up_to_date' => true];
                } else {
                    $extra['expect'] = [
                        'sha' => $input['sha'],
                        'run_id' => $input['run_id'],
                    ];
                }
                return self::next($step, 'read', $input, $extra);
            }
        }

        return self::terminal('success', 'verified', $input, [
            'sha' => $input['sha'],
            'run_id' => $input['run_id'],
            'schema_up_to_date' => true,
        ]);
    }

    private static function next(
        string $operation,
        string $effect,
        array $input,
        array $extra = [],
    ): array {
        $intent = [
            'operation' => $operation,
            'effect' => $effect,
            'project' => 'brvtal',
            'environment' => 'production',
            'resource' => 'database:migrations',
            'issue' => 'pl0n3r/brvtal#681',
            'run_id' => $input['run_id'],
        ] + $extra;

        return [
            'version' => 1,
            'status' => 'planned',
            'reason' => 'next_step',
            'next_intent' => $intent,
            'evidence' => null,
            'continuation' => null,
            'revoke_grant' => false,
        ];
    }

    private static function terminal(
        string $status,
        string $reason,
        array $input,
        array $evidence,
    ): array {
        return [
            'version' => 1,
            'status' => $status,
            'reason' => $reason,
            'next_intent' => null,
            'evidence' => $evidence,
            'continuation' => $status === 'recoverable'
                ? ['resume_from' => $input['failed_step']]
                : null,
            'revoke_grant' => true,
        ];
    }

    private static function validate(array $input): void
    {
        $expected = [
            'run_id', 'sha', 'now', 'schema_state',
            'backup_receipt', 'completed_steps', 'failed_step',
        ];
        $keys = array_keys($input);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Runbook input fields invalid.');
        }
        if (!is_string($input['run_id'])
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $input['run_id']) !== 1
            || !is_string($input['sha'])
            || preg_match('/^[0-9a-f]{40}$/D', $input['sha']) !== 1
            || !is_int($input['now']) || $input['now'] < 1
            || !in_array($input['schema_state'], ['clean', 'reconcile_needed', 'ambiguous'], true)
            || !is_array($input['completed_steps']) || !array_is_list($input['completed_steps'])
            || ($input['backup_receipt'] !== null && !is_array($input['backup_receipt']))
            || ($input['failed_step'] !== null && !in_array($input['failed_step'], self::STEPS, true))) {
            throw new InvalidArgumentException('Runbook input invalid.');
        }

        $seen = [];
        foreach ($input['completed_steps'] as $step) {
            if (!is_string($step) || !in_array($step, self::STEPS, true) || isset($seen[$step])) {
                throw new InvalidArgumentException('Runbook completed_steps invalid.');
            }
            $seen[$step] = true;
        }

        $order = match ($input['schema_state']) {
            'reconcile_needed' => self::STEPS,
            'clean' => ['migration.status', 'migration.verify', 'health.check', 'smoke.run'],
            'ambiguous' => ['migration.status'],
        };
        if ($input['completed_steps'] !== array_slice($order, 0, count($input['completed_steps']))) {
            throw new InvalidArgumentException('Runbook completed_steps order invalid.');
        }

        $order = match ($input['schema_state']) {
            'clean' => ['migration.status', 'migration.verify', 'health.check', 'smoke.run'],
            'ambiguous' => ['migration.status'],
            default => self::STEPS,
        };
        if ($input['completed_steps'] !== array_slice($order, 0, count($input['completed_steps']))) {
            throw new InvalidArgumentException('Runbook completed_steps out of order.');
        }
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;

final class BackupGate
{
    private const REQUIRED_TYPE = [
        'migration.registry.reconcile' => 'database_dump',
        'database.safe_write' => 'database_dump',
        'deploy.rollback_artifact' => 'release_artifact_snapshot',
        'cron.write' => 'cron_snapshot',
        'config.write' => 'config_snapshot',
    ];

    /**
     * @param array{project:string,environment:string,resource:string,run_id:string,issue:string} $scope
     * @param list<string> $restrictions
     */
    public static function evaluate(
        string $capability,
        array $scope,
        ?array $receipt,
        int $writeAt,
        array $restrictions = [],
    ): array {
        if ($writeAt < 1) {
            throw new InvalidArgumentException('writeAt inválido.');
        }

        $policy = CapabilityPolicy::classify($capability, $restrictions);
        if (!$policy['known'] || $policy['decision'] === 'forbidden') {
            return self::deny('policy_denied');
        }

        if (!$policy['requires_backup']) {
            return [
                'allowed' => true,
                'backup_required' => false,
                'reason' => 'backup_not_required',
                'evidence' => null,
            ];
        }

        if ($receipt === null) {
            return self::deny('backup_receipt_required', true);
        }

        try {
            $backup = BackupReceipt::fromRecord($receipt);
        } catch (InvalidArgumentException) {
            return self::deny('backup_receipt_invalid', true);
        }

        if (!$backup->matches($scope)) {
            return self::deny('backup_scope_mismatch', true);
        }

        $requiredType = self::REQUIRED_TYPE[$capability] ?? null;
        if ($requiredType === null || $backup->type() !== $requiredType) {
            return self::deny('backup_type_mismatch', true);
        }

        if (!$backup->isUsableBefore($writeAt)) {
            return self::deny('backup_not_usable_for_write', true);
        }

        return [
            'allowed' => true,
            'backup_required' => true,
            'reason' => 'backup_verified',
            'evidence' => $backup->safeEvidence(),
        ];
    }

    private static function deny(string $reason, bool $required = false): array
    {
        return [
            'allowed' => false,
            'backup_required' => $required,
            'reason' => $reason,
            'evidence' => null,
        ];
    }
}

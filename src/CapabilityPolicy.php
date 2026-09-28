<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;

final class CapabilityPolicy
{
    private const RANK = [
        'automatic' => 0,
        'backup_required' => 1,
        'owner_required' => 2,
        'forbidden' => 3,
    ];

    private const CATALOG = [
        'hostinger.read' => 'automatic',
        'ssh.readonly' => 'automatic',
        'deploy.status' => 'automatic',
        'health.check' => 'automatic',
        'smoke.run' => 'automatic',
        'migration.status' => 'automatic',
        'migration.verify' => 'automatic',
        'database.backup' => 'automatic',
        'config.snapshot' => 'automatic',
        'cron.snapshot' => 'automatic',

        'migration.registry.reconcile' => 'backup_required',
        'database.safe_write' => 'backup_required',
        'deploy.rollback_artifact' => 'backup_required',
        'cron.write' => 'backup_required',
        'config.write' => 'backup_required',

        'database.restore' => 'owner_required',
        'database.destructive' => 'owner_required',
        'dns.write' => 'owner_required',
        'site.delete' => 'owner_required',
        'database.delete' => 'owner_required',
        'file.bulk_delete' => 'owner_required',

        'billing.change' => 'forbidden',
        'plan.purchase_or_renew' => 'forbidden',
        'authority.expand' => 'forbidden',
        'shell.arbitrary' => 'forbidden',
    ];

    /**
     * @param list<string> $restrictions Additional exact policy/risk restrictions.
     */
    public static function classify(string $capability, array $restrictions = []): array
    {
        self::capability($capability);
        self::restrictions($restrictions);

        if (!array_key_exists($capability, self::CATALOG)) {
            return [
                'known' => false,
                'decision' => 'forbidden',
                'requires_backup' => false,
                'requires_owner_approval' => false,
                'auto_grant_allowed' => false,
                'reason' => 'unknown_capability',
            ];
        }

        $decision = self::CATALOG[$capability];
        foreach ($restrictions as $restriction) {
            if (self::RANK[$restriction] > self::RANK[$decision]) {
                $decision = $restriction;
            }
        }

        return [
            'known' => true,
            'decision' => $decision,
            'requires_backup' => $decision === 'backup_required',
            'requires_owner_approval' => $decision === 'owner_required',
            'auto_grant_allowed' => in_array($decision, ['automatic', 'backup_required'], true),
            'reason' => $decision,
        ];
    }

    private static function restrictions(array $restrictions): void
    {
        if (!array_is_list($restrictions) || count($restrictions) > 8) {
            throw new InvalidArgumentException('Restricciones de policy inválidas.');
        }
        foreach ($restrictions as $restriction) {
            if (!is_string($restriction) || !array_key_exists($restriction, self::RANK)) {
                throw new InvalidArgumentException('Restricción de policy inválida.');
            }
        }
    }

    private static function capability(string $value): void
    {
        if (strlen($value) > 120
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Capability inválida.');
        }
    }
}

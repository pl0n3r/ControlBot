<?php
declare(strict_types=1);

namespace ControlBot\Infrastructure;

use ControlBot\Production\BackupReceipt;
use InvalidArgumentException;

final class RecoveryDrillProjection
{
    private const STATUSES = ['PASSED', 'BREACHED'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const REASONS = ['RPO_EXCEEDED', 'RTO_EXCEEDED'];

    public static function normalize(
        array $raw,
        array $profileRaw,
        array $recoveryEvidenceRaw,
        BackupReceipt $receipt,
    ): array {
        InfrastructureProvider::assertFields($raw, [
            'version', 'project_ref', 'recovery_evidence_ref', 'backup_receipt_id',
            'target', 'reported_status', 'observed', 'checks', 'reasons',
            'evidence_refs', 'observed_at', 'freshness', 'authority', 'execute',
        ], 'RecoveryDrillProjection');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('RecoveryDrillProjection version invalid.');
        }

        $profile = RecoveryProfile::normalize($profileRaw);
        $evidence = RecoveryEvidence::normalize($recoveryEvidenceRaw, $profileRaw, $receipt);
        $project = self::projectRef($raw['project_ref'] ?? null);
        if ($project !== $profile['project_ref'] || $project !== $evidence['project_ref']) {
            throw new InvalidArgumentException('RecoveryDrillProjection project mismatch.');
        }
        if (($raw['backup_receipt_id'] ?? null) !== $receipt->receiptId()) {
            throw new InvalidArgumentException('RecoveryDrillProjection receipt mismatch.');
        }
        if (($raw['authority'] ?? null) !== 'unchanged' || ($raw['execute'] ?? null) !== false) {
            throw new InvalidArgumentException('RecoveryDrillProjection authority invalid.');
        }

        $reported = InfrastructureProvider::normalizeEnum(
            $raw['reported_status'] ?? null, self::STATUSES, 'recovery.drill.reported_status'
        );
        $freshness = InfrastructureProvider::normalizeEnum(
            $raw['freshness'] ?? null, self::FRESHNESS, 'recovery.drill.freshness'
        );
        $reasons = self::reasons($raw['reasons'] ?? null, $reported);
        $observed = self::observed($raw['observed'] ?? null, $profile);
        $checks = self::checks($raw['checks'] ?? null);

        return [
            'version' => 1,
            'project_ref' => $project,
            'recovery_evidence_ref' => self::prefixedRef(
                $raw['recovery_evidence_ref'] ?? null,
                'recovery.drill.recovery_evidence_ref',
                'controlbot:recovery-evidence/'
            ),
            'backup_receipt_id' => $receipt->receiptId(),
            'target' => self::target($raw['target'] ?? null),
            'status' => $freshness === 'fresh' ? $reported : 'UNKNOWN',
            'reported_status' => $reported,
            'observed' => $observed,
            'checks' => $checks,
            'reasons' => $reasons,
            'evidence_refs' => self::refs($raw['evidence_refs'] ?? null),
            'observed_at' => InfrastructureProvider::normalizeTimestamp(
                $raw['observed_at'] ?? null, 'recovery.drill.observed_at'
            ),
            'freshness' => $freshness,
            'authority' => 'unchanged',
            'execute' => false,
        ];
    }

    private static function observed(mixed $raw, array $profile): array
    {
        InfrastructureProvider::assertFields($raw, [
            'rpo_seconds', 'rto_seconds', 'rpo_target_seconds', 'rto_target_seconds',
        ], 'RecoveryDrillObserved');
        foreach ($raw as $key => $value) {
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException("recovery.drill.observed.$key invalid.");
            }
        }
        if ($raw['rpo_target_seconds'] !== $profile['targets']['rpo_minutes'] * 60
            || $raw['rto_target_seconds'] !== $profile['targets']['rto_minutes'] * 60) {
            throw new InvalidArgumentException('RecoveryDrillProjection targets mismatch.');
        }
        return [
            'rpo_seconds' => $raw['rpo_seconds'],
            'rto_seconds' => $raw['rto_seconds'],
            'rpo_target_seconds' => $raw['rpo_target_seconds'],
            'rto_target_seconds' => $raw['rto_target_seconds'],
        ];
    }

    private static function checks(mixed $raw): array
    {
        InfrastructureProvider::assertFields($raw, ['health', 'smoke', 'integrity'], 'RecoveryDrillChecks');
        if (($raw['health'] ?? null) !== true || ($raw['smoke'] ?? null) !== true
            || ($raw['integrity'] ?? null) !== true) {
            throw new InvalidArgumentException('RecoveryDrillProjection checks invalid.');
        }
        return ['health' => true, 'smoke' => true, 'integrity' => true];
    }

    private static function reasons(mixed $raw, string $reported): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 2) {
            throw new InvalidArgumentException('RecoveryDrillProjection reasons invalid.');
        }
        $out = [];
        foreach ($raw as $reason) {
            $reason = InfrastructureProvider::normalizeEnum($reason, self::REASONS, 'recovery.drill.reason');
            $out[$reason] = true;
        }
        $reasons = array_keys($out);
        sort($reasons);
        if (($reported === 'PASSED' && $reasons !== []) || ($reported === 'BREACHED' && $reasons === [])) {
            throw new InvalidArgumentException('RecoveryDrillProjection status/reasons invalid.');
        }
        return $reasons;
    }

    private static function target(mixed $raw): array
    {
        InfrastructureProvider::assertFields($raw, ['kind', 'target_ref'], 'RecoveryDrillTarget');
        if (($raw['kind'] ?? null) !== 'disposable') {
            throw new InvalidArgumentException('RecoveryDrillProjection target must be disposable.');
        }
        return ['kind' => 'disposable', 'target_ref' => self::prefixedRef(
            $raw['target_ref'] ?? null, 'recovery.drill.target_ref', 'controlbot:recovery-target/'
        )];
    }

    private static function refs(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > 20) {
            throw new InvalidArgumentException('RecoveryDrillProjection evidence_refs invalid.');
        }
        $out = [];
        foreach ($raw as $ref) {
            $out[InfrastructureProvider::normalizeReference($ref, 'recovery.drill.evidence_ref')] = true;
        }
        $refs = array_keys($out);
        sort($refs);
        return $refs;
    }

    private static function projectRef(mixed $value): string
    {
        return self::prefixedRef($value, 'recovery.drill.project_ref', 'controlbot:project/');
    }

    private static function prefixedRef(mixed $value, string $label, string $prefix): string
    {
        $ref = InfrastructureProvider::normalizeControlRef($value, $label);
        if (!str_starts_with($ref, $prefix) || strlen($ref) <= strlen($prefix)) {
            throw new InvalidArgumentException("$label invalid.");
        }
        return $ref;
    }
}

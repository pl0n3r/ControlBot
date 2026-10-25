<?php
declare(strict_types=1);

namespace ControlBot\Security;

use ControlBot\Infrastructure\RecoveryProfile;
use InvalidArgumentException;

/** Read-only overview of declared recovery policies; never a backup-health source. */
final class RecoveryPortfolio
{
    private const PROJECTS = [
        'autofactory', 'brvtal', 'condor', 'controlbot',
        'factory', 'factoryrunner', 'grindflow',
    ];

    /** @param array<string, array<string, mixed>|null> $profiles */
    public static function project(array $profiles): array
    {
        foreach ($profiles as $key => $_value) {
            if (!is_string($key) || !in_array($key, self::PROJECTS, true)) {
                throw new InvalidArgumentException('Unknown recovery portfolio project.');
            }
        }

        $projects = [];
        foreach (self::PROJECTS as $key) {
            $raw = $profiles[$key] ?? null;
            if ($key === 'factoryrunner' && $raw !== null) {
                throw new InvalidArgumentException('FactoryRunner has no approved recovery profile.');
            }
            if ($raw !== null && (!is_array($raw) || array_is_list($raw))) {
                throw new InvalidArgumentException('Recovery portfolio profile invalid.');
            }
            $normalized = $raw === null ? null : RecoveryProfile::normalize($raw);
            if ($normalized !== null
                && $normalized['project_ref'] !== 'controlbot:project/project-' . $key) {
                throw new InvalidArgumentException('Recovery portfolio profile/project mismatch.');
            }
            $configured = $normalized !== null && RecoveryProfile::effectiveStatus($raw) === 'configured';
            $projects[] = [
                'project' => $key,
                'profile_status' => $configured ? 'configured' : 'unknown',
                'dr_status' => 'UNKNOWN',
                'reason' => $raw === null ? 'PROFILE_MISSING'
                    : ($configured ? 'RECOVERY_HEALTH_MISSING' : 'PROFILE_NOT_FRESH'),
                'rpo_target_minutes' => $configured ? $normalized['targets']['rpo_minutes'] : null,
                'rto_target_minutes' => $configured ? $normalized['targets']['rto_minutes'] : null,
            ];
        }
        return [
            'version' => 1,
            'section' => 'security/resilience',
            'execution' => false,
            'projects' => $projects,
            'actions' => [],
        ];
    }
}

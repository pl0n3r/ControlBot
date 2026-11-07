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
            // OWNER #1490/A approved declarative targets only, not Recovery Health.
            // Reject a caller-supplied FactoryRunner profile with different authority
            // or targets, even if it is otherwise syntactically valid.
            if ($key === 'factoryrunner' && $raw !== null) {
                if (!is_array($raw) || array_is_list($raw)
                    || ($raw['source_ref'] ?? null) !== 'https://github.com/pl0n3r/ControlBot/issues/1490'
                    || ($raw['targets']['rpo_minutes'] ?? null) !== 15
                    || ($raw['targets']['rto_minutes'] ?? null) !== 60
                    || ($raw['retention']['hourly'] ?? null) !== 0
                    || ($raw['retention']['daily'] ?? null) !== 7
                    || ($raw['retention']['weekly'] ?? null) !== 8
                    || ($raw['retention']['monthly'] ?? null) !== 12) {
                    throw new InvalidArgumentException('FactoryRunner recovery policy not authorized.');
                }
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

<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__.'/FactoryLiveSnapshot.php';
require_once __DIR__.'/FactoryLiveOrchestratorSnapshot.php';

use InvalidArgumentException;

final class FactoryOrchestratorSnapshotSource
{
    private const GITHUB_SECTIONS = ['releases', 'blockers', 'work'];

    public static function fromInjectedEvidence(array $evidence, int $now): array
    {
        return FactoryLiveOrchestratorSnapshot::build(
            self::canonicalFromInjectedEvidence($evidence, $now),
            $now
        );
    }

    public static function canonicalFromInjectedEvidence(array $evidence, int $now): array
    {
        if ($now < 1 || array_is_list($evidence)) {
            throw new InvalidArgumentException('Injected Factory evidence invalid.');
        }

        self::assertInjectedGitHubEvidence($evidence);

        return FactoryLiveSnapshot::build($evidence, $now);
    }

    private static function assertInjectedGitHubEvidence(array $evidence): void
    {
        foreach (self::GITHUB_SECTIONS as $section) {
            if (!array_key_exists($section, $evidence)) {
                continue;
            }

            $rows = $evidence[$section];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new InvalidArgumentException($section . ' injected evidence invalid.');
            }

            foreach ($rows as $row) {
                if (
                    !is_array($row)
                    || array_is_list($row)
                    || ($row['authority'] ?? null) !== 'github_project_snapshot'
                ) {
                    throw new InvalidArgumentException($section . ' must use canonical GitHub evidence.');
                }
            }
        }
    }
}

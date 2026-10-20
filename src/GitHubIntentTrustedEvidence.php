<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

require_once __DIR__ . '/GitHubProjectView.php';

/**
 * Pure, fail-closed projection from an already authenticated canonical snapshot.
 *
 * The application MUST obtain $canonicalSnapshot from its trusted snapshot
 * store and $trustedNow from its server clock, never from an HTTP/intention
 * payload. Structural validation cannot authenticate a caller-forged snapshot.
 */
final class GitHubIntentTrustedEvidence
{
    private const MAX_AGE_SECONDS = 300;

    public static function derive(array $canonicalSnapshot, string $repositoryRef, int $trustedNow): array
    {
        try {
            if ($trustedNow < 1 || preg_match(
                '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',
                $repositoryRef
            ) !== 1) {
                return self::unknown();
            }

            // A fixed policy TTL, never caller supplied. GitHubProjectView
            // checks every field, timestamp, source URL and ambiguity.
            $project = GitHubProjectView::project(
                $canonicalSnapshot,
                $trustedNow,
                self::MAX_AGE_SECONDS
            );
            if ($project['state'] !== 'current' || $project['freshness'] !== 'current') {
                return self::unknown();
            }

            $selected = null;
            foreach ($project['repositories'] as $repository) {
                if ($repository['repository'] !== $repositoryRef) {
                    continue;
                }
                if ($selected !== null) {
                    return self::unknown();
                }
                $selected = $repository;
            }
            if (
                $selected === null ||
                $selected['state'] !== 'current' ||
                $selected['freshness'] !== 'current' ||
                $selected['observed_at'] !== $project['observed_at']
            ) {
                return self::unknown();
            }

            return [
                'version' => 1,
                'state' => 'current',
                'repository_ref' => $selected['repository'],
                'source_ref' => $selected['source_ref'],
                // Derived exclusively from the source and main SHA observed
                // in the canonical snapshot, not from intent evidence_refs.
                'evidence_ref' => $selected['source_ref'] . '/commit/' . $selected['main_sha'],
                'observed_at' => $selected['observed_at'],
                'freshness' => 'current',
                'age_seconds' => $trustedNow - $selected['observed_at'],
                'execution' => false,
            ];
        } catch (\Throwable) {
            return self::unknown();
        }
    }

    private static function unknown(): array
    {
        // Do not echo caller-controlled refs, sensitive evidence or payloads.
        return [
            'version' => 1,
            'state' => 'unknown',
            'reason' => 'untrusted_or_stale_snapshot',
            'execution' => false,
        ];
    }
}

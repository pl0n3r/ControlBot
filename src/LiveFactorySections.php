<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__.'/FactoryLiveSnapshot.php';

use InvalidArgumentException;

/** Offline projection of the eight sections of Factory #561; never accesses a network. */
final class LiveFactorySections
{
    private const SOURCES = [
        'batches' => ['factory', 'factory_plan'],
        'owner_decisions' => ['github', 'owner_inbox'],
        'releases' => ['github', 'github_project_snapshot'],
        'blockers' => ['github', 'github_project_snapshot'],
        'production' => ['observability', 'observability_project_status'],
        'quality' => ['sonar', 'quality_health'],
        'work' => ['github', 'github_project_snapshot'],
        'learning' => ['learning', 'incident_lesson'],
    ];
    private const MAX_AGE = 300;
    private const FIELDS = ['id', 'state', 'source_ref', 'observed_at', 'data'];

    /** Fixture format: source => section => list of signals, no credentials. */
    public static function build(array $fixtures, int $now): array
    {
        if ($now < 1 || ($fixtures !== [] && array_is_list($fixtures))) {
            throw new InvalidArgumentException('Factory fixture invalid.');
        }
        $allowedSources = array_unique(array_column(self::SOURCES, 0));
        foreach ($fixtures as $source => $groups) {
            if (!is_string($source) || !in_array($source, $allowedSources, true)
                || !is_array($groups) || ($groups !== [] && array_is_list($groups))) {
                throw new InvalidArgumentException('Factory fixture provider invalid.');
            }
            foreach ($groups as $section => $signals) {
                if (!isset(self::SOURCES[$section]) || self::SOURCES[$section][0] !== $source) {
                    throw new InvalidArgumentException('Factory section/provider mismatch.');
                }
                if (!is_array($signals) || !array_is_list($signals) || count($signals) > 50) {
                    throw new InvalidArgumentException('Factory fixture signals invalid.');
                }
            }
        }
        $raw = [];
        foreach (self::SOURCES as $section => [$source, $authority]) {
            $items = $fixtures[$source][$section] ?? [];
            $signals = [];
            foreach ($items as $item) {
                if (!is_array($item) || array_is_list($item)) {
                    throw new InvalidArgumentException('Factory signal invalid.');
                }
                $keys = array_keys($item);
                sort($keys, SORT_STRING);
                $required = self::FIELDS;
                sort($required, SORT_STRING);
                if ($keys !== $required) {
                    throw new InvalidArgumentException('Factory signal fields invalid.');
                }
                $ref = $item['source_ref'];
                if (!is_string($ref) || !str_starts_with($ref, $source.':')) {
                    throw new InvalidArgumentException('Factory signal source invalid.');
                }
                $at = $item['observed_at'];
                if (!is_int($at) || $at < 1 || $at > $now) {
                    throw new InvalidArgumentException('Factory signal timestamp invalid.');
                }
                $fresh = ($now - $at) > self::MAX_AGE ? 'stale' : 'current';
                $state = $item['state'];
                if ($fresh === 'stale' && $state === 'healthy') {
                    $state = 'unknown';
                }
                $signals[] = [
                    'id' => $item['id'],
                    'authority' => $authority,
                    'state' => $state,
                    'source_ref' => $ref,
                    'observed_at' => $at,
                    'freshness' => $fresh,
                    'data' => $item['data'],
                ];
            }
            $raw[$section] = $signals;
        }
        // Reuse canonical limits, issue-link, PII and secrets validation.
        $canonical = FactoryLiveSnapshot::build($raw, $now);
        $ordered = ['owner_decisions' => $canonical['sections']['owner_decisions']];
        foreach ($canonical['sections'] as $section => $signals) {
            if ($section !== 'owner_decisions') {
                $ordered[$section] = $signals;
            }
        }
        return [
            'version' => 1,
            'observed_at' => $now,
            'sections' => $ordered,
            'canonical_fingerprint' => $canonical['fingerprint'],
        ];
    }
}

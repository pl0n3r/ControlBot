<?php
declare(strict_types=1);

namespace ControlBot\Observability;

use InvalidArgumentException;

final class ObservabilityProjectStatus
{
    private const SOURCES = ['health', 'ci', 'deploy', 'agent'];

    public static function build(array $projectIds, array $rawEvents, int $now, array $ttlBySource): array
    {
        $catalog = self::catalog($projectIds);
        self::ttl($ttlBySource);
        if (!array_is_list($rawEvents) || count($rawEvents) > 1000) {
            throw new InvalidArgumentException('Events invalid.');
        }

        $latest = [];
        foreach ($rawEvents as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Event invalid.');
            }
            $event = ObservabilityEvent::normalize($raw, $now, $ttlBySource);
            $projectId = $event['project_id'];
            if (!isset($catalog[$projectId])) {
                throw new InvalidArgumentException('Event project outside catalog.');
            }
            $key = $projectId . '|' . $event['source'];
            $current = $latest[$key] ?? null;
            if ($current === null || $event['occurred_at'] > $current['occurred_at']) {
                $latest[$key] = $event;
                continue;
            }
            if ($event['occurred_at'] === $current['occurred_at']
                && $event['fingerprint'] !== $current['fingerprint']) {
                throw new InvalidArgumentException('Ambiguous latest event.');
            }
        }

        $projects = [];
        foreach (array_keys($catalog) as $projectId) {
            $sources = [];
            foreach (self::SOURCES as $source) {
                $event = $latest[$projectId . '|' . $source] ?? null;
                $sources[$source] = $event === null ? self::unknown($source) : self::slot($event);
            }
            $projects[] = ['project_id' => $projectId, 'sources' => $sources];
        }
        $canonical = ['version' => 1, 'projects' => $projects];
        return $canonical + [
            'fingerprint' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private static function catalog(array $projectIds): array
    {
        if (!array_is_list($projectIds) || $projectIds === [] || count($projectIds) > 100) {
            throw new InvalidArgumentException('Project catalog invalid.');
        }
        $out = [];
        foreach ($projectIds as $projectId) {
            if (!is_string($projectId) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $projectId) !== 1) {
                throw new InvalidArgumentException('Project id invalid.');
            }
            if (isset($out[$projectId])) {
                throw new InvalidArgumentException('Project id duplicated.');
            }
            $out[$projectId] = true;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private static function ttl(array $ttlBySource): void
    {
        if (array_is_list($ttlBySource) && $ttlBySource !== []) {
            throw new InvalidArgumentException('TTL map invalid.');
        }
        foreach ($ttlBySource as $source => $ttl) {
            if (!is_string($source) || !in_array($source, self::SOURCES, true)
                || !is_int($ttl) || $ttl < 1 || $ttl > 86400) {
                throw new InvalidArgumentException('TTL map invalid.');
            }
        }
    }

    private static function slot(array $event): array
    {
        return [
            'source' => $event['source'],
            'freshness' => $event['freshness'],
            'severity' => $event['severity'],
            'observed_at' => $event['occurred_at'],
            'evidence_ref' => 'controlbot:observability-event/'
                . substr(hash('sha256', $event['fingerprint']), 0, 32),
        ];
    }

    private static function unknown(string $source): array
    {
        return [
            'source' => $source,
            'freshness' => 'unknown',
            'severity' => 'unknown',
            'observed_at' => null,
            'evidence_ref' => null,
        ];
    }
}

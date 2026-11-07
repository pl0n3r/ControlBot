<?php
declare(strict_types=1);

use ControlBot\Business\IncidentTimeline;
use ControlBot\Observability\ObservabilityProjectStatus;

require_once __DIR__ . '/../../ObservabilityEvent.php';
require_once __DIR__ . '/../../ObservabilityProjectStatus.php';
require_once __DIR__ . '/../../IncidentTimeline.php';

/**
 * Owner-authenticated, read-only projections. No notifications or external
 * side-effects; the backend is injected by the trusted server bootstrap.
 */
$source = static function (callable $backend, string $operation, array $params, int $now): array {
    $result = $backend($operation, $params, [], $now);
    if (!is_array($result) || array_is_list($result)) {
        throw new RuntimeException('Trusted observability source unavailable.');
    }
    return $result;
};

$fields = static function (array $result, array $expected): void {
    $actual = array_keys($result);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($actual !== $expected) {
        throw new RuntimeException('Trusted observability schema mismatch.');
    }
};

return [
    [
        'method' => 'GET',
        'path' => '/api/v1/observabilidad',
        'handler' => static function (callable $backend, array $params, array $body, int $now)
            use ($source, $fields): array {
            $data = $source($backend, 'observability.snapshot', [], $now);
            $fields($data, ['project_ids', 'events', 'ttl_by_source']);
            if (!is_array($data['project_ids']) || !is_array($data['events'])
                || !is_array($data['ttl_by_source'])) {
                throw new RuntimeException('Trusted observability source invalid.');
            }
            try {
                // The domain builder validates scope, provenance and freshness
                // and explicitly marks every missing signal as unknown.
                $projection = ObservabilityProjectStatus::build(
                    $data['project_ids'], $data['events'], $now, $data['ttl_by_source'],
                );
            } catch (InvalidArgumentException $error) {
                throw new RuntimeException('Unverifiable observability input.', 0, $error);
            }
            $projects = [];
            foreach ($projection['projects'] as $project) {
                $sources = $project['sources'];
                $state = 'HEALTHY';
                $rank = 0;
                foreach ($sources as $slot) {
                    if ($slot['freshness'] !== 'fresh'
                        || $slot['severity'] === 'unknown') {
                        $state = 'UNKNOWN';
                        break;
                    }
                    $rank = max($rank, match ($slot['severity']) {
                        'info' => 0, 'warning' => 1, 'error' => 2, 'critical' => 3,
                        default => throw new RuntimeException('Untrusted severity.'),
                    });
                }
                if ($state !== 'UNKNOWN') {
                    $state = match ($rank) {
                        0 => 'HEALTHY', 1, 2 => 'DEGRADED', 3 => 'DOWN',
                    };
                }
                // One stable visual indicator per project, never an alert
                // dispatch: no email, no push, no incident creation.
                $projects[] = [
                    'project_id' => $project['project_id'],
                    'status' => $state,
                    'sources' => $sources,
                    'alert' => [
                        'indicator' => $state === 'HEALTHY' ? 'NONE' : 'REVIEW',
                        'dedupe_key' => hash('sha256', $project['project_id'] . '|' . $state),
                        'sent' => false,
                    ],
                ];
            }
            return [
                'version' => 1,
                'projects' => $projects,
                'source_fingerprint' => $projection['fingerprint'],
                'read_only' => true,
                'notifications_sent' => 0,
            ];
        },
    ],
    [
        'method' => 'GET',
        'path' => '/api/v1/incidentes/{incident_id}',
        'handler' => static function (callable $backend, array $params, array $body, int $now)
            use ($source, $fields): array {
            $slug = $params['incident_id'] ?? null;
            if (!is_string($slug)
                || preg_match('/^incident-([a-f0-9]{32})$/D', $slug, $matches) !== 1) {
                throw new InvalidArgumentException('Invalid incident identifier.');
            }
            $incidentRef = 'incident:' . $matches[1];
            $data = $source($backend, 'incidents.read', ['incident_ref' => $incidentRef], $now);
            $fields($data, ['timeline']);
            if ($data['timeline'] === null) {
                return [
                    'version' => 1, 'incident_ref' => $incidentRef,
                    'status' => 'UNKNOWN', 'timeline' => [],
                    'duration_seconds' => null, 'read_only' => true,
                    'notifications_sent' => 0,
                ];
            }
            if (!is_array($data['timeline']) || array_is_list($data['timeline'])) {
                throw new RuntimeException('Invalid trusted incident timeline.');
            }
            try {
                $timeline = IncidentTimeline::build($data['timeline']);
            } catch (InvalidArgumentException $error) {
                throw new RuntimeException('Invalid trusted incident timeline.', 0, $error);
            }
            if (!hash_equals($incidentRef, $timeline['incident_ref'])) {
                throw new RuntimeException('Incident identity mismatch.');
            }
            return [
                'version' => 1,
                'incident_ref' => $incidentRef,
                'status' => $timeline['recovered_at'] === null ? 'OPEN' : 'RECOVERED',
                'timeline' => $timeline['events'],
                'duration_seconds' => $timeline['duration_seconds'],
                'read_only' => true,
                'notifications_sent' => 0,
            ];
        },
    ],
];

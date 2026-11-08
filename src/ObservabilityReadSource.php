<?php
declare(strict_types=1);

namespace ControlBot\Observability;

use RuntimeException;
use Throwable;

/**
 * Read-only owner projection from a private, server-configured snapshot.
 * No browser input, network fetch or unverified status creates authority.
 */
final class ObservabilityReadSource
{
    private const MAX_BYTES = 262144;
    private const MAX_AGE = 300;

    public static function readConfigured(
        array $environment, string $operation, array $params, int $now,
    ): array {
        if ($operation !== 'observability.snapshot' && $operation !== 'incidents.read') {
            throw new RuntimeException('Unsupported read operation.');
        }
        if ($operation === 'observability.snapshot' && $params !== []) {
            throw new RuntimeException('Unexpected read parameters.');
        }
        if ($operation === 'incidents.read'
            && (array_keys($params) !== ['incident_ref']
                || !is_string($params['incident_ref'])
                || preg_match('/^incident:[a-f0-9]{32}$/D', $params['incident_ref']) !== 1)) {
            throw new RuntimeException('Invalid incident reference.');
        }

        $path = $environment['CONTROLBOT_OBSERVABILITY_SNAPSHOT_PATH'] ?? null;
        if (!is_string($path) || $path === '' || strlen($path) > 2048
            || $path[0] !== '/' || str_contains($path, "\0")
            || str_contains($path, '..') || str_contains($path, '//')) {
            throw new RuntimeException('Observability snapshot not configured.');
        }
        $real = realpath($path);
        if ($real === false || $real !== $path || is_link($path)
            || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Observability snapshot unavailable.');
        }
        $public = $environment['CONTROLBOT_DOCUMENT_ROOT'] ?? ($_SERVER['DOCUMENT_ROOT'] ?? null);
        if (is_string($public) && $public !== '') {
            $root = realpath($public);
            if ($root !== false && ($real === $root || str_starts_with($real, rtrim($root, '/') . '/'))) {
                throw new RuntimeException('Observability snapshot is public.');
            }
        }
        $before = @stat($real);
        if (!is_array($before) || ($before['mode'] & 0077) !== 0
            || $before['size'] < 2 || $before['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Observability snapshot permissions invalid.');
        }
        $stream = @fopen($real, 'rb');
        if (!is_resource($stream)) throw new RuntimeException('Observability snapshot unreadable.');
        try {
            $during = fstat($stream);
            if (!is_array($during) || $during['ino'] !== $before['ino']
                || $during['dev'] !== $before['dev'] || ($during['mode'] & 0077) !== 0) {
                throw new RuntimeException('Observability snapshot changed during read.');
            }
            $raw = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > self::MAX_BYTES) {
            throw new RuntimeException('Observability snapshot size invalid.');
        }
        try {
            $data = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('Observability snapshot JSON invalid.', 0, $error);
        }
        if (!is_array($data) || array_is_list($data)
            || !self::keys($data, ['version','observed_at','project_ids','events','ttl_by_source','incidents'])
            || $data['version'] !== 1 || !is_int($data['observed_at'])
            || $now < 1 || $data['observed_at'] > $now
            || $now - $data['observed_at'] > self::MAX_AGE
            || !is_array($data['project_ids']) || !array_is_list($data['project_ids'])
            || $data['project_ids'] === [] || count($data['project_ids']) > 100
            || !is_array($data['events']) || !array_is_list($data['events'])
            || count($data['events']) > 1000
            || !is_array($data['ttl_by_source'])
            || !is_array($data['incidents'])
            || ($data['incidents'] !== [] && array_is_list($data['incidents']))) {
            throw new RuntimeException('Observability snapshot schema/freshness invalid.');
        }
        if (count($data['incidents']) > 100) {
            throw new RuntimeException('Incident catalog too large.');
        }
        foreach ($data['incidents'] as $ref => $timeline) {
            if (!is_string($ref) || preg_match('/^incident:[a-f0-9]{32}$/D', $ref) !== 1
                || ($timeline !== null && (!is_array($timeline) || array_is_list($timeline)))) {
                throw new RuntimeException('Incident catalog invalid.');
            }
        }
        if ($operation === 'incidents.read') {
            return ['timeline' => $data['incidents'][$params['incident_ref']] ?? null];
        }
        return [
            'project_ids' => $data['project_ids'],
            'events' => $data['events'],
            'ttl_by_source' => $data['ttl_by_source'],
        ];
    }

    private static function keys(array $data, array $fields): bool
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        sort($fields, SORT_STRING);
        return $keys === $fields;
    }
}

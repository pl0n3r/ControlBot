<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

/** HTML-only, owner-private projection; never labels HTTP 200 as HEALTH GREEN. */
final class FactoryPublicHealthUi
{
    private const REPOS = ['pl0n3r/Condor', 'pl0n3r/brvtal',
        'pl0n3r/GrindFlow', 'pl0n3r/ControlBot'];
    private const STATES = ['reachable', 'unreachable', 'unknown', 'stale'];
    private const REASONS = ['http_success', 'http_non_success', 'probe_failed',
        'no_public_endpoint', 'endpoint_not_allowlisted', 'endpoint_unsafe',
        'evidence_stale'];

    public static function renderSection(?array $rows): string
    {
        if ($rows === null) {
            $rows = array_map(static fn(string $ref): array => [
                'repository_ref' => $ref, 'endpoint' => 'UNKNOWN',
                'status' => 'unknown', 'http_status' => null,
                'latency_ms' => null, 'observed_at' => null,
                'source_age_seconds' => null, 'reason' => 'no_public_endpoint',
            ], self::REPOS);
        }
        if (!array_is_list($rows) || count($rows) !== 4) {
            throw new InvalidArgumentException('public health panel cardinality invalid');
        }
        $html = '';
        foreach ($rows as $index => $row) {
            if (!is_array($row) || count($row) !== 8
                || array_diff(array_keys($row), [
                    'repository_ref','endpoint','status','http_status',
                    'latency_ms','observed_at','source_age_seconds','reason'
                ]) !== []
                || ($row['repository_ref'] ?? null) !== self::REPOS[$index]
                || !in_array($row['status'] ?? null, self::STATES, true)
                || !in_array($row['reason'] ?? null, self::REASONS, true)
                || !is_string($row['endpoint'])
                || ($row['endpoint'] !== 'UNKNOWN'
                    && (strlen($row['endpoint']) > 240
                    || !str_starts_with($row['endpoint'], 'https://')))
                || ($row['http_status'] !== null
                    && (!is_int($row['http_status'])
                    || $row['http_status'] < 100 || $row['http_status'] > 599))
                || ($row['latency_ms'] !== null
                    && (!is_int($row['latency_ms']) || $row['latency_ms'] < 0
                    || $row['latency_ms'] > 2000))
                || ($row['observed_at'] !== null
                    && (!is_int($row['observed_at']) || $row['observed_at'] < 1))
                || ($row['source_age_seconds'] !== null
                    && (!is_int($row['source_age_seconds']) || $row['source_age_seconds'] < 0))
            ) {
                throw new InvalidArgumentException('public health panel evidence invalid');
            }
            if ($row['status'] === 'reachable'
                && ($row['http_status'] === null || $row['http_status'] < 200
                    || $row['http_status'] >= 300)) {
                throw new InvalidArgumentException('public health reachability mismatch');
            }
            if ($row['status'] === 'unknown'
                && ($row['http_status'] !== null || $row['latency_ms'] !== null
                    || $row['endpoint'] === 'UNKNOWN' && $row['observed_at'] !== null)) {
                throw new InvalidArgumentException('public health unknown inconsistency');
            }
            // UI must not turn contradictory or incomplete evidence into
            // a credible health observation, even when handed an external snapshot.
            if ($row['status'] === 'reachable'
                && ($row['reason'] !== 'http_success'
                    || $row['observed_at'] === null
                    || $row['source_age_seconds'] === null
                    || $row['latency_ms'] === null)) {
                throw new InvalidArgumentException('public health success evidence mismatch');
            }
            if ($row['status'] === 'unreachable'
                && ($row['reason'] !== 'http_non_success'
                    || $row['http_status'] === null
                    || ($row['http_status'] >= 200 && $row['http_status'] < 300)
                    || $row['observed_at'] === null
                    || $row['source_age_seconds'] === null
                    || $row['latency_ms'] === null)) {
                throw new InvalidArgumentException('public health failure evidence mismatch');
            }
            if ($row['status'] === 'stale'
                && ($row['reason'] !== 'evidence_stale'
                    || $row['observed_at'] === null
                    || $row['source_age_seconds'] === null
                    || $row['source_age_seconds'] <= 300
                    || $row['http_status'] === null)) {
                throw new InvalidArgumentException('public health stale evidence mismatch');
            }
            if ($row['status'] === 'unknown'
                && ($row['endpoint'] !== 'UNKNOWN'
                    || $row['source_age_seconds'] !== null
                    || !in_array($row['reason'], [
                        'no_public_endpoint', 'endpoint_not_allowlisted',
                        'endpoint_unsafe', 'probe_failed',
                    ], true))) {
                throw new InvalidArgumentException('public health unknown evidence mismatch');
            }
            $code = $row['http_status'] === null ? 'UNKNOWN' : (string) $row['http_status'];
            $latency = $row['latency_ms'] === null ? 'UNKNOWN' : $row['latency_ms'] . ' ms';
            $when = $row['observed_at'] === null ? 'UNKNOWN'
                : gmdate('Y-m-d H:i:s', $row['observed_at']) . ' UTC';
            $age = $row['source_age_seconds'] === null ? 'UNKNOWN'
                : $row['source_age_seconds'] . ' s';
            $html .= '<article class="front public-health" data-read-only="true"'
                . ' data-health="' . self::e($row['status']) . '">'
                . '<h3>' . self::e($row['repository_ref']) . '</h3>'
                . '<p>Disponibilidad HTTP: <strong>' . self::e($row['status']) . '</strong></p>'
                . '<p>Código: ' . self::e($code) . ' · Latencia: ' . self::e($latency) . '</p>'
                . '<p>Observado: ' . self::e($when) . ' · Antigüedad: ' . self::e($age) . '</p>'
                . '<small>Motivo: ' . self::e($row['reason'])
                . ' · Endpoint: ' . self::e($row['endpoint']) . '</small></article>';
        }
        return '<section class="panel" data-section="public_health" aria-label="Salud pública observada">'
            . '<p class="eyebrow">OBSERVACIÓN PÚBLICA</p><h2>Salud de producción</h2>'
            . '<p>Solo lectura: HTTP alcanzable NO significa producción VERDE. '
            . 'SHA, esquema y smoke permanecen UNKNOWN sin evidencia independiente.</p>'
            . '<div class="front-grid">' . $html . '</div></section>';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

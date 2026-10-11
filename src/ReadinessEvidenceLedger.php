<?php
declare(strict_types=1);

namespace ControlBot\Readiness;

use InvalidArgumentException;

/** Offline evidence inventory. No network, storage, authorization, or go-live side effects. */
final class ReadinessEvidenceLedger
{
    private const AREAS = ['deploy', 'health', 'smoke', 'recovery', 'security', 'rollback'];
    private const OUTCOMES = ['pass', 'fail', 'unknown'];
    private const KEYS = ['area', 'source_ref', 'revision', 'observed_at', 'valid_for_seconds', 'outcome'];

    public static function project(array $items, int $now): array
    {
        if ($now < 1 || !array_is_list($items) || $items === [] || count($items) > 64) {
            throw new InvalidArgumentException('Evidence inventory invalid.');
        }
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item) || array_keys($item) !== self::KEYS) {
                throw new InvalidArgumentException('Evidence shape invalid.');
            }
            $area = $item['area'];
            if (!is_string($area) || !in_array($area, self::AREAS, true) || isset($rows[$area])) {
                throw new InvalidArgumentException('Evidence area invalid or duplicated.');
            }
            $rows[$area] = true;
            $source = $item['source_ref'];
            $revision = $item['revision'];
            $observed = $item['observed_at'];
            $validFor = $item['valid_for_seconds'];
            $outcome = $item['outcome'];
            if ($source === null && $revision === null && $observed === null
                && $validFor === null && $outcome === null) {
                $rows[$area] = self::row($area, 'UNKNOWN', 'unknown', 'evidence_missing', null, null, null);
                continue;
            }
            if (!is_string($source) || preg_match('~^https://github\.com/pl0n3r/[A-Za-z0-9_.-]+/(?:actions/runs|issues|pull)/[1-9][0-9]*$~D', $source) !== 1
                || !is_string($revision) || preg_match('/^(?:[a-f0-9]{40}|v[0-9]+(?:\.[0-9]+){1,2})$/D', $revision) !== 1
                || !is_int($observed) || $observed < 1 || $observed > $now
                || !is_int($validFor) || $validFor < 1 || $validFor > 86400
                || !is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
                throw new InvalidArgumentException('Evidence provenance invalid.');
            }
            if ($now - $observed > $validFor) {
                $rows[$area] = self::row($area, 'UNKNOWN', 'stale', 'expired', $source, $revision, $observed);
                continue;
            }
            $result = match ($outcome) {
                'pass' => 'PASS', 'fail' => 'FAIL', default => 'UNKNOWN',
            };
            $reason = match ($outcome) {
                'pass' => 'verified', 'fail' => 'check_failed', default => 'inconclusive',
            };
            $rows[$area] = self::row($area, $result, 'current', $reason, $source, $revision, $observed);
        }
        ksort($rows, SORT_STRING);
        $hasUnknown = false;
        foreach ($rows as $row) {
            if ($row['result'] === 'UNKNOWN') {
                $hasUnknown = true;
                break;
            }
        }
        return [
            'version' => 1, 'observed_at' => $now, 'signals' => array_values($rows),
            'has_unknown_or_stale' => $hasUnknown,
            'authorizes_live' => false,
        ];
    }

    private static function row(string $area, string $result, string $freshness, string $reason, ?string $source, ?string $revision, ?int $observed): array
    {
        return [
            'area' => $area, 'result' => $result, 'freshness' => $freshness,
            'reason' => $reason, 'source_ref' => $source,
            'revision' => $revision, 'observed_at' => $observed,
        ];
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FactoryOrchestratorLiveCache
{
    private const MAX_BYTES = 2000000;

    public static function remember(string $path, int $now, int $ttl, int $stale, int $budget, callable $refresh): array
    {
        if ($path === '' || $path[0] !== '/' || $ttl < 1 || $stale < $ttl || $budget < 1 || $stale > 86400) {
            throw new InvalidArgumentException('cache policy invalid');
        }
        // A damaged derived cache must never prevent a canonical refresh.
        try {
            $cached = self::read($path);
            if ($cached !== null && $now < $cached['cached_at']) {
                $cached = null;
            }
        } catch (Throwable) {
            $cached = null;
        }
        if ($cached !== null) {
            $age = $now - $cached['cached_at'];
            if ($age <= $ttl) return self::result('fresh', $cached, $age);
            if ($age <= $stale && $now < $cached['next_refresh_at']) return self::result('stale', $cached, $age);
        }
        try {
            $payload = self::payload($refresh());
            $record = ['cached_at' => $now, 'next_refresh_at' => $now + $budget, 'payload' => $payload];
            self::write($path, $record);
            return self::result('refreshed', $record, 0);
        } catch (Throwable $error) {
            if ($cached !== null) {
                $age = $now - $cached['cached_at'];
                if ($age >= 0 && $age <= $stale) {
                    $cached['next_refresh_at'] = $now + $budget;
                    self::write($path, $cached);
                    return self::result('stale', $cached, $age);
                }
            }
            throw new RuntimeException('snapshot unavailable', 0, $error);
        }
    }

    private static function read(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        if (!is_string($raw) || strlen($raw) < 2 || strlen($raw) > self::MAX_BYTES) throw new RuntimeException('cache invalid');
        $row = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($row) || !is_int($row['cached_at'] ?? null) || !is_int($row['next_refresh_at'] ?? null)) {
            throw new RuntimeException('cache invalid');
        }
        $row['payload'] = self::payload($row['payload'] ?? null);
        return $row;
    }

    private static function payload(mixed $payload): array
    {
        if (!is_array($payload) || array_is_list($payload)
            || ($payload['version'] ?? null) !== 1 || ($payload['read_only'] ?? null) !== true
            || !is_string($payload['fingerprint'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $payload['fingerprint']) !== 1) {
            throw new InvalidArgumentException('payload invalid');
        }
        self::projection($payload);
        $body = $payload;
        unset($body['fingerprint']);
        $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (!hash_equals(hash('sha256', $encoded), $payload['fingerprint'])) {
            throw new InvalidArgumentException('payload invalid');
        }
        return $payload;
    }


    /** Reject a self-consistent digest when the cached projection is not a valid v1 view. */
    private static function projection(array $view): void
    {
        $keys = ['version','observed_at','source_snapshot','read_only',
            'central','fronts','owner_decisions','fingerprint'];
        if (array_key_exists('agent_activity', $view)) $keys[] = 'agent_activity';
        self::fields($view, $keys);
        if (!is_int($view['observed_at']) || $view['observed_at'] < 1
            || !self::digest($view['source_snapshot'])
            || !is_array($view['fronts']) || !array_is_list($view['fronts'])
            || !is_array($view['owner_decisions']) || !array_is_list($view['owner_decisions'])
            || count($view['fronts']) > 24 || count($view['owner_decisions']) > 100
            || (array_key_exists('agent_activity', $view)
                && $view['agent_activity'] !== null && !is_array($view['agent_activity']))) {
            throw new InvalidArgumentException('projection invalid');
        }

        $c = $view['central'];
        self::fields($c, ['activity_state','available','reserved','blocked',
            'source_ref','observed_at','freshness','age_seconds']);
        $freshness = $c['freshness'];
        if (!in_array($freshness, ['current','stale','unknown'], true)) {
            throw new InvalidArgumentException('central freshness invalid');
        }
        if ($freshness === 'unknown') {
            if ($c !== [
                'activity_state'=>'UNKNOWN','available'=>null,'reserved'=>null,
                'blocked'=>null,'source_ref'=>null,'observed_at'=>null,
                'freshness'=>'unknown','age_seconds'=>null,
            ]) {
                throw new InvalidArgumentException('central unknown invalid');
            }
        } else {
            foreach (['available','reserved','blocked'] as $count) {
                if (!is_int($c[$count]) || $c[$count] < 0) {
                    throw new InvalidArgumentException('central count invalid');
                }
            }
            if (!is_string($c['source_ref']) || $c['source_ref'] === ''
                || !is_int($c['observed_at']) || $c['observed_at'] < 1
                || $c['observed_at'] > $view['observed_at']
                || $c['age_seconds'] !== $view['observed_at'] - $c['observed_at']) {
                throw new InvalidArgumentException('central timestamps invalid');
            }
            $state = $freshness === 'stale' ? 'STALE'
                : ($c['reserved'] > 0 ? 'ACTIVE' : ($c['available'] > 0
                    ? 'READY' : ($c['blocked'] > 0 ? 'BLOCKED' : 'IDLE')));
            if ($c['activity_state'] !== $state) {
                throw new InvalidArgumentException('central status inconsistent');
            }
        }

        $repos = ['pl0n3r/Factory','pl0n3r/Condor','pl0n3r/GrindFlow',
            'pl0n3r/brvtal','pl0n3r/ControlBot','pl0n3r/AutoFactory','pl0n3r/FactoryRunner'];
        foreach ($view['fronts'] as $front) {
            self::fields($front, ['id','repository_ref','issue_ref','status',
                'progress_percent','progress_state','source_ref','observed_at','freshness','age_seconds']);
            if (!self::text($front['id']) || !self::text($front['issue_ref'])
                || !self::text($front['source_ref'])
                || !in_array($front['repository_ref'], $repos, true)
                || !in_array($front['freshness'], ['current','stale'], true)
                || !in_array($front['status'],
                    ['available','reserved','in_review','blocked','merged','unknown','STALE'], true)
                || ($front['freshness'] === 'stale') !== ($front['status'] === 'STALE')
                || !is_int($front['observed_at']) || $front['observed_at'] < 1
                || $front['observed_at'] > $view['observed_at']
                || $front['age_seconds'] !== $view['observed_at'] - $front['observed_at']
                || !in_array($front['progress_state'], ['UNKNOWN','EVIDENCED'], true)
                || ($front['progress_state'] === 'UNKNOWN') !== ($front['progress_percent'] === null)
                || ($front['progress_percent'] !== null
                    && (!is_int($front['progress_percent']) || $front['progress_percent'] < 0
                        || $front['progress_percent'] > 100))) {
                throw new InvalidArgumentException('front invalid');
            }
        }
        foreach ($view['owner_decisions'] as $d) {
            self::fields($d, ['id','issue_ref','repository_ref','issue_number',
                'format','title','title_simple','summary_simple','explain_simple',
                'why_recommended','blocks','options','recommendation','safe_default',
                'expires_at','seconds_left','expired','source_ref','observed_at',
                'freshness','age_seconds']);
            if (!self::text($d['id']) || !self::text($d['issue_ref'])
                || !self::text($d['source_ref']) || !self::text($d['title'])
                || !in_array($d['repository_ref'], $repos, true)
                || !is_int($d['issue_number']) || $d['issue_number'] < 1
                || !in_array($d['format'], ['legacy','structured'], true)
                || !in_array($d['freshness'], ['current','stale'], true)
                || !is_int($d['observed_at']) || $d['observed_at'] < 1
                || $d['observed_at'] > $view['observed_at']
                || $d['age_seconds'] !== $view['observed_at'] - $d['observed_at']
                || !is_array($d['options']) || !array_is_list($d['options'])) {
                throw new InvalidArgumentException('decision invalid');
            }
            if ($d['format'] === 'legacy') {
                foreach (['title_simple','summary_simple','explain_simple','why_recommended',
                    'blocks','recommendation','safe_default','expires_at','seconds_left','expired'] as $key) {
                    if ($d[$key] !== null) throw new InvalidArgumentException('legacy decision invalid');
                }
                if ($d['options'] !== []) throw new InvalidArgumentException('legacy options invalid');
            } else {
                foreach (['title_simple','summary_simple','why_recommended','blocks'] as $key) {
                    if (!self::text($d[$key])) throw new InvalidArgumentException('decision text invalid');
                }
                $ids = [];
                foreach ($d['options'] as $option) {
                    self::fields($option, ['id','label','effect','pros','cons','risk',
                        'cost','reversible','explain_simple']);
                    if (!is_string($option['id']) || preg_match('/^[A-D]$/D', $option['id']) !== 1
                        || in_array($option['id'], $ids, true)
                        || !is_bool($option['reversible'])
                        || !self::text($option['label']) || !self::text($option['effect'])
                        || !self::text($option['risk']) || !is_array($option['pros'])
                        || !array_is_list($option['pros']) || !is_array($option['cons'])
                        || !array_is_list($option['cons'])) {
                        throw new InvalidArgumentException('option invalid');
                    }
                    $ids[] = $option['id'];
                }
                if (!$ids || !in_array($d['recommendation'], $ids, true)
                    || !in_array($d['safe_default'], $ids, true)) {
                    throw new InvalidArgumentException('decision recommendation invalid');
                }
            }
        }
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private static function fields(mixed $row, array $expected): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException('projection fields invalid');
        }
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('projection keys invalid');
        }
    }

    private static function write(string $path, array $record): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('cache directory unavailable');
        $raw = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($raw) > self::MAX_BYTES) throw new RuntimeException('cache too large');
        $tmp = tempnam($dir, '.orchestrator-');
        if (!is_string($tmp)) throw new RuntimeException('cache temp unavailable');
        try {
            if (file_put_contents($tmp, $raw, LOCK_EX) !== strlen($raw) || !rename($tmp, $path)) {
                throw new RuntimeException('cache write failed');
            }
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private static function result(string $status, array $record, int $age): array
    {
        return ['status' => $status, 'payload' => $record['payload'], 'age_seconds' => $age];
    }
}

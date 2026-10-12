<?php
declare(strict_types=1);
namespace ControlBot\Business;
require_once __DIR__ . '/FactoryAgentActivityUi.php';
use InvalidArgumentException;
use RuntimeException;
use Throwable;
final class FactoryOrchestratorLiveCache
{
    private const MAX_BYTES = 2000000;
    private const PUBLIC_SENSITIVE =
        '/(?:password|passwd|secret|token|cookie|authorization|bearer' .
        '|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PUBLIC_PII =
        '/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}' .
        '|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';
    public static function remember(string $path, int $now, int $ttl, int $stale, int $budget, callable $refresh): array
    {
        if ($path === '' || $path[0] !== '/' || $ttl < 1 || $stale < $ttl || $budget < 1 || $stale > 86400) {
            throw new InvalidArgumentException('cache policy invalid');
        }
        // A damaged derived cache must never prevent a canonical refresh.
        try {
            $cached = self::read($path);
            if ($cached !== null && $now < $cached['cached_at']) $cached = null;
        } catch (Throwable) { $cached = null; }
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
        if ($row['cached_at'] !== $row['payload']['observed_at']
            || $row['next_refresh_at'] < $row['cached_at']) throw new RuntimeException('cache timestamps invalid');
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
        if (!hash_equals(hash('sha256', $encoded), $payload['fingerprint'])) throw new InvalidArgumentException('payload invalid');
        return $payload;
    }
    /** Reject a self-consistent digest when the cached projection is not a valid v1 view. */
    private static function projection(array $view): void
    {
        $keys = ['version','observed_at','source_snapshot','read_only',
            'central','fronts','owner_decisions','fingerprint'];
        if (array_key_exists('blocker_explanations', $view)) {
            $keys[] = 'blocker_explanations';
            self::blockerExplanations($view['blocker_explanations'], $view['observed_at'] ?? null);
        }
        if (array_key_exists('agent_activity', $view)) $keys[] = 'agent_activity';
        if (array_key_exists('signal_summary', $view)) {
            $keys[] = 'signal_summary'; $s = $view['signal_summary'];
            self::fields($s, ['truncated','omitted','reason']); self::fields($s['omitted'] ?? null, ['blockers','owner_decisions','work']);
            $sum = 0;
            foreach ($s['omitted'] as $count) {
                if (!is_int($count) || $count < 0 || $count > 10000) throw new InvalidArgumentException('signal count invalid');
                $sum += $count;
            }
            if (!is_bool($s['truncated']) || $s['truncated'] !== ($sum > 0) || $s['reason'] !== ($sum > 0 ? 'bounded_signal_budget' : 'none')) throw new InvalidArgumentException('signal summary invalid');
        }
        if (array_key_exists('collector_failure', $view)) {
            $keys[] = 'collector_failure'; $f = $view['collector_failure'];
            self::fields($f, ['freshness','reason','last_success_at','detected_at']);
            $causes = ['evidence_invalid','snapshot_build_failed','snapshot_size_invalid','temp_write_failed',
                'atomic_rename_failed','snapshot_directory_unwritable','snapshot_target_invalid','internal_error','collector_failed'];
            if ($f['freshness'] !== 'stale' || !in_array($f['reason'], $causes, true)
                || !is_int($f['detected_at']) || $f['detected_at'] < 1 || $f['detected_at'] !== $view['observed_at']
                || ($f['last_success_at'] !== null && (!is_int($f['last_success_at'])
                    || $f['last_success_at'] < 1 || $f['last_success_at'] > $f['detected_at'])))
                throw new InvalidArgumentException('collector failure invalid');
        }
        self::fields($view, $keys);
        FactoryAgentActivityUi::renderSection($view['agent_activity'] ?? null);
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
        if (!in_array($freshness, ['current','stale','unknown'], true)) throw new InvalidArgumentException('central freshness invalid');
        if ($freshness === 'unknown') {
            if ($c !== [
                'activity_state'=>'UNKNOWN','available'=>null,'reserved'=>null,
                'blocked'=>null,'source_ref'=>null,'observed_at'=>null,
                'freshness'=>'unknown','age_seconds'=>null,
            ]) throw new InvalidArgumentException('central unknown invalid');
        } else {
            foreach (['available','reserved','blocked'] as $count) {
                if (!is_int($c[$count]) || $c[$count] < 0) throw new InvalidArgumentException('central count invalid');
            }
            if (!self::sourceRef($c['source_ref'])
                || !is_int($c['observed_at']) || $c['observed_at'] < 1
                || $c['observed_at'] > $view['observed_at']
                || $c['age_seconds'] !== $view['observed_at'] - $c['observed_at']) {
                throw new InvalidArgumentException('central timestamps invalid');
            }
            $state = $freshness === 'stale' ? 'STALE'
                : ($c['reserved'] > 0 ? 'ACTIVE' : ($c['available'] > 0
                    ? 'READY' : ($c['blocked'] > 0 ? 'BLOCKED' : 'IDLE')));
            if ($c['activity_state'] !== $state) throw new InvalidArgumentException('central status inconsistent');
        }
        $repos = ['pl0n3r/Factory','pl0n3r/Condor','pl0n3r/GrindFlow',
            'pl0n3r/brvtal','pl0n3r/ControlBot','pl0n3r/AutoFactory','pl0n3r/FactoryRunner'];
        foreach ($view['fronts'] as $front) {
            self::issueHref($front['issue_ref'] ?? null);
            self::fields($front, ['id','repository_ref','issue_ref','status',
                'progress_percent','progress_state','source_ref','observed_at','freshness','age_seconds']);
            if (!self::publicId($front['id']) || !self::text($front['issue_ref'])
                || !self::sourceRef($front['source_ref'])
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
            if (self::issueHref($d['issue_ref'] ?? null) !== 'https://github.com/'.($d['repository_ref'] ?? '').'/issues/'.($d['issue_number'] ?? '')) throw new InvalidArgumentException('decision identity invalid');
            self::fields($d, ['id','issue_ref','repository_ref','issue_number',
                'format','title','title_simple','summary_simple','explain_simple',
                'why_recommended','blocks','options','recommendation','safe_default',
                'expires_at','seconds_left','expired','source_ref','observed_at',
                'freshness','age_seconds']);
            if (!self::publicId($d['id']) || !self::text($d['issue_ref'])
                || !self::sourceRef($d['source_ref']) || !self::text($d['title'])
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
                if ($d['explain_simple'] !== null && !self::text($d['explain_simple'])) throw new InvalidArgumentException('decision explain invalid');
                foreach (['title_simple','summary_simple','why_recommended','blocks'] as $key) {
                    if (!self::text($d[$key])) throw new InvalidArgumentException('decision text invalid');
                }
                if ($d['expires_at'] === null) {
                    if ($d['seconds_left'] !== null || $d['expired'] !== null) throw new InvalidArgumentException('decision expiry invalid');
                } else {
                    $epoch = is_string($d['expires_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $d['expires_at']) === 1
                        ? strtotime($d['expires_at']) : false;
                    if ($epoch === false || $d['seconds_left'] !== max(0, $epoch - $view['observed_at'])
                        || $d['expired'] !== ($epoch <= $view['observed_at'])) throw new InvalidArgumentException('decision expiry invalid');
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
                        || !array_is_list($option['cons']) || count($option['pros']) > 6 || count($option['cons']) > 6
                        || ($option['cost'] !== null && !self::text($option['cost']))
                        || ($option['explain_simple'] !== null && !self::text($option['explain_simple']))) {
                        throw new InvalidArgumentException('option invalid');
                    }
                    foreach (['pros','cons'] as $kind) foreach ($option[$kind] as $item)
                        if (!self::text($item)) throw new InvalidArgumentException('option list item invalid');
                    $ids[] = $option['id'];
                }
                if (!$ids || !in_array($d['recommendation'], $ids, true)
                    || !in_array($d['safe_default'], $ids, true)) throw new InvalidArgumentException('decision recommendation invalid');
            }
        }
    }
    /**
     * Accepts the opt-in #753 projection without trusting its SHA as authority.
     * Legacy cache payloads do not contain this field and remain unchanged.
     */
    private static function blockerExplanations(mixed $rows, mixed $now): void
    {
        if (!is_int($now) || $now < 1 || !is_array($rows)
            || !array_is_list($rows) || count($rows) > 50) {
            throw new InvalidArgumentException('blocker projection invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            self::fields($row, [
                'issue_ref', 'source_ref', 'observed_at', 'freshness',
                'cause', 'actor', 'next_action', 'blocked_at',
                'age_seconds', 'mvp_impact', 'evidence_status',
            ]);
            $ref = $row['issue_ref'];
            if (!is_string($ref) || preg_match(
                '~^github:pl0n3r/(?:Factory|Condor|GrindFlow|brvtal|ControlBot|AutoFactory|FactoryRunner)#[1-9][0-9]*$~D',
                $ref
            ) !== 1 || isset($seen[$ref]) || $row['source_ref'] !== $ref
                || $row['freshness'] !== 'current'
                || !is_int($row['observed_at']) || $row['observed_at'] < 1
                || $row['observed_at'] > $now) {
                throw new InvalidArgumentException('blocker identity invalid');
            }
            $seen[$ref] = true;
            if ($row['evidence_status'] === 'UNKNOWN') {
                if ($row['cause'] !== 'UNKNOWN' || $row['actor'] !== 'UNKNOWN'
                    || $row['next_action'] !== 'UNKNOWN'
                    || $row['blocked_at'] !== null || $row['age_seconds'] !== null
                    || $row['mvp_impact'] !== null) {
                    throw new InvalidArgumentException('unknown blocker evidence inconsistent');
                }
                continue;
            }
            if ($row['evidence_status'] !== 'DECLARED'
                || !in_array($row['actor'], ['owner','agent','external'], true)
                || !is_bool($row['mvp_impact'])
                || !is_int($row['blocked_at']) || $row['blocked_at'] < 1
                || $row['blocked_at'] > $now
                || !is_int($row['age_seconds'])
                || $row['age_seconds'] !== $now - $row['blocked_at']) {
                throw new InvalidArgumentException('declared blocker evidence inconsistent');
            }
            foreach (['cause', 'next_action'] as $field) {
                $text = $row[$field];
                if (!is_string($text) || strlen($text) > 160
                    || !self::publicSafe($text, 160)
                    || preg_match('/[<>]/', $text) === 1) {
                    throw new InvalidArgumentException('blocker text unsafe');
                }
            }
        }
    }

    private static function issueHref(mixed $ref): string {
        if (is_string($ref) && preg_match('~^https://github\.com/pl0n3r/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*$~D', $ref) === 1) return $ref;
        if (is_string($ref) && preg_match('~^github:(pl0n3r/[A-Za-z0-9_.-]+)#([1-9][0-9]*)$~D', $ref, $m) === 1) return 'https://github.com/'.$m[1].'/issues/'.$m[2];
        throw new InvalidArgumentException('issue_ref invalid'); }
    private static function digest(mixed $value): bool { return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1; }
    /** Match the producer's public identity grammar (3..161 ASCII bytes). */
    private static function publicId(mixed $value): bool {
        return is_string($value)
            && preg_match('/^[a-z][a-z0-9._:\/#-]{2,160}$/D', $value) === 1
            && self::publicSafe($value, 161);
    }
    /** Source references are public labels, not unrestricted rich decision text. */
    private static function sourceRef(mixed $value): bool {
        return is_string($value) && self::publicSafe($value, 240);
    }
    private static function publicSafe(string $value, int $max): bool {
        return $value !== '' && $value === trim($value) && strlen($value) <= $max
            && !str_contains($value, '@')
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1
            && preg_match(self::PUBLIC_SENSITIVE, $value) !== 1
            && preg_match(self::PUBLIC_PII, $value) !== 1;
    }
    private static function text(mixed $value): bool { return is_string($value) && trim($value) !== '' && strlen(trim($value)) <= 600 && preg_match('/[\x00-\x1f\x7f]/', trim($value)) !== 1; }
    private static function fields(mixed $row, array $expected): void {
        if (!is_array($row) || array_is_list($row)) throw new InvalidArgumentException('projection fields invalid'); $actual = array_keys($row); sort($actual); sort($expected);
        if ($actual !== $expected) throw new InvalidArgumentException('projection keys invalid'); }
    private static function write(string $path, array $record): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('cache directory unavailable');
        $raw = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($raw) > self::MAX_BYTES) throw new RuntimeException('cache too large');
        $tmp = tempnam($dir, '.orchestrator-');
        if (!is_string($tmp)) throw new RuntimeException('cache temp unavailable'); try {
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

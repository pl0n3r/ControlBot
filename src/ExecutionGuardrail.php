<?php
declare(strict_types=1);
namespace ControlBot\Guardrail;
use InvalidArgumentException;
final class ExecutionGuardrail
{
    public static function analyze(array $s, array $t, int $now): array
    {
        self::fields($s, [
            'issue_ref','pr_ref','sha','last_result','next_approach','commits','review_rounds',
            'progress_version','previous_progress_version','issue_updated_at','reservation_updated_at',
            'heartbeat_at','state','state_started_at','attempts','retry_events','handoffs','non_preemptible',
        ], 'snapshot');
        self::fields($t, [
            'issue_stale_seconds','reservation_stale_seconds','heartbeat_stale_seconds',
            'rapid_retry_seconds','state_timeouts',
        ], 'thresholds');
        if ($now < 0 || !is_bool($s['non_preemptible'] ?? null)) {
            throw new InvalidArgumentException('Guardrail clock or preemption invalid.');
        }
        $issue = self::ref($s['issue_ref'], 'issue_ref');
        $pr = self::ref($s['pr_ref'], 'pr_ref');
        $sha = self::sha($s['sha']);
        $last = self::text($s['last_result'], 'last_result', 240);
        $next = self::text($s['next_approach'], 'next_approach', 240);
        $commits = self::integer($s['commits'], 'commits', 0, 1000);
        $rounds = self::integer($s['review_rounds'], 'review_rounds', 0, 100);
        $progress = self::integer($s['progress_version'], 'progress_version', 0);
        $previousProgress = self::integer($s['previous_progress_version'], 'previous_progress_version', 0);
        $issueAt = self::pastTime($s['issue_updated_at'], 'issue_updated_at', $now);
        $reservationAt = self::pastTime($s['reservation_updated_at'], 'reservation_updated_at', $now);
        $heartbeatAt = self::pastTime($s['heartbeat_at'], 'heartbeat_at', $now);
        $stateAt = self::pastTime($s['state_started_at'], 'state_started_at', $now);
        $state = self::slug($s['state'], 'state');
        $attempts = self::attempts($s['attempts'], $now);
        $retries = self::retries($s['retry_events'], $now);
        $handoffs = self::handoffs($s['handoffs'], $now);
        $issueTtl = self::integer($t['issue_stale_seconds'], 'issue_stale_seconds', 1, 604800);
        $reservationTtl = self::integer($t['reservation_stale_seconds'], 'reservation_stale_seconds', 1, 604800);
        $heartbeatTtl = self::integer($t['heartbeat_stale_seconds'], 'heartbeat_stale_seconds', 1, 86400);
        $retryWindow = self::integer($t['rapid_retry_seconds'], 'rapid_retry_seconds', 1, 3600);
        if (!is_array($t['state_timeouts']) || array_is_list($t['state_timeouts']) || !isset($t['state_timeouts'][$state])) {
            throw new InvalidArgumentException('state_timeouts invalid.');
        }
        $stateTimeout = self::integer($t['state_timeouts'][$state], 'state_timeout', 1, 86400);
        $signals = [];
        $lastTwo = array_slice($attempts, -2);
        if (count($lastTwo) === 2) {
            [$a, $b] = $lastTwo;
            if ($a['result'] === 'failed' && $b['result'] === 'failed' && $a['approach'] === $b['approach']) {
                $signals[] = 'same_approach_twice';
            }
            if ($a['error'] !== null && $a['error'] === $b['error'] && $a['evidence'] === $b['evidence']) {
                $signals[] = 'same_error_without_new_evidence';
            }
        }
        if ($commits > 10) {
            $signals[] = 'too_many_commits';
        }
        if ($rounds >= 3 && $progress === $previousProgress) {
            $signals[] = 'review_loop';
        }
        if ($now - $issueAt > $issueTtl) {
            $signals[] = 'issue_stale';
        }
        if ($now - $reservationAt > $reservationTtl) {
            $signals[] = 'reservation_stale';
        }
        if ($now - $heartbeatAt > $heartbeatTtl) {
            $signals[] = 'heartbeat_lost';
        }
        if ($now - $stateAt >= $stateTimeout) {
            $signals[] = 'state_timeout';
        }
        if (self::hasRapidRetry($retries, $retryWindow)) {
            $signals[] = 'duplicate_retry';
        }
        if (self::hasBounce($handoffs)) {
            $signals[] = 'handoff_bounce';
        }
        sort($signals);
        $hard = array_intersect($signals, [
            'same_approach_twice','same_error_without_new_evidence','heartbeat_lost',
            'state_timeout','duplicate_retry','handoff_bounce',
        ]) !== [];
        $health = $signals === [] ? 'healthy' : ($hard ? 'stuck' : 'degraded');
        $pause = $health === 'stuck' && !$s['non_preemptible'];
        $fingerprint = hash('sha256', json_encode([$issue,$pr,$sha,$signals], JSON_THROW_ON_ERROR));
        return [
            'health' => $health,
            'signals' => $signals,
            'pause_required' => $pause,
            'escalation_required' => $health === 'stuck' && $s['non_preemptible'],
            'alert_fingerprint' => $fingerprint,
            'handoff' => [
                'issue_ref' => $issue,
                'pr_ref' => $pr,
                'sha' => $sha,
                'last_result' => $last,
                'attempts' => count($attempts),
                'next_approach' => $next,
                'signals' => $signals,
            ],
        ];
    }
    private static function attempts(mixed $rows, int $now): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 20) {
            throw new InvalidArgumentException('attempts invalid.');
        }
        $out = [];
        foreach ($rows as $row) {
            self::fields($row, ['approach','result','error','evidence','at'], 'attempt');
            $result = $row['result'] ?? null;
            if (!in_array($result, ['success','failed','blocked'], true)) {
                throw new InvalidArgumentException('attempt result invalid.');
            }
            $out[] = [
                'approach' => self::text($row['approach'], 'approach', 120),
                'result' => $result,
                'error' => self::nullableText($row['error'], 'error', 240),
                'evidence' => self::nullableText($row['evidence'], 'evidence', 240),
                'at' => self::pastTime($row['at'], 'attempt.at', $now),
            ];
        }
        return $out;
    }
    private static function retries(mixed $rows, int $now): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 50) {
            throw new InvalidArgumentException('retry_events invalid.');
        }
        $out = [];
        foreach ($rows as $row) {
            self::fields($row, ['key','at'], 'retry');
            $out[] = ['key' => self::ref($row['key'], 'retry.key'), 'at' => self::pastTime($row['at'], 'retry.at', $now)];
        }
        return $out;
    }
    private static function handoffs(mixed $rows, int $now): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 20) {
            throw new InvalidArgumentException('handoffs invalid.');
        }
        $out = [];
        foreach ($rows as $row) {
            self::fields($row, ['agent','state','at'], 'handoff');
            $out[] = [
                'agent' => self::slug($row['agent'], 'handoff.agent'),
                'state' => self::slug($row['state'], 'handoff.state'),
                'at' => self::pastTime($row['at'], 'handoff.at', $now),
            ];
        }
        return $out;
    }
    private static function hasRapidRetry(array $rows, int $window): bool
    {
        for ($i = 1; $i < count($rows); $i++) {
            if ($rows[$i]['at'] < $rows[$i - 1]['at']) {
                throw new InvalidArgumentException('retry_events order invalid.');
            }
            if ($rows[$i]['key'] === $rows[$i - 1]['key'] && $rows[$i]['at'] - $rows[$i - 1]['at'] <= $window) {
                return true;
            }
        }
        return false;
    }
    private static function hasBounce(array $rows): bool
    {
        if (count($rows) < 3) {
            return false;
        }
        [$a,$b,$c] = array_slice($rows, -3);
        return $a['agent'] === $c['agent'] && $a['agent'] !== $b['agent']
            && $a['state'] === $b['state'] && $b['state'] === $c['state'];
    }
    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        $keys = array_keys($row);
        sort($keys); sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
    private static function integer(mixed $value, string $label, int $min, int $max = PHP_INT_MAX): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
    private static function pastTime(mixed $value, string $label, int $now): int
    {
        $value = self::integer($value, $label, 0);
        if ($value > $now) {
            throw new InvalidArgumentException($label . ' is in the future.');
        }
        return $value;
    }
    private static function slug(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
    private static function ref(mixed $value, string $label): string
    {
        $value = self::text($value, $label, 180);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#@-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }
    private static function sha(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new InvalidArgumentException('sha invalid.');
        }
        return $value;
    }
    private static function nullableText(mixed $value, string $label, int $max): ?string
    {
        return $value === null ? null : self::text($value, $label, $max);
    }
    private static function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('/(?:-----BEGIN [^-]*PRIVATE KEY-----|\\b(?:bearer\\s+[A-Za-z0-9._~+\\/-]{8,}|(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn)\\s*[:=]\\s*\\S+|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}))/i', $value) === 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return trim($value);
    }
}

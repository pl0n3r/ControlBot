<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

use InvalidArgumentException;

final class StuckDetector
{
    private const ACTION = 'pause_at_safe_point';

    public function detect(array $snapshot): array
    {
        $s = $this->normalize($snapshot);
        $detections = [];

        if ($s['same_error_count'] >= 2 && $s['same_error_fingerprint'] !== '') {
            $detections[] = $this->finding('repeated_error', 'warning', $s, [
                'error_fingerprint' => $s['same_error_fingerprint'],
                'count' => $s['same_error_count'],
            ]);
        }

        if ($s['same_approach_failures'] >= 2 && $s['approach_fingerprint'] !== '') {
            $detections[] = $this->finding('same_approach_loop', 'error', $s, [
                'approach_fingerprint' => $s['approach_fingerprint'],
                'failures' => $s['same_approach_failures'],
                'next_attempt_auto_executable' => false,
            ]);
        }

        if (!$s['pr_closed'] && $s['commit_count'] > 10) {
            $detections[] = $this->finding('pr_commit_overflow', 'warning', $s, [
                'commit_count' => $s['commit_count'],
            ]);
        }

        if (!$s['review_progress'] && $s['review_rounds'] >= 3) {
            $detections[] = $this->finding('review_loop', 'warning', $s, [
                'review_rounds' => $s['review_rounds'],
            ]);
        }

        if (
            $s['reservation_active']
            && $s['reservation_timeout'] > 0
            && ($s['now'] - $s['last_progress_at']) > $s['reservation_timeout']
        ) {
            $detections[] = $this->finding('stale_reservation', 'warning', $s, [
                'age_seconds' => $s['now'] - $s['last_progress_at'],
                'timeout_seconds' => $s['reservation_timeout'],
            ]);
        }

        if (
            $s['heartbeat_timeout'] > 0
            && ($s['now'] - $s['last_heartbeat_at']) > $s['heartbeat_timeout']
        ) {
            $detections[] = $this->finding('heartbeat_timeout', 'error', $s, [
                'age_seconds' => $s['now'] - $s['last_heartbeat_at'],
                'timeout_seconds' => $s['heartbeat_timeout'],
            ]);
        }

        $stateTimeout = $s['state_timeouts'][$s['state']] ?? null;
        if (
            is_int($stateTimeout)
            && $stateTimeout > 0
            && ($s['now'] - $s['state_started_at']) > $stateTimeout
        ) {
            $detections[] = $this->finding('state_timeout', 'error', $s, [
                'state' => $s['state'],
                'age_seconds' => $s['now'] - $s['state_started_at'],
                'timeout_seconds' => $stateTimeout,
            ]);
        }

        if ($s['rapid_retry_count'] >= 2) {
            $detections[] = $this->finding('rapid_retry_loop', 'warning', $s, [
                'retry_count' => $s['rapid_retry_count'],
                'window_seconds' => $s['rapid_retry_window'],
            ]);
        }

        if (!$s['handoff_state_changed'] && $s['handoff_bounce_count'] >= 3) {
            $detections[] = $this->finding('handoff_bounce', 'warning', $s, [
                'bounce_count' => $s['handoff_bounce_count'],
            ]);
        }

        return $detections;
    }

    private function normalize(array $snapshot): array
    {
        $allowed = [
            'session_id', 'work_item_id', 'now', 'state', 'state_started_at',
            'state_timeouts', 'last_heartbeat_at', 'heartbeat_timeout',
            'last_progress_at', 'reservation_active', 'reservation_timeout',
            'commit_count', 'pr_closed', 'review_rounds', 'review_progress',
            'same_error_count', 'same_error_fingerprint',
            'same_approach_failures', 'approach_fingerprint',
            'rapid_retry_count', 'rapid_retry_window',
            'handoff_bounce_count', 'handoff_state_changed',
        ];
        if (array_diff(array_keys($snapshot), $allowed) !== []) {
            throw new InvalidArgumentException('Campo de snapshot no permitido.');
        }

        $required = ['session_id', 'work_item_id', 'now', 'state', 'state_started_at', 'state_timeouts', 'last_heartbeat_at'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $snapshot)) {
                throw new InvalidArgumentException("Falta campo de snapshot: {$key}");
            }
        }

        foreach (['session_id', 'work_item_id', 'state'] as $key) {
            if (!is_string($snapshot[$key]) || trim($snapshot[$key]) === '' || strlen($snapshot[$key]) > 120) {
                throw new InvalidArgumentException("Campo {$key} inválido.");
            }
        }
        foreach (['now', 'state_started_at', 'last_heartbeat_at'] as $key) {
            if (!is_int($snapshot[$key]) || $snapshot[$key] < 0) {
                throw new InvalidArgumentException("Campo {$key} inválido.");
            }
        }
        if ($snapshot['state_started_at'] > $snapshot['now'] || $snapshot['last_heartbeat_at'] > $snapshot['now']) {
            throw new InvalidArgumentException('Timestamps de snapshot inválidos.');
        }
        if (!is_array($snapshot['state_timeouts']) || array_is_list($snapshot['state_timeouts'])) {
            throw new InvalidArgumentException('Timeouts por estado inválidos.');
        }
        foreach ($snapshot['state_timeouts'] as $state => $seconds) {
            if (!is_string($state) || $state === '' || !is_int($seconds) || $seconds < 1 || $seconds > 86400) {
                throw new InvalidArgumentException('Timeout por estado inválido.');
            }
        }

        $defaults = [
            'heartbeat_timeout' => 0,
            'last_progress_at' => $snapshot['now'],
            'reservation_active' => false,
            'reservation_timeout' => 0,
            'commit_count' => 0,
            'pr_closed' => false,
            'review_rounds' => 0,
            'review_progress' => false,
            'same_error_count' => 0,
            'same_error_fingerprint' => '',
            'same_approach_failures' => 0,
            'approach_fingerprint' => '',
            'rapid_retry_count' => 0,
            'rapid_retry_window' => 0,
            'handoff_bounce_count' => 0,
            'handoff_state_changed' => false,
        ];
        $s = $snapshot + $defaults;

        foreach ([
            'heartbeat_timeout', 'last_progress_at', 'reservation_timeout', 'commit_count',
            'review_rounds', 'same_error_count', 'same_approach_failures',
            'rapid_retry_count', 'rapid_retry_window', 'handoff_bounce_count',
        ] as $key) {
            if (!is_int($s[$key]) || $s[$key] < 0) {
                throw new InvalidArgumentException("Campo {$key} inválido.");
            }
        }
        if ($s['last_progress_at'] > $s['now']) {
            throw new InvalidArgumentException('Progreso futuro inválido.');
        }
        foreach (['reservation_active', 'pr_closed', 'review_progress', 'handoff_state_changed'] as $key) {
            if (!is_bool($s[$key])) {
                throw new InvalidArgumentException("Campo {$key} inválido.");
            }
        }
        foreach (['same_error_fingerprint', 'approach_fingerprint'] as $key) {
            if (!is_string($s[$key]) || strlen($s[$key]) > 128) {
                throw new InvalidArgumentException("Campo {$key} inválido.");
            }
        }

        return $s;
    }

    private function finding(string $type, string $severity, array $s, array $evidence): array
    {
        $material = [
            'type' => $type,
            'session_id' => $s['session_id'],
            'work_item_id' => $s['work_item_id'],
            'evidence' => $evidence,
        ];

        return [
            'type' => $type,
            'severity' => $severity,
            'fingerprint' => hash('sha256', json_encode($material, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'evidence' => $evidence,
            'recommended_action' => self::ACTION,
        ];
    }
}

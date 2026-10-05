<?php
declare(strict_types=1);

namespace ControlBot\Runner;

use InvalidArgumentException;

final class RunnerOutcomeProjection
{
    private const EVENT_STALE_AFTER_SECONDS = 300;

    private static function fields(array $record, array $expected, string $label): void
    {
        $actual = array_keys($record);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }

    private static function ref(mixed $value, string $label, int $max = 180): string
    {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#@-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function uuid(mixed $value, string $label): string
    {
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) !== 1) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return strtolower($value);
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        if (!is_int($value) || $value < 1 || $value > 1_000_000_000) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        return $value;
    }

    private static function delivery(array $record): array
    {
        self::fields(
            $record,
            [
                'version', 'session_id', 'assignment_id', 'runner_id', 'generation',
                'order_id', 'attempt_id', 'work_item_id', 'order_fingerprint', 'execution',
            ],
            'SessionDelivery'
        );
        if (($record['version'] ?? null) !== 1
            || ($record['execution'] ?? null) !== false
            || !is_string($record['order_fingerprint'])
            || preg_match('/^[0-9a-f]{64}$/D', $record['order_fingerprint']) !== 1) {
            throw new InvalidArgumentException('SessionDelivery invalid.');
        }

        return [
            'version' => 1,
            'session_id' => self::ref($record['session_id'], 'delivery.session_id'),
            'assignment_id' => self::ref($record['assignment_id'], 'delivery.assignment_id'),
            'runner_id' => self::uuid($record['runner_id'], 'delivery.runner_id'),
            'generation' => self::positiveInt($record['generation'], 'delivery.generation'),
            'order_id' => self::uuid($record['order_id'], 'delivery.order_id'),
            'attempt_id' => self::uuid($record['attempt_id'], 'delivery.attempt_id'),
            'work_item_id' => self::ref($record['work_item_id'], 'delivery.work_item_id'),
            'order_fingerprint' => $record['order_fingerprint'],
            'execution' => false,
        ];
    }

    private static function unknown(array $delivery, string $reason, ?array $event = null): array
    {
        return [
            'version' => 1,
            'session_id' => $delivery['session_id'],
            'assignment_id' => $delivery['assignment_id'],
            'order_id' => $delivery['order_id'],
            'attempt_id' => $delivery['attempt_id'],
            'runner_id' => $delivery['runner_id'],
            'generation' => $delivery['generation'],
            'delivery' => 'UNKNOWN',
            'ack' => 'UNKNOWN',
            'progress' => 'UNKNOWN',
            'outcome' => 'UNKNOWN',
            'terminal' => false,
            'freshness' => $reason,
            'provenance' => $event === null ? null : [
                'event_id' => $event['event_id'],
                'sequence' => $event['sequence'],
                'state' => $event['state'],
                'occurred_at' => $event['occurred_at'],
                'evidence_code' => $event['evidence']['code'],
                'evidence_ref' => $event['evidence']['ref'],
            ],
            'execution' => false,
        ];
    }

    public static function project(
        array $deliveryRecord,
        ?array $eventRecord,
        int $now,
        int $staleAfterSeconds = self::EVENT_STALE_AFTER_SECONDS,
    ): array {
        $delivery = self::delivery($deliveryRecord);
        if ($now < 0 || $staleAfterSeconds < 1 || $staleAfterSeconds > 3600) {
            throw new InvalidArgumentException('Projection freshness thresholds invalid.');
        }

        if ($eventRecord === null) {
            return self::unknown($delivery, 'missing');
        }

        $event = RunnerGateway::event($eventRecord);
        if ($event['occurred_at'] > $now || $now - $event['occurred_at'] > $staleAfterSeconds) {
            return self::unknown($delivery, 'stale', $event);
        }

        if ($event['order_id'] !== $delivery['order_id']
            || $event['attempt_id'] !== $delivery['attempt_id']
            || $event['runner_id'] !== $delivery['runner_id']
            || $event['generation'] !== $delivery['generation']) {
            return self::unknown($delivery, 'mismatched', $event);
        }

        [$progress, $outcome, $terminal] = match ($event['state']) {
            'accepted' => ['accepted', 'UNKNOWN', false],
            'started', 'heartbeat' => ['running', 'UNKNOWN', false],
            'progress', 'checkpoint' => ['in_progress', 'UNKNOWN', false],
            'waiting_human' => ['waiting_human', 'UNKNOWN', false],
            'blocked' => ['blocked', 'UNKNOWN', false],
            'completed' => ['complete', 'SUCCEEDED', true],
            'failed' => ['complete', 'FAILED', true],
            'cancelled' => ['complete', 'CANCELLED', true],
        };

        return [
            'version' => 1,
            'session_id' => $delivery['session_id'],
            'assignment_id' => $delivery['assignment_id'],
            'order_id' => $delivery['order_id'],
            'attempt_id' => $delivery['attempt_id'],
            'runner_id' => $delivery['runner_id'],
            'generation' => $delivery['generation'],
            'delivery' => 'DELIVERED',
            'ack' => 'ACKNOWLEDGED',
            'progress' => $progress,
            'outcome' => $outcome,
            'terminal' => $terminal,
            'freshness' => 'fresh',
            'provenance' => [
                'event_id' => $event['event_id'],
                'sequence' => $event['sequence'],
                'state' => $event['state'],
                'occurred_at' => $event['occurred_at'],
                'evidence_code' => $event['evidence']['code'],
                'evidence_ref' => $event['evidence']['ref'],
            ],
            'execution' => false,
        ];
    }
}

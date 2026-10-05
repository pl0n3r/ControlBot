<?php
declare(strict_types=1);

namespace ControlBot\Runner;

use ControlBot\Runtime\AgentRuntime;
use InvalidArgumentException;

final class RunnerSessionDelivery
{
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

    private static function target(array $record): array
    {
        self::fields(
            $record,
            ['version', 'session_id', 'assignment_id', 'runner_id', 'generation'],
            'DeliveryTarget'
        );
        if (($record['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('DeliveryTarget version invalid.');
        }

        return [
            'version' => 1,
            'session_id' => self::ref($record['session_id'], 'target.session_id'),
            'assignment_id' => self::ref($record['assignment_id'], 'target.assignment_id'),
            'runner_id' => self::uuid($record['runner_id'], 'target.runner_id'),
            'generation' => self::positiveInt($record['generation'], 'target.generation'),
        ];
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

    public static function bind(
        array $sessionRecord,
        array $assignmentRecord,
        array $orderRecord,
        array $targetRecord,
        int $now,
        ?array $existingDelivery = null,
    ): array {
        $session = AgentRuntime::session($sessionRecord);
        $assignment = AgentRuntime::assignment($assignmentRecord);
        $order = RunnerGateway::order($orderRecord);
        $target = self::target($targetRecord);

        if ($now < 0 || $order['issued_at'] > $now || $order['expires_at'] <= $now) {
            throw new InvalidArgumentException('ExecutionOrder is not currently deliverable.');
        }

        $health = AgentRuntime::sessionHealth($session, $now);
        if (!$health['eligible'] || !in_array($session['status'], ['assigned', 'working'], true)) {
            throw new InvalidArgumentException('Session is stale or offline for delivery.');
        }
        if (!in_array($assignment['status'], ['assigned', 'running'], true)) {
            throw new InvalidArgumentException('Assignment is not deliverable.');
        }

        if ($session['assignment_id'] !== $assignment['assignment_id']
            || $assignment['session_id'] !== $session['session_id']
            || $target['session_id'] !== $session['session_id']
            || $target['assignment_id'] !== $assignment['assignment_id']) {
            throw new InvalidArgumentException('Session or assignment was reassigned.');
        }

        if ($target['runner_id'] !== $order['runner_id']
            || $target['generation'] !== $order['generation']) {
            throw new InvalidArgumentException('Runner generation fence mismatch.');
        }

        if ($order['work_item_id'] !== $assignment['source_ref']
            || $order['scope'] !== $assignment['project_id']) {
            throw new InvalidArgumentException('ExecutionOrder target mismatch.');
        }

        $delivery = [
            'version' => 1,
            'session_id' => $session['session_id'],
            'assignment_id' => $assignment['assignment_id'],
            'runner_id' => $order['runner_id'],
            'generation' => $order['generation'],
            'order_id' => $order['order_id'],
            'attempt_id' => $order['attempt_id'],
            'work_item_id' => $order['work_item_id'],
            'order_fingerprint' => RunnerGateway::orderFingerprint($order),
            'execution' => false,
        ];

        if ($existingDelivery === null) {
            return $delivery;
        }

        $existing = self::delivery($existingDelivery);
        if ($existing !== $delivery) {
            throw new InvalidArgumentException('Conflicting or duplicate session delivery.');
        }

        return $existing;
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Production;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class ContinuationToken
{
    private const FIELDS = [
        'version', 'continuation_id', 'run_id', 'issue', 'project', 'environment',
        'blocked_on', 'expected_action', 'plan_digest', 'source_sha', 'risk_digest',
        'created_at', 'expires_at', 'resumed_at', 'consumed_at',
    ];

    private function __construct(private readonly array $record)
    {
    }

    public static function fromRecord(array $record): self
    {
        self::validate($record);
        return new self($record);
    }

    public function safeRecord(): array
    {
        return $this->record;
    }

    public function assertCurrent(array $current, int $now): void
    {
        if ($now < 1) {
            throw new InvalidArgumentException('now inválido.');
        }
        if ($this->record['consumed_at'] !== null) {
            throw new RuntimeException('Continuation ya consumida.');
        }
        if ($now < self::timestamp($this->record['created_at'])
            || $now >= self::timestamp($this->record['expires_at'])) {
            throw new RuntimeException('Continuation expirada o no activa.');
        }

        $expected = ['source_sha', 'expected_action', 'plan_digest', 'risk_digest'];
        $keys = array_keys($current);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Evidencia actual incompleta.');
        }
        foreach ($expected as $field) {
            if (!is_string($current[$field]) || $current[$field] !== $this->record[$field]) {
                throw new RuntimeException('Continuation invalidada por drift: ' . $field);
            }
        }
    }

    public function close(string $timestamp, bool $resumed): self
    {
        if ($this->record['consumed_at'] !== null) {
            return $this;
        }
        self::timestampString($timestamp, 'consumed_at');
        $closed = $this->record;
        $closed['consumed_at'] = $timestamp;
        $closed['resumed_at'] = $resumed ? $timestamp : null;
        self::validate($closed);
        return new self($closed);
    }

    private static function validate(array $record): void
    {
        if (array_is_list($record)) {
            throw new InvalidArgumentException('ContinuationToken inválido.');
        }
        $keys = array_keys($record);
        $expected = self::FIELDS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected || ($record['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('ContinuationToken fields inválidos.');
        }

        self::uuid($record['continuation_id'] ?? null, 'continuation_id');
        self::uuid($record['run_id'] ?? null, 'run_id');
        self::issue($record['issue'] ?? null);
        self::slug($record['project'] ?? null, 'project', 64);
        self::slug($record['environment'] ?? null, 'environment', 32);
        if (!in_array($record['blocked_on'] ?? null, ['owner_approval', 'external_event', 'transport'], true)) {
            throw new InvalidArgumentException('blocked_on inválido.');
        }
        self::action($record['expected_action'] ?? null);
        self::digest($record['plan_digest'] ?? null, 'plan_digest');
        self::digest($record['risk_digest'] ?? null, 'risk_digest');
        if (!is_string($record['source_sha'] ?? null)
            || preg_match('/^[0-9a-f]{40}$/D', $record['source_sha']) !== 1) {
            throw new InvalidArgumentException('source_sha inválido.');
        }

        self::timestampString($record['created_at'] ?? null, 'created_at');
        self::timestampString($record['expires_at'] ?? null, 'expires_at');
        $created = self::timestamp($record['created_at']);
        $expires = self::timestamp($record['expires_at']);
        if ($expires <= $created || ($expires - $created) > 3600) {
            throw new InvalidArgumentException('TTL inválido.');
        }

        foreach (['resumed_at', 'consumed_at'] as $field) {
            if ($record[$field] !== null) {
                self::timestampString($record[$field], $field);
                if (self::timestamp($record[$field]) < $created) {
                    throw new InvalidArgumentException($field . ' inválido.');
                }
            }
        }
        if ($record['resumed_at'] !== null && $record['consumed_at'] === null) {
            throw new InvalidArgumentException('Continuation reanudada sin consumo.');
        }
    }

    private static function uuid(mixed $value, string $field): void
    {
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new InvalidArgumentException($field . ' inválido.');
        }
    }

    private static function issue(mixed $value): void
    {
        if (!is_string($value)
            || preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#[1-9][0-9]*$~D', $value) !== 1) {
            throw new InvalidArgumentException('issue inválido.');
        }
    }

    private static function slug(mixed $value, string $field, int $max): void
    {
        if (!is_string($value)
            || strlen($value) > $max
            || preg_match('/^[a-z][a-z0-9-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException($field . ' inválido.');
        }
    }

    private static function action(mixed $value): void
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1) {
            throw new InvalidArgumentException('expected_action inválido.');
        }
    }

    private static function digest(mixed $value, string $field): void
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException($field . ' inválido.');
        }
    }

    private static function timestampString(mixed $value, string $field): void
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1
            || self::timestamp($value) < 1) {
            throw new InvalidArgumentException($field . ' inválido.');
        }
    }

    private static function timestamp(string $value): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Timestamp inválido.');
        }
        return $date->getTimestamp();
    }
}

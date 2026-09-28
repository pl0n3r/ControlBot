<?php
declare(strict_types=1);

namespace ControlBot\Production;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class CapabilityGrant
{
    private const FIELDS = [
        'version', 'grant_id', 'capability', 'project', 'environment', 'resource',
        'operation', 'issue', 'run_id', 'subject', 'issued_at', 'expires_at',
        'backup_receipt_id', 'owner_approval_id', 'idempotency_key', 'revoked_at',
    ];

    private function __construct(private readonly array $record)
    {
    }

    /**
     * @param list<string> $restrictions
     */
    public static function issue(array $record, array $restrictions = []): self
    {
        $normalized = self::normalize($record);
        $policy = CapabilityPolicy::classify($normalized['capability'], $restrictions);

        if (!$policy['known'] || $policy['decision'] === 'forbidden') {
            throw new RuntimeException('Capability no autorizable.');
        }
        if ($policy['requires_backup'] && $normalized['backup_receipt_id'] === null) {
            throw new RuntimeException('Backup receipt requerido.');
        }
        if ($policy['requires_owner_approval'] && $normalized['owner_approval_id'] === null) {
            throw new RuntimeException('Aprobación del dueño requerida.');
        }

        return new self($normalized);
    }

    public function safeRecord(): array
    {
        return $this->record;
    }

    /**
     * @param array{capability:string,project:string,environment:string,resource:string,operation:string,issue:string,run_id:string,subject:string} $scope
     * @param list<string> $restrictions
     */
    public function authorize(array $scope, int $now, array $restrictions = []): array
    {
        if ($now < 1) {
            throw new InvalidArgumentException('now inválido.');
        }
        self::scope($scope);

        if ($this->record['revoked_at'] !== null) {
            return self::deny('grant_revoked');
        }
        if ($now >= self::timestamp($this->record['expires_at'])) {
            return self::deny('grant_expired');
        }
        if ($now < self::timestamp($this->record['issued_at'])) {
            return self::deny('grant_not_active');
        }

        $policy = CapabilityPolicy::classify($this->record['capability'], $restrictions);
        if (!$policy['known'] || $policy['decision'] === 'forbidden') {
            return self::deny('policy_denied');
        }
        if ($policy['requires_backup'] && $this->record['backup_receipt_id'] === null) {
            return self::deny('backup_receipt_required');
        }
        if ($policy['requires_owner_approval'] && $this->record['owner_approval_id'] === null) {
            return self::deny('owner_approval_required');
        }

        foreach (['capability', 'project', 'environment', 'resource', 'operation', 'issue', 'run_id', 'subject'] as $key) {
            if ($scope[$key] !== $this->record[$key]) {
                return self::deny($key . '_mismatch');
            }
        }

        return [
            'authorized' => true,
            'reason' => 'authorized',
            'decision' => $policy['decision'],
            'grant_id' => $this->record['grant_id'],
        ];
    }

    public function revoke(string $revokedAt): self
    {
        self::timestampString($revokedAt, 'revoked_at');
        $copy = $this->record;
        $copy['revoked_at'] = $revokedAt;
        return new self($copy);
    }

    private static function normalize(array $record): array
    {
        if (array_is_list($record)) {
            throw new InvalidArgumentException('Grant inválido.');
        }
        $keys = array_keys($record);
        $expected = self::FIELDS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected || ($record['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Grant fields inválidos.');
        }

        foreach (['grant_id', 'run_id'] as $key) {
            self::uuid($record[$key] ?? null, $key);
        }
        foreach (['backup_receipt_id', 'owner_approval_id'] as $key) {
            if ($record[$key] !== null) {
                self::uuid($record[$key], $key);
            }
        }

        foreach (['capability', 'project', 'environment', 'resource', 'operation', 'issue', 'subject', 'idempotency_key'] as $key) {
            if (!is_string($record[$key])) {
                throw new InvalidArgumentException($key . ' inválido.');
            }
            self::plain($record[$key], $key);
        }

        if (preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $record['capability']) !== 1) {
            throw new InvalidArgumentException('capability inválida.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $record['project']) !== 1
            || preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $record['environment']) !== 1) {
            throw new InvalidArgumentException('scope inválido.');
        }
        if (preg_match('/^[a-z][a-z0-9._:-]{1,119}$/D', $record['resource']) !== 1
            || preg_match('/^[a-z][a-z0-9._:-]{1,79}$/D', $record['operation']) !== 1) {
            throw new InvalidArgumentException('resource/operation inválido.');
        }
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#[1-9][0-9]*$~D', $record['issue']) !== 1) {
            throw new InvalidArgumentException('issue inválido.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,119}$/D', $record['subject']) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,159}$/D', $record['idempotency_key']) !== 1) {
            throw new InvalidArgumentException('subject/idempotency inválido.');
        }

        self::timestampString($record['issued_at'] ?? null, 'issued_at');
        self::timestampString($record['expires_at'] ?? null, 'expires_at');
        if ($record['revoked_at'] !== null) {
            self::timestampString($record['revoked_at'], 'revoked_at');
        }

        $issued = self::timestamp($record['issued_at']);
        $expires = self::timestamp($record['expires_at']);
        if ($expires <= $issued || ($expires - $issued) > 3600) {
            throw new InvalidArgumentException('TTL inválido.');
        }

        return $record;
    }

    private static function scope(array $scope): void
    {
        $expected = ['capability', 'project', 'environment', 'resource', 'operation', 'issue', 'run_id', 'subject'];
        $keys = array_keys($scope);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Execution scope inválido.');
        }
        foreach ($expected as $key) {
            if (!is_string($scope[$key]) || trim($scope[$key]) === '' || str_contains($scope[$key], '*')) {
                throw new InvalidArgumentException('Execution scope inválido.');
            }
        }
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#[1-9][0-9]*$~D', $scope['issue']) !== 1) {
            throw new InvalidArgumentException('Execution issue inválido.');
        }
        self::uuid($scope['run_id'], 'run_id');
    }

    private static function plain(string $value, string $key): void
    {
        if ($value === '' || strlen($value) > 180 || str_contains($value, '*')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match('/(password|private[_ -]?key|token|dsn|cookie|authorization|bearer)/i', $value) === 1) {
            throw new InvalidArgumentException($key . ' contiene material no permitido.');
        }
    }

    private static function uuid(mixed $value, string $key): void
    {
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new InvalidArgumentException($key . ' inválido.');
        }
    }

    private static function timestampString(mixed $value, string $key): void
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1
            || self::timestamp($value) < 1) {
            throw new InvalidArgumentException($key . ' inválido.');
        }
    }

    private static function timestamp(string $value): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Timestamp inválido.');
        }
        return $date->getTimestamp();
    }

    private static function deny(string $reason): array
    {
        return ['authorized' => false, 'reason' => $reason];
    }
}

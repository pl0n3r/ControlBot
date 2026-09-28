<?php
declare(strict_types=1);

namespace ControlBot\Production;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SecretReference
{
    private const FIELDS = [
        'version', 'reference_id', 'capability', 'project', 'environment',
        'provider', 'secret_kind', 'generation', 'issued_at', 'revoked_at',
    ];

    private const KINDS = ['password', 'private_key', 'api_token', 'dsn', 'cookie'];

    private function __construct(private readonly array $record)
    {
    }

    public static function fromRecord(array $record): self
    {
        if (array_is_list($record)) {
            throw new InvalidArgumentException('SecretReference inválida.');
        }

        $keys = array_keys($record);
        $expected = self::FIELDS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected || ($record['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('SecretReference fields inválidos.');
        }

        self::uuid($record['reference_id'] ?? null, 'reference_id');
        self::capability($record['capability'] ?? null);
        self::slug($record['project'] ?? null, 'project', 64);
        self::slug($record['environment'] ?? null, 'environment', 32);
        self::slug($record['provider'] ?? null, 'provider', 64);

        if (!is_string($record['secret_kind'] ?? null)
            || !in_array($record['secret_kind'], self::KINDS, true)) {
            throw new InvalidArgumentException('secret_kind inválido.');
        }

        if (!is_int($record['generation'] ?? null)
            || $record['generation'] < 1
            || $record['generation'] > 1_000_000) {
            throw new InvalidArgumentException('generation inválida.');
        }

        self::timestampString($record['issued_at'] ?? null, 'issued_at');
        if ($record['revoked_at'] !== null) {
            self::timestampString($record['revoked_at'], 'revoked_at');
            if (self::timestamp($record['revoked_at']) < self::timestamp($record['issued_at'])) {
                throw new InvalidArgumentException('revoked_at inválido.');
            }
        }

        return new self($record);
    }

    public function record(): array
    {
        return $this->record;
    }

    public function referenceId(): string
    {
        return $this->record['reference_id'];
    }

    public function generation(): int
    {
        return $this->record['generation'];
    }

    public function capability(): string
    {
        return $this->record['capability'];
    }

    public function isRevoked(): bool
    {
        return $this->record['revoked_at'] !== null;
    }

    public function matchesContext(array $context): bool
    {
        self::context($context);
        foreach (['reference_id', 'capability', 'project', 'environment', 'generation'] as $key) {
            if ($context[$key] !== $this->record[$key]) {
                return false;
            }
        }
        return true;
    }

    public function revoke(string $revokedAt): self
    {
        self::timestampString($revokedAt, 'revoked_at');
        if (self::timestamp($revokedAt) < self::timestamp($this->record['issued_at'])) {
            throw new InvalidArgumentException('revoked_at inválido.');
        }
        $copy = $this->record;
        $copy['revoked_at'] = $revokedAt;
        return new self($copy);
    }

    public function rotate(string $newReferenceId, string $issuedAt): self
    {
        self::uuid($newReferenceId, 'reference_id');
        self::timestampString($issuedAt, 'issued_at');
        if (self::timestamp($issuedAt) <= self::timestamp($this->record['issued_at'])) {
            throw new InvalidArgumentException('issued_at de rotación inválido.');
        }
        $copy = $this->record;
        $copy['reference_id'] = $newReferenceId;
        $copy['generation']++;
        $copy['issued_at'] = $issuedAt;
        $copy['revoked_at'] = null;
        return new self($copy);
    }

    public function publicMetadata(): array
    {
        return [
            'version' => 1,
            'reference_id' => $this->record['reference_id'],
            'capability' => $this->record['capability'],
            'project' => $this->record['project'],
            'environment' => $this->record['environment'],
            'provider' => $this->record['provider'],
            'secret_kind' => $this->record['secret_kind'],
            'generation' => $this->record['generation'],
            'revoked' => $this->isRevoked(),
        ];
    }

    private static function context(array $context): void
    {
        $expected = ['executor_id', 'reference_id', 'capability', 'project', 'environment', 'generation'];
        $keys = array_keys($context);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Executor context inválido.');
        }

        self::slug($context['executor_id'] ?? null, 'executor_id', 80);
        self::uuid($context['reference_id'] ?? null, 'reference_id');
        self::capability($context['capability'] ?? null);
        self::slug($context['project'] ?? null, 'project', 64);
        self::slug($context['environment'] ?? null, 'environment', 32);
        if (!is_int($context['generation'] ?? null) || $context['generation'] < 1) {
            throw new InvalidArgumentException('generation de contexto inválida.');
        }
    }

    private static function uuid(mixed $value, string $key): void
    {
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new InvalidArgumentException($key . ' inválido.');
        }
    }

    private static function capability(mixed $value): void
    {
        if (!is_string($value)
            || strlen($value) > 120
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1) {
            throw new InvalidArgumentException('capability inválida.');
        }
    }

    private static function slug(mixed $value, string $key, int $max): void
    {
        if (!is_string($value)
            || strlen($value) > $max
            || preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $value) !== 1) {
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
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Timestamp inválido.');
        }
        return $date->getTimestamp();
    }
}

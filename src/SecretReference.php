<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;

final class SecretReference
{
    private const FIELDS = [
        'version', 'reference_id', 'capability', 'project', 'environment',
        'provider', 'secret_kind', 'generation', 'issued_at', 'revoked_at',
    ];

    private const CONTEXT_FIELDS = [
        'executor_id', 'reference_id', 'capability', 'project', 'environment', 'generation',
    ];

    private const KINDS = ['password', 'private_key', 'api_token', 'dsn', 'cookie'];

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';
    private const CAPABILITY_PATTERN = '/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D';
    private const SLUG_PATTERN = '/^[a-z][a-z0-9-]+$/D';
    private const UTC_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';

    private function __construct(private readonly array $record)
    {
    }

    public static function fromRecord(array $record): self
    {
        self::exactKeys($record, self::FIELDS, 'SecretReference');

        if (($record['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('SecretReference version inválida.');
        }

        self::matching($record['reference_id'] ?? null, 'reference_id', self::UUID_PATTERN, 36);
        self::matching($record['capability'] ?? null, 'capability', self::CAPABILITY_PATTERN, 120);
        self::matching($record['project'] ?? null, 'project', self::SLUG_PATTERN, 64);
        self::matching($record['environment'] ?? null, 'environment', self::SLUG_PATTERN, 32);
        self::matching($record['provider'] ?? null, 'provider', self::SLUG_PATTERN, 64);

        if (!is_string($record['secret_kind'] ?? null)
            || !in_array($record['secret_kind'], self::KINDS, true)) {
            throw new InvalidArgumentException('secret_kind inválido.');
        }

        if (!is_int($record['generation'] ?? null)
            || $record['generation'] < 1
            || $record['generation'] > 1_000_000) {
            throw new InvalidArgumentException('generation inválida.');
        }

        $issued = self::utcEpoch($record['issued_at'] ?? null, 'issued_at');
        if ($record['revoked_at'] !== null
            && self::utcEpoch($record['revoked_at'], 'revoked_at') < $issued) {
            throw new InvalidArgumentException('revoked_at inválido.');
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
        self::validateContext($context);

        foreach (['reference_id', 'capability', 'project', 'environment', 'generation'] as $field) {
            if ($context[$field] !== $this->record[$field]) {
                return false;
            }
        }

        return true;
    }

    public function revoke(string $revokedAt): self
    {
        if (self::utcEpoch($revokedAt, 'revoked_at') < self::utcEpoch($this->record['issued_at'], 'issued_at')) {
            throw new InvalidArgumentException('revoked_at inválido.');
        }

        $copy = $this->record;
        $copy['revoked_at'] = $revokedAt;
        return new self($copy);
    }

    public function rotate(string $newReferenceId, string $issuedAt): self
    {
        self::matching($newReferenceId, 'reference_id', self::UUID_PATTERN, 36);
        if (self::utcEpoch($issuedAt, 'issued_at') <= self::utcEpoch($this->record['issued_at'], 'issued_at')) {
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

    private static function validateContext(array $context): void
    {
        self::exactKeys($context, self::CONTEXT_FIELDS, 'Executor context');
        self::matching($context['executor_id'] ?? null, 'executor_id', self::SLUG_PATTERN, 80);
        self::matching($context['reference_id'] ?? null, 'reference_id', self::UUID_PATTERN, 36);
        self::matching($context['capability'] ?? null, 'capability', self::CAPABILITY_PATTERN, 120);
        self::matching($context['project'] ?? null, 'project', self::SLUG_PATTERN, 64);
        self::matching($context['environment'] ?? null, 'environment', self::SLUG_PATTERN, 32);

        if (!is_int($context['generation'] ?? null) || $context['generation'] < 1) {
            throw new InvalidArgumentException('generation de contexto inválida.');
        }
    }

    private static function exactKeys(array $value, array $expected, string $label): void
    {
        if (array_is_list($value)
            || count($value) !== count($expected)
            || array_diff($expected, array_keys($value)) !== []) {
            throw new InvalidArgumentException($label . ' fields inválidos.');
        }
    }

    private static function matching(
        mixed $value,
        string $field,
        string $pattern,
        int $maxLength,
    ): string {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > $maxLength
            || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException($field . ' inválido.');
        }

        return $value;
    }

    private static function utcEpoch(mixed $value, string $field): int
    {
        if (!is_string($value) || preg_match(self::UTC_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException($field . ' inválido.');
        }

        $epoch = strtotime($value);
        if ($epoch === false || gmdate('Y-m-d\TH:i:s\Z', $epoch) !== $value) {
            throw new InvalidArgumentException($field . ' inválido.');
        }

        return $epoch;
    }
}

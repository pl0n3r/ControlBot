<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;

final class OwnerAction
{
    private const FIELDS = [
        'version', 'capability', 'project', 'environment', 'resource', 'operation',
        'issue', 'title', 'summary', 'why', 'backup', 'risk', 'reversibility', 'scope',
    ];

    private function __construct(private readonly array $record)
    {
    }

    public static function fromRecord(array $record): self
    {
        if (array_is_list($record)) {
            throw new InvalidArgumentException('OwnerAction inválida.');
        }
        $keys = array_keys($record);
        $expected = self::FIELDS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected || ($record['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('OwnerAction fields inválidos.');
        }

        self::capability($record['capability'] ?? null);
        self::slug($record['project'] ?? null, 'project', 64);
        self::slug($record['environment'] ?? null, 'environment', 32);
        self::identifier($record['resource'] ?? null, 'resource', 120);
        self::identifier($record['operation'] ?? null, 'operation', 80);
        self::issue($record['issue'] ?? null);

        foreach (['title', 'summary', 'why', 'backup', 'risk', 'reversibility', 'scope'] as $field) {
            self::safeText($record[$field] ?? null, $field, $field === 'title' ? 120 : 600);
        }

        return new self($record);
    }

    public function safeRecord(): array
    {
        return $this->record;
    }

    private static function capability(mixed $value): void
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D', $value) !== 1) {
            throw new InvalidArgumentException('capability inválida.');
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

    private static function identifier(mixed $value, string $field, int $max): void
    {
        if (!is_string($value)
            || strlen($value) > $max
            || preg_match('/^[a-z][a-z0-9._:-]+$/D', $value) !== 1) {
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

    private static function safeText(mixed $value, string $field, int $max): void
    {
        if (!is_string($value)
            || trim($value) === ''
            || strlen($value) > $max
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
            || preg_match('/(?:password|secret|token|credential|cookie|authorization|dsn|private[_ -]?key)\s*[:=]/i', $value) === 1
            || preg_match('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', $value) === 1
            || stripos($value, '-----BEGIN PRIVATE KEY-----') !== false) {
            throw new InvalidArgumentException($field . ' contiene material no permitido.');
        }
    }
}

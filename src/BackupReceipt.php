<?php
declare(strict_types=1);

namespace ControlBot\Production;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class BackupReceipt
{
    private const FIELDS = [
        'version', 'receipt_id', 'project', 'environment', 'resource', 'backup_type',
        'run_id', 'issue', 'created_at', 'verified_at', 'status', 'artifact_ref',
        'integrity', 'restore_capability', 'expires_at',
    ];

    private const TYPES = [
        'database_dump',
        'config_snapshot',
        'cron_snapshot',
        'release_artifact_snapshot',
    ];

    private const RESTORE = ['manual', 'validated', 'unknown'];

    private function __construct(private readonly array $record)
    {
    }

    public static function fromRecord(array $record): self
    {
        if (array_is_list($record)) {
            throw new InvalidArgumentException('BackupReceipt inválido.');
        }

        $keys = array_keys($record);
        $expected = self::FIELDS;
        sort($keys);
        sort($expected);
        if ($keys !== $expected || ($record['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('BackupReceipt fields inválidos.');
        }

        self::uuid($record['receipt_id'] ?? null, 'receipt_id');
        self::uuid($record['run_id'] ?? null, 'run_id');
        self::slug($record['project'] ?? null, 'project', 64);
        self::slug($record['environment'] ?? null, 'environment', 32);
        self::resource($record['resource'] ?? null);

        if (!is_string($record['backup_type'] ?? null)
            || !in_array($record['backup_type'], self::TYPES, true)) {
            throw new InvalidArgumentException('backup_type inválido.');
        }
        if (!is_string($record['issue'] ?? null)
            || preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#[1-9][0-9]*$~D', $record['issue']) !== 1) {
            throw new InvalidArgumentException('issue inválido.');
        }

        self::timestampString($record['created_at'] ?? null, 'created_at');
        self::timestampString($record['verified_at'] ?? null, 'verified_at');
        if ($record['expires_at'] !== null) {
            self::timestampString($record['expires_at'], 'expires_at');
        }

        if (!is_string($record['status'] ?? null) || $record['status'] !== 'ready') {
            throw new InvalidArgumentException('BackupReceipt no ready.');
        }

        if (!is_string($record['artifact_ref'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,159}$/D', $record['artifact_ref']) !== 1
            || preg_match('~^(?:https?|ssh|s3)://~i', $record['artifact_ref']) === 1
            || self::containsSensitive($record['artifact_ref'])) {
            throw new InvalidArgumentException('artifact_ref inválido.');
        }

        self::integrity($record['integrity'] ?? null);

        if (!is_string($record['restore_capability'] ?? null)
            || !in_array($record['restore_capability'], self::RESTORE, true)) {
            throw new InvalidArgumentException('restore_capability inválido.');
        }

        $created = self::timestamp($record['created_at']);
        $verified = self::timestamp($record['verified_at']);
        if ($verified < $created) {
            throw new InvalidArgumentException('BackupReceipt verification inválida.');
        }
        if ($record['expires_at'] !== null && self::timestamp($record['expires_at']) <= $verified) {
            throw new InvalidArgumentException('BackupReceipt expiry inválido.');
        }

        return new self($record);
    }

    public function matches(array $scope): bool
    {
        self::scope($scope);
        foreach (['project', 'environment', 'resource', 'run_id', 'issue'] as $key) {
            if ($scope[$key] !== $this->record[$key]) {
                return false;
            }
        }
        return true;
    }

    public function isUsableBefore(int $writeAt): bool
    {
        if ($writeAt < 1) {
            throw new InvalidArgumentException('writeAt inválido.');
        }

        $verified = self::timestamp($this->record['verified_at']);
        if ($verified >= $writeAt) {
            return false;
        }
        if ($this->record['expires_at'] !== null
            && $writeAt >= self::timestamp($this->record['expires_at'])) {
            return false;
        }
        return true;
    }

    public function type(): string
    {
        return $this->record['backup_type'];
    }

    public function receiptId(): string
    {
        return $this->record['receipt_id'];
    }

    public function safeEvidence(): array
    {
        return [
            'version' => 1,
            'receipt_id' => $this->record['receipt_id'],
            'project' => $this->record['project'],
            'environment' => $this->record['environment'],
            'resource' => $this->record['resource'],
            'backup_type' => $this->record['backup_type'],
            'run_id' => $this->record['run_id'],
            'issue' => $this->record['issue'],
            'verified_at' => $this->record['verified_at'],
            'status' => 'ready',
            'restore_capability' => $this->record['restore_capability'],
            'expires_at' => $this->record['expires_at'],
        ];
    }

    private static function scope(array $scope): void
    {
        $expected = ['project', 'environment', 'resource', 'run_id', 'issue'];
        $keys = array_keys($scope);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Backup scope inválido.');
        }

        self::slug($scope['project'] ?? null, 'project', 64);
        self::slug($scope['environment'] ?? null, 'environment', 32);
        self::resource($scope['resource'] ?? null);
        self::uuid($scope['run_id'] ?? null, 'run_id');
        if (!is_string($scope['issue'] ?? null)
            || preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#[1-9][0-9]*$~D', $scope['issue']) !== 1) {
            throw new InvalidArgumentException('Backup scope issue inválido.');
        }
    }

    private static function integrity(mixed $value): void
    {
        if (!is_array($value) || array_is_list($value)
            || array_keys($value) !== ['algorithm', 'digest']
            || ($value['algorithm'] ?? null) !== 'sha256'
            || !is_string($value['digest'] ?? null)
            || strlen($value['digest']) < 16
            || strlen($value['digest']) > 160
            || preg_match('/^[A-Za-z0-9._:-]+$/D', $value['digest']) !== 1
            || self::containsSensitive($value['digest'])) {
            throw new InvalidArgumentException('integrity inválida.');
        }
    }

    private static function containsSensitive(string $value): bool
    {
        return preg_match('/(password|private[_ -]?key|token|bearer|authorization|cookie|dsn|dump[_ -]?payload|sql[_ -]?payload)/i', $value) === 1;
    }

    private static function uuid(mixed $value, string $key): void
    {
        if (!is_string($value)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) !== 1) {
            throw new InvalidArgumentException($key . ' inválido.');
        }
    }

    private static function slug(mixed $value, string $key, int $max): void
    {
        if (!is_string($value) || strlen($value) > $max
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException($key . ' inválido.');
        }
    }

    private static function resource(mixed $value): void
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9._:-]{1,119}$/D', $value) !== 1) {
            throw new InvalidArgumentException('resource inválido.');
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

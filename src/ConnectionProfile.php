<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;

final class ConnectionProfile
{
    private const STATUSES = [
        'unconfigured',
        'verifying',
        'connected',
        'degraded',
        'unavailable',
        'revoked',
    ];

    private function __construct(
        private readonly string $profileId,
        private readonly string $project,
        private readonly string $environment,
        private readonly string $host,
        private readonly int $port,
        private readonly string $usernameRef,
        private readonly string $secretRef,
        private readonly string $hostFingerprint,
        private readonly string $status,
        private readonly ?string $verifiedAt,
        private readonly ?string $lastHealthAt,
        private readonly ?string $revokedAt,
    ) {
        self::uuid($profileId);
        self::slug($project, 'Proyecto');
        self::slug($environment, 'Entorno');
        self::host($host);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Puerto de conexión inválido.');
        }
        self::reference($usernameRef, 'Referencia de usuario');
        self::reference($secretRef, 'Referencia de secreto');
        self::fingerprint($hostFingerprint);
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Estado de conexión inválido.');
        }
        self::timestamp($verifiedAt);
        self::timestamp($lastHealthAt);
        self::timestamp($revokedAt);
        if ($status === 'connected' && $verifiedAt === null) {
            throw new InvalidArgumentException('Perfil conectado sin verificación.');
        }
        if ($status === 'revoked' && $revokedAt === null) {
            throw new InvalidArgumentException('Perfil revocado sin fecha.');
        }
    }

    public static function bootstrap(
        string $profileId,
        string $project,
        string $environment,
        string $host,
        int $port,
        string $usernameRef,
        string $secretRef,
        string $hostFingerprint,
    ): self {
        return new self(
            $profileId,
            $project,
            $environment,
            $host,
            $port,
            $usernameRef,
            $secretRef,
            self::fingerprint($hostFingerprint),
            'verifying',
            null,
            null,
            null,
        );
    }

    public static function fromServerRecord(array $record): self
    {
        $expected = [
            'version',
            'profile_id',
            'project',
            'environment',
            'provider',
            'transport',
            'host',
            'port',
            'username_ref',
            'secret_ref',
            'host_fingerprint',
            'status',
            'verified_at',
            'last_health_at',
            'revoked_at',
        ];
        if (array_keys($record) !== $expected || $record['version'] !== 1) {
            throw new InvalidArgumentException('Registro de conexión inválido.');
        }
        if ($record['provider'] !== 'hostinger' || $record['transport'] !== 'ssh') {
            throw new InvalidArgumentException('Proveedor o transporte no permitido.');
        }
        if (!is_int($record['port'])) {
            throw new InvalidArgumentException('Puerto de conexión inválido.');
        }
        foreach ([
            'profile_id',
            'project',
            'environment',
            'host',
            'username_ref',
            'secret_ref',
            'host_fingerprint',
            'status',
        ] as $key) {
            if (!is_string($record[$key])) {
                throw new InvalidArgumentException('Registro de conexión inválido.');
            }
        }
        foreach (['verified_at', 'last_health_at', 'revoked_at'] as $key) {
            if ($record[$key] !== null && !is_string($record[$key])) {
                throw new InvalidArgumentException('Registro de conexión inválido.');
            }
        }

        return new self(
            $record['profile_id'],
            $record['project'],
            $record['environment'],
            $record['host'],
            $record['port'],
            $record['username_ref'],
            $record['secret_ref'],
            self::fingerprint($record['host_fingerprint']),
            $record['status'],
            $record['verified_at'],
            $record['last_health_at'],
            $record['revoked_at'],
        );
    }

    public function toServerRecord(): array
    {
        return [
            'version' => 1,
            'profile_id' => $this->profileId,
            'project' => $this->project,
            'environment' => $this->environment,
            'provider' => 'hostinger',
            'transport' => 'ssh',
            'host' => $this->host,
            'port' => $this->port,
            'username_ref' => $this->usernameRef,
            'secret_ref' => $this->secretRef,
            'host_fingerprint' => $this->hostFingerprint,
            'status' => $this->status,
            'verified_at' => $this->verifiedAt,
            'last_health_at' => $this->lastHealthAt,
            'revoked_at' => $this->revokedAt,
        ];
    }

    public function safeSnapshot(): array
    {
        return [
            'version' => 1,
            'profile_id' => $this->profileId,
            'project' => $this->project,
            'environment' => $this->environment,
            'provider' => 'hostinger',
            'transport' => 'ssh',
            'host' => $this->host,
            'port' => $this->port,
            'username_ref' => $this->usernameRef,
            'host_fingerprint' => $this->hostFingerprint,
            'status' => $this->status,
            'verified_at' => $this->verifiedAt,
            'last_health_at' => $this->lastHealthAt,
            'revoked_at' => $this->revokedAt,
        ];
    }

    public function probeRequest(): array
    {
        if (in_array($this->status, ['unconfigured', 'revoked'], true)) {
            throw new RuntimeException('Perfil de conexión no disponible.');
        }

        return [
            'operation' => 'ssh.identity.probe',
            'effect' => 'read',
            'profile_id' => $this->profileId,
            'project' => $this->project,
            'environment' => $this->environment,
            'provider' => 'hostinger',
            'transport' => 'ssh',
            'host' => $this->host,
            'port' => $this->port,
            'username_ref' => $this->usernameRef,
            'expected_fingerprint' => $this->hostFingerprint,
        ];
    }

    public function matchesFingerprint(string $observed): bool
    {
        try {
            return hash_equals($this->hostFingerprint, self::fingerprint($observed));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function markConnected(int $now): self
    {
        $this->assertNotRevoked();
        $timestamp = self::now($now);
        return $this->copy(
            status: 'connected',
            verifiedAt: $timestamp,
            lastHealthAt: $timestamp,
            revokedAt: null,
        );
    }

    public function markUnavailable(int $now): self
    {
        $this->assertNotRevoked();
        return $this->copy(
            status: 'unavailable',
            verifiedAt: null,
            lastHealthAt: self::now($now),
            revokedAt: null,
        );
    }

    public function markDegraded(int $now): self
    {
        $this->assertNotRevoked();
        return $this->copy(
            status: 'degraded',
            verifiedAt: $this->verifiedAt,
            lastHealthAt: self::now($now),
            revokedAt: null,
        );
    }

    public function revoke(int $now): self
    {
        return $this->copy(
            status: 'revoked',
            verifiedAt: null,
            lastHealthAt: $this->lastHealthAt,
            revokedAt: self::now($now),
        );
    }

    public function rotateSecretRef(string $secretRef): self
    {
        $this->assertNotRevoked();
        self::reference($secretRef, 'Referencia de secreto');
        return new self(
            $this->profileId,
            $this->project,
            $this->environment,
            $this->host,
            $this->port,
            $this->usernameRef,
            $secretRef,
            $this->hostFingerprint,
            'verifying',
            null,
            null,
            null,
        );
    }

    public function authorityScope(): string
    {
        return implode(':', [$this->project, $this->environment, 'hostinger']);
    }

    public function allowsReadOnlyDiagnosis(): bool
    {
        return in_array($this->status, ['connected', 'degraded'], true);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function destination(): string
    {
        return $this->host . ':' . $this->port;
    }

    private function copy(
        string $status,
        ?string $verifiedAt,
        ?string $lastHealthAt,
        ?string $revokedAt,
    ): self {
        return new self(
            $this->profileId,
            $this->project,
            $this->environment,
            $this->host,
            $this->port,
            $this->usernameRef,
            $this->secretRef,
            $this->hostFingerprint,
            $status,
            $verifiedAt,
            $lastHealthAt,
            $revokedAt,
        );
    }

    private function assertNotRevoked(): void
    {
        if ($this->status === 'revoked') {
            throw new RuntimeException('Perfil de conexión revocado.');
        }
    }

    private static function uuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new InvalidArgumentException('ID de perfil inválido.');
        }
    }

    private static function slug(string $value, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $value) !== 1) {
            throw new InvalidArgumentException($label . ' inválido.');
        }
    }

    private static function host(string $value): void
    {
        if ($value === '' || strlen($value) > 253 || preg_match('/[\s\/@?#]/', $value) === 1) {
            throw new InvalidArgumentException('Host inválido.');
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return;
        }
        foreach (explode('.', $value) as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $label) !== 1
            ) {
                throw new InvalidArgumentException('Host inválido.');
            }
        }
    }

    private static function reference(string $value, string $label): void
    {
        if (
            $value === ''
            || strlen($value) > 160
            || preg_match('/^[A-Za-z0-9._:@\/-]+$/', $value) !== 1
        ) {
            throw new InvalidArgumentException($label . ' inválida.');
        }
    }

    private static function fingerprint(string $value): string
    {
        if (preg_match('/^sha256:([A-Za-z0-9+\/]{16,88}={0,2})$/i', $value, $match) !== 1) {
            throw new InvalidArgumentException('Fingerprint SSH inválido.');
        }
        return 'SHA256:' . $match[1];
    }

    private static function timestamp(?string $value): void
    {
        if ($value === null) {
            return;
        }
        $parsed = date_create_immutable($value);
        if ($parsed === false || $parsed->format(DATE_ATOM) !== $value) {
            throw new InvalidArgumentException('Timestamp de conexión inválido.');
        }
    }

    private static function now(int $timestamp): string
    {
        if ($timestamp < 0) {
            throw new InvalidArgumentException('Timestamp de conexión inválido.');
        }
        return gmdate(DATE_ATOM, $timestamp);
    }
}

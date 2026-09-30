<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ConnectionIdentityBroker
{
    private const META = [
        'version', 'username_ref', 'provider', 'project', 'environment',
        'generation', 'issued_at', 'revoked_at',
    ];
    private const CONTEXT = [
        'executor_id', 'username_ref', 'provider', 'project', 'environment', 'generation',
    ];

    /** @var array<string,array{metadata:array<string,mixed>,username:string}> */
    private array $entries = [];
    /** @var array<string,true> */
    private array $executors = [];

    /** @param list<string> $authorizedExecutors */
    public function __construct(array $authorizedExecutors)
    {
        if (!array_is_list($authorizedExecutors) || $authorizedExecutors === []) {
            throw new InvalidArgumentException('Executors autorizados requeridos.');
        }
        foreach ($authorizedExecutors as $executor) {
            self::executorId($executor);
            $this->executors[$executor] = true;
        }
    }

    public function register(array $metadata, string $username): void
    {
        self::metadata($metadata);
        self::username($username);
        $ref = $metadata['username_ref'];
        if (isset($this->entries[$ref])) {
            throw new RuntimeException('Identity reference ya registrada.');
        }
        $this->entries[$ref] = ['metadata' => $metadata, 'username' => $username];
    }

    public function execute(array $context, callable $executor): array
    {
        if (!self::context($context)) {
            return self::deny('identity_context_invalid');
        }
        if (!isset($this->executors[$context['executor_id']])) {
            return self::deny('executor_not_authorized');
        }
        $ref = $context['username_ref'];
        if (!isset($this->entries[$ref])) {
            return self::deny('identity_reference_unknown');
        }

        $entry = $this->entries[$ref];
        if ($entry['metadata']['revoked_at'] !== null) {
            return self::deny('identity_reference_revoked');
        }
        foreach (['provider', 'project', 'environment', 'generation'] as $field) {
            if ($context[$field] !== $entry['metadata'][$field]) {
                return self::deny('identity_scope_mismatch');
            }
        }

        try {
            $raw = $executor($entry['username'], $entry['metadata']);
            return [
                'ok' => true, 'reason' => 'executed',
                'result' => self::sanitize($raw, $entry['username']), 'username' => null,
            ];
        } catch (Throwable $error) {
            return [
                'ok' => false, 'reason' => 'executor_failed',
                'error' => self::sanitize($error->getMessage(), $entry['username']),
                'result' => null, 'username' => null,
            ];
        }
    }

    public function revoke(string $referenceId, string $revokedAt): void
    {
        $entry = &$this->entry($referenceId);
        if ($entry['metadata']['revoked_at'] !== null) {
            throw new RuntimeException('Identity reference ya revocada.');
        }
        if (self::epoch($revokedAt) < self::epoch($entry['metadata']['issued_at'])) {
            throw new InvalidArgumentException('Revocación anterior a emisión.');
        }
        $entry['metadata']['revoked_at'] = $revokedAt;
    }

    public function rotate(
        string $referenceId,
        string $newReferenceId,
        string $issuedAt,
        string $newUsername,
    ): array {
        $entry = &$this->entry($referenceId);
        if ($entry['metadata']['revoked_at'] !== null) {
            throw new RuntimeException('Identity reference revocada no puede rotarse.');
        }
        if (isset($this->entries[$newReferenceId])) {
            throw new RuntimeException('Identity reference destino ya existe.');
        }
        self::referenceId($newReferenceId);
        self::username($newUsername);
        if (self::epoch($issuedAt) <= self::epoch($entry['metadata']['issued_at'])) {
            throw new InvalidArgumentException('Rotación no posterior a emisión.');
        }

        $entry['metadata']['revoked_at'] = $issuedAt;
        $new = $entry['metadata'];
        $new['username_ref'] = $newReferenceId;
        $new['generation']++;
        $new['issued_at'] = $issuedAt;
        $new['revoked_at'] = null;
        $this->entries[$newReferenceId] = ['metadata' => $new, 'username' => $newUsername];
        return $new;
    }

    public function agentSurface(string $referenceId): array
    {
        $entry = $this->entry($referenceId);
        return ['identity' => $entry['metadata'], 'username' => null, 'resolvable' => false];
    }

    private function &entry(string $referenceId): array
    {
        if (!isset($this->entries[$referenceId])) {
            throw new RuntimeException('Identity reference desconocida.');
        }
        return $this->entries[$referenceId];
    }

    private static function metadata(array $value): void
    {
        if (array_is_list($value) || count($value) !== count(self::META)
            || array_diff(self::META, array_keys($value)) !== []
            || array_diff(array_keys($value), self::META) !== []
            || ($value['version'] ?? null) !== 1
            || !is_int($value['generation'] ?? null) || $value['generation'] < 1
            || !is_string($value['issued_at'] ?? null)
            || (($value['revoked_at'] ?? null) !== null && !is_string($value['revoked_at']))) {
            throw new InvalidArgumentException('Identity metadata inválida.');
        }
        self::referenceId($value['username_ref']);
        self::slug($value['provider']);
        self::slug($value['project']);
        self::slug($value['environment']);
        $issued = self::epoch($value['issued_at']);
        if ($value['revoked_at'] !== null && self::epoch($value['revoked_at']) < $issued) {
            throw new InvalidArgumentException('Identity revocation inválida.');
        }
    }

    private static function context(array $value): bool
    {
        if (array_is_list($value) || count($value) !== count(self::CONTEXT)
            || array_diff(self::CONTEXT, array_keys($value)) !== []
            || array_diff(array_keys($value), self::CONTEXT) !== []
            || !is_int($value['generation'] ?? null) || $value['generation'] < 1) {
            return false;
        }
        try {
            self::executorId($value['executor_id'] ?? null);
            self::referenceId($value['username_ref'] ?? null);
            self::slug($value['provider'] ?? null);
            self::slug($value['project'] ?? null);
            self::slug($value['environment'] ?? null);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function sanitize(mixed $value, string $username): mixed
    {
        if (is_string($value)) {
            return substr(str_replace($username, '[REDACTED]', $value), 0, 2_000);
        }
        if (is_array($value)) {
            $out = [];
            foreach (array_slice($value, 0, 50, true) as $key => $item) {
                $safeKey = is_string($key)
                    ? substr(str_replace($username, '[REDACTED]', $key), 0, 80)
                    : $key;
                $out[$safeKey] = self::sanitize($item, $username);
            }
            return $out;
        }
        return is_scalar($value) || $value === null ? $value : '[REDACTED]';
    }

    private static function deny(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'result' => null, 'username' => null];
    }

    private static function executorId(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Executor inválido.');
        }
    }

    private static function referenceId(mixed $value): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > 160
            || preg_match('/^[A-Za-z0-9._:@\/-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('username_ref inválida.');
        }
    }

    private static function slug(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Identity scope inválido.');
        }
    }

    private static function username(string $value): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Username inválido.');
        }
    }

    private static function epoch(string $value): int
    {
        $parsed = date_create_immutable($value);
        if ($parsed === false || $parsed->format(DATE_ATOM) !== $value) {
            throw new InvalidArgumentException('Timestamp inválido.');
        }
        return $parsed->getTimestamp();
    }
}

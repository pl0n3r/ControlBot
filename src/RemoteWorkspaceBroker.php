<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RemoteWorkspaceBroker
{
    private const META_FIELDS = [
        'version','workspace_ref','provider','project','environment',
        'generation','issued_at','revoked_at',
    ];
    private const CONTEXT_FIELDS = [
        'executor_id','workspace_ref','provider','project','environment','generation',
    ];
    private const SCOPE_FIELDS = ['provider','project','environment','generation'];

    /** @var array<string,array{metadata:array<string,mixed>,path:string}> */
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
            $this->executors[self::validatedExecutor($executor)] = true;
        }
    }

    public function register(array $metadata, string $path): void
    {
        self::validateMetadata($metadata);
        self::validatePath($path);
        $ref = $metadata['workspace_ref'];
        if (isset($this->entries[$ref])) {
            throw new RuntimeException('Workspace reference ya registrada.');
        }
        $this->entries[$ref] = ['metadata'=>$metadata,'path'=>$path];
    }

    public function execute(array $context, callable $executor): array
    {
        $reason = self::validateContext($context);
        if ($reason !== null) {
            return self::deny($reason);
        }
        if (!isset($this->executors[$context['executor_id']])) {
            return self::deny('executor_not_authorized');
        }

        $entry = $this->entries[$context['workspace_ref']] ?? null;
        if ($entry === null) {
            return self::deny('workspace_reference_unknown');
        }
        if ($entry['metadata']['revoked_at'] !== null) {
            return self::deny('workspace_reference_revoked');
        }
        if (!self::sameScope($context, $entry['metadata'])) {
            return self::deny('workspace_scope_mismatch');
        }

        try {
            $raw = $executor($entry['path'], $entry['metadata']);
            return self::result(true, 'executed', self::sanitize($raw, $entry['path']));
        } catch (Throwable $error) {
            return self::result(
                false,
                'executor_failed',
                null,
                self::sanitize($error->getMessage(), $entry['path']),
            );
        }
    }

    public function revoke(string $referenceId, string $revokedAt): void
    {
        $entry = &$this->requiredEntry($referenceId);
        if ($entry['metadata']['revoked_at'] !== null) {
            throw new RuntimeException('Workspace reference ya revocada.');
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
        string $newPath,
    ): array {
        $entry = &$this->requiredEntry($referenceId);
        if ($entry['metadata']['revoked_at'] !== null) {
            throw new RuntimeException('Workspace reference revocada no puede rotarse.');
        }
        if (isset($this->entries[$newReferenceId])) {
            throw new RuntimeException('Workspace reference destino ya existe.');
        }

        self::validateReference($newReferenceId);
        self::validatePath($newPath);
        if (self::epoch($issuedAt) <= self::epoch($entry['metadata']['issued_at'])) {
            throw new InvalidArgumentException('Rotación no posterior a emisión.');
        }

        $entry['metadata']['revoked_at'] = $issuedAt;
        $next = $entry['metadata'];
        $next['workspace_ref'] = $newReferenceId;
        $next['generation']++;
        $next['issued_at'] = $issuedAt;
        $next['revoked_at'] = null;
        $this->entries[$newReferenceId] = ['metadata'=>$next,'path'=>$newPath];
        return $next;
    }

    public function agentSurface(string $referenceId): array
    {
        $entry = $this->requiredEntry($referenceId);
        return ['workspace'=>$entry['metadata'],'path'=>null,'resolvable'=>false];
    }

    private function &requiredEntry(string $referenceId): array
    {
        if (!isset($this->entries[$referenceId])) {
            throw new RuntimeException('Workspace reference desconocida.');
        }
        return $this->entries[$referenceId];
    }

    private static function validateMetadata(array $value): void
    {
        if (!self::hasExactKeys($value, self::META_FIELDS)
            || ($value['version'] ?? null) !== 1
            || !is_int($value['generation'] ?? null)
            || $value['generation'] < 1
            || !is_string($value['issued_at'] ?? null)
            || (($value['revoked_at'] ?? null) !== null && !is_string($value['revoked_at']))) {
            throw new InvalidArgumentException('Workspace metadata inválida.');
        }

        self::validateReference($value['workspace_ref']);
        foreach (['provider','project','environment'] as $field) {
            self::validateSlug($value[$field]);
        }
        $issued = self::epoch($value['issued_at']);
        if ($value['revoked_at'] !== null && self::epoch($value['revoked_at']) < $issued) {
            throw new InvalidArgumentException('Workspace revocation inválida.');
        }
    }

    private static function validateContext(array $value): ?string
    {
        if (!self::hasExactKeys($value, self::CONTEXT_FIELDS)
            || !is_int($value['generation'] ?? null)
            || $value['generation'] < 1) {
            return 'workspace_context_invalid';
        }

        try {
            self::validatedExecutor($value['executor_id']);
            self::validateReference($value['workspace_ref']);
            foreach (['provider','project','environment'] as $field) {
                self::validateSlug($value[$field]);
            }
        } catch (InvalidArgumentException) {
            return 'workspace_context_invalid';
        }
        return null;
    }

    private static function hasExactKeys(array $value, array $fields): bool
    {
        return !array_is_list($value)
            && count($value) === count($fields)
            && array_diff($fields, array_keys($value)) === []
            && array_diff(array_keys($value), $fields) === [];
    }

    private static function sameScope(array $context, array $metadata): bool
    {
        foreach (self::SCOPE_FIELDS as $field) {
            if ($context[$field] !== $metadata[$field]) {
                return false;
            }
        }
        return true;
    }

    private static function validatePath(string $value): void
    {
        if ($value === '' || strlen($value) > 512 || $value[0] !== '/'
            || str_contains($value, '\\') || str_contains($value, '//')
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Workspace path inválido.');
        }

        $parts = explode('/', substr($value, 1));
        if ($parts === [] || in_array('', $parts, true)) {
            throw new InvalidArgumentException('Workspace path inválido.');
        }
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..'
                || preg_match('/^[A-Za-z0-9._-]+$/D', $part) !== 1) {
                throw new InvalidArgumentException('Workspace path inválido.');
            }
        }
        if ('/'.implode('/', $parts) !== $value) {
            throw new InvalidArgumentException('Workspace path inválido.');
        }
    }

    private static function sanitize(mixed $value, string $path): mixed
    {
        if (is_string($value)) {
            return substr(str_replace($path, '[REDACTED]', $value), 0, 2000);
        }
        if (!is_array($value)) {
            return is_scalar($value) || $value === null ? $value : '[REDACTED]';
        }

        $safe = [];
        foreach (array_slice($value, 0, 50, true) as $key=>$item) {
            $safeKey = is_string($key)
                ? substr(str_replace($path, '[REDACTED]', $key), 0, 80)
                : $key;
            $safe[$safeKey] = self::sanitize($item, $path);
        }
        return $safe;
    }

    private static function result(
        bool $ok,
        string $reason,
        mixed $result,
        mixed $error = null,
    ): array {
        $out = ['ok'=>$ok,'reason'=>$reason,'result'=>$result,'path'=>null];
        if (!$ok && $reason === 'executor_failed') {
            $out['error'] = $error;
        }
        return $out;
    }

    private static function deny(string $reason): array
    {
        return self::result(false, $reason, null);
    }

    private static function validatedExecutor(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Executor inválido.');
        }
        return $value;
    }

    private static function validateReference(mixed $value): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > 160
            || preg_match('/^[A-Za-z0-9._:@\/-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('workspace_ref inválida.');
        }
    }

    private static function validateSlug(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Workspace scope inválido.');
        }
    }

    private static function epoch(string $value): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException('Timestamp inválido.');
        }
        $epoch = strtotime($value);
        if ($epoch === false || gmdate('Y-m-d\\TH:i:s\\Z', $epoch) !== $value) {
            throw new InvalidArgumentException('Timestamp inválido.');
        }
        return $epoch;
    }
}

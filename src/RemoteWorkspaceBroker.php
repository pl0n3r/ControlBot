<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RemoteWorkspaceBroker
{
    private const META = [
        'version','workspace_ref','provider','project','environment',
        'generation','issued_at','revoked_at',
    ];
    private const CONTEXT = [
        'executor_id','workspace_ref','provider','project','environment','generation',
    ];

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
            self::executorId($executor);
            $this->executors[$executor] = true;
        }
    }

    public function register(array $metadata, string $path): void
    {
        self::metadata($metadata);
        self::path($path);
        $ref = $metadata['workspace_ref'];
        if (isset($this->entries[$ref])) {
            throw new RuntimeException('Workspace reference ya registrada.');
        }
        $this->entries[$ref] = ['metadata'=>$metadata,'path'=>$path];
    }

    public function execute(array $context, callable $executor): array
    {
        if (!self::context($context)) return self::deny('workspace_context_invalid');
        if (!isset($this->executors[$context['executor_id']])) {
            return self::deny('executor_not_authorized');
        }
        $ref = $context['workspace_ref'];
        if (!isset($this->entries[$ref])) return self::deny('workspace_reference_unknown');

        $entry = $this->entries[$ref];
        if ($entry['metadata']['revoked_at'] !== null) {
            return self::deny('workspace_reference_revoked');
        }
        foreach (['provider','project','environment','generation'] as $field) {
            if ($context[$field] !== $entry['metadata'][$field]) {
                return self::deny('workspace_scope_mismatch');
            }
        }

        try {
            $raw = $executor($entry['path'], $entry['metadata']);
            return [
                'ok'=>true,'reason'=>'executed',
                'result'=>self::sanitize($raw,$entry['path']),'path'=>null,
            ];
        } catch (Throwable $error) {
            return [
                'ok'=>false,'reason'=>'executor_failed',
                'error'=>self::sanitize($error->getMessage(),$entry['path']),
                'result'=>null,'path'=>null,
            ];
        }
    }

    public function revoke(string $referenceId, string $revokedAt): void
    {
        $entry = &$this->entry($referenceId);
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
        $entry = &$this->entry($referenceId);
        if ($entry['metadata']['revoked_at'] !== null) {
            throw new RuntimeException('Workspace reference revocada no puede rotarse.');
        }
        if (isset($this->entries[$newReferenceId])) {
            throw new RuntimeException('Workspace reference destino ya existe.');
        }
        self::referenceId($newReferenceId);
        self::path($newPath);
        if (self::epoch($issuedAt) <= self::epoch($entry['metadata']['issued_at'])) {
            throw new InvalidArgumentException('Rotación no posterior a emisión.');
        }

        $entry['metadata']['revoked_at'] = $issuedAt;
        $new = $entry['metadata'];
        $new['workspace_ref'] = $newReferenceId;
        $new['generation']++;
        $new['issued_at'] = $issuedAt;
        $new['revoked_at'] = null;
        $this->entries[$newReferenceId] = ['metadata'=>$new,'path'=>$newPath];
        return $new;
    }

    public function agentSurface(string $referenceId): array
    {
        $entry = $this->entry($referenceId);
        return ['workspace'=>$entry['metadata'],'path'=>null,'resolvable'=>false];
    }

    private function &entry(string $referenceId): array
    {
        if (!isset($this->entries[$referenceId])) {
            throw new RuntimeException('Workspace reference desconocida.');
        }
        return $this->entries[$referenceId];
    }

    private static function metadata(array $value): void
    {
        if (array_is_list($value) || count($value)!==count(self::META)
            || array_diff(self::META,array_keys($value))!==[]
            || array_diff(array_keys($value),self::META)!==[]
            || ($value['version']??null)!==1
            || !is_int($value['generation']??null) || $value['generation']<1
            || !is_string($value['issued_at']??null)
            || (($value['revoked_at']??null)!==null && !is_string($value['revoked_at']))) {
            throw new InvalidArgumentException('Workspace metadata inválida.');
        }
        self::referenceId($value['workspace_ref']);
        self::slug($value['provider']);
        self::slug($value['project']);
        self::slug($value['environment']);
        $issued=self::epoch($value['issued_at']);
        if ($value['revoked_at']!==null && self::epoch($value['revoked_at'])<$issued) {
            throw new InvalidArgumentException('Workspace revocation inválida.');
        }
    }

    private static function context(array $value): bool
    {
        if (array_is_list($value) || count($value)!==count(self::CONTEXT)
            || array_diff(self::CONTEXT,array_keys($value))!==[]
            || array_diff(array_keys($value),self::CONTEXT)!==[]
            || !is_int($value['generation']??null) || $value['generation']<1) return false;
        try {
            self::executorId($value['executor_id']??null);
            self::referenceId($value['workspace_ref']??null);
            self::slug($value['provider']??null);
            self::slug($value['project']??null);
            self::slug($value['environment']??null);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function path(string $value): void
    {
        if ($value==='' || strlen($value)>512 || $value[0]!=='/'
            || str_contains($value,'\\') || str_contains($value,'//')
            || preg_match('/[\x00-\x1f\x7f]/',$value)===1) {
            throw new InvalidArgumentException('Workspace path inválido.');
        }
        $parts=explode('/',substr($value,1));
        if ($parts===[] || in_array('', $parts, true)) {
            throw new InvalidArgumentException('Workspace path inválido.');
        }
        foreach ($parts as $part) {
            if ($part==='.' || $part==='..'
                || preg_match('/^[A-Za-z0-9._-]+$/D',$part)!==1) {
                throw new InvalidArgumentException('Workspace path inválido.');
            }
        }
        if ('/'.implode('/',$parts)!==$value) {
            throw new InvalidArgumentException('Workspace path inválido.');
        }
    }

    private static function sanitize(mixed $value,string $path): mixed
    {
        if (is_string($value)) return substr(str_replace($path,'[REDACTED]',$value),0,2000);
        if (is_array($value)) {
            $out=[];
            foreach (array_slice($value,0,50,true) as $key=>$item) {
                $safeKey=is_string($key)
                    ? substr(str_replace($path,'[REDACTED]',$key),0,80)
                    : $key;
                $out[$safeKey]=self::sanitize($item,$path);
            }
            return $out;
        }
        return is_scalar($value) || $value===null ? $value : '[REDACTED]';
    }

    private static function deny(string $reason): array
    {
        return ['ok'=>false,'reason'=>$reason,'result'=>null,'path'=>null];
    }

    private static function executorId(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{1,79}$/D',$value)!==1) {
            throw new InvalidArgumentException('Executor inválido.');
        }
    }

    private static function referenceId(mixed $value): void
    {
        if (!is_string($value) || $value==='' || strlen($value)>160
            || preg_match('/^[A-Za-z0-9._:@\/-]+$/D',$value)!==1) {
            throw new InvalidArgumentException('workspace_ref inválida.');
        }
    }

    private static function slug(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$value)!==1) {
            throw new InvalidArgumentException('Workspace scope inválido.');
        }
    }

    private static function epoch(string $value): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value)!==1) {
            throw new InvalidArgumentException('Timestamp inválido.');
        }
        $epoch=strtotime($value);
        if ($epoch===false || gmdate('Y-m-d\\TH:i:s\\Z',$epoch)!==$value) {
            throw new InvalidArgumentException('Timestamp inválido.');
        }
        return $epoch;
    }
}

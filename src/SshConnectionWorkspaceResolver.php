<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use Throwable;

final class SshConnectionWorkspaceResolver
{
    private const EXECUTOR='hostinger-executor';
    private const INPUT=['workspace_ref','provider','project','environment'];

    public function __construct(private RemoteWorkspaceBroker $broker) {}

    public function resolve(array $input, callable $consumer): array
    {
        if (!self::exact($input,self::INPUT)) return self::deny('workspace_context_invalid');
        try {
            self::ref($input['workspace_ref']);
            foreach (['provider','project','environment'] as $field) self::slug($input[$field]);
            $surface=$this->broker->agentSurface($input['workspace_ref']);
            $meta=self::metadata($surface);
        } catch (Throwable) {
            return self::deny('workspace_reference_unknown');
        }

        if (($meta['revoked_at']??null)!==null) return self::deny('workspace_reference_revoked');
        foreach (['workspace_ref','provider','project','environment'] as $field)
            if ($meta[$field]!==$input[$field]) return self::deny('workspace_scope_mismatch');

        $context=[
            'executor_id'=>self::EXECUTOR,'workspace_ref'=>$input['workspace_ref'],
            'provider'=>$input['provider'],'project'=>$input['project'],
            'environment'=>$input['environment'],'generation'=>$meta['generation'],
        ];
        $realPath=null;
        $result=$this->broker->execute($context,function(string $path,array $live) use ($consumer,$meta,&$realPath): mixed {
            if (!self::same($live,$meta)) throw new InvalidArgumentException('workspace generation changed');
            $realPath=$path;
            return $consumer($path);
        });
        if (($result['ok']??false)!==true) return self::fromBroker($result);

        try {
            $after=self::metadata($this->broker->agentSurface($input['workspace_ref']));
            if (!self::same($after,$meta) || ($after['revoked_at']??null)!==null)
                return self::deny('workspace_generation_changed');
        } catch (Throwable) {
            return self::deny('workspace_generation_changed');
        }

        $out=['ok'=>true,'reason'=>'resolved','result'=>$result['result']??null,'path'=>null];
        if (is_string($realPath) && str_contains(json_encode($out,JSON_THROW_ON_ERROR),$realPath))
            return self::deny('workspace_path_leak');
        return $out;
    }

    private static function metadata(array $surface): array
    {
        if (!self::exact($surface,['workspace','path','resolvable'])
            || $surface['path']!==null || $surface['resolvable']!==false
            || !is_array($surface['workspace']) || array_is_list($surface['workspace'])
            || !is_int($surface['workspace']['generation']??null)
            || $surface['workspace']['generation']<1) {
            throw new InvalidArgumentException('workspace metadata invalid');
        }
        return $surface['workspace'];
    }

    private static function same(array $a,array $b): bool
    {
        foreach (['workspace_ref','provider','project','environment','generation'] as $field)
            if (($a[$field]??null)!==($b[$field]??null)) return false;
        return true;
    }

    private static function fromBroker(array $result): array
    {
        $out=[
            'ok'=>false,'reason'=>is_string($result['reason']??null)?$result['reason']:'broker_denied',
            'result'=>null,'path'=>null,
        ];
        if (array_key_exists('error',$result)) $out['error']=$result['error'];
        return $out;
    }

    private static function deny(string $reason): array
    { return ['ok'=>false,'reason'=>$reason,'result'=>null,'path'=>null]; }

    private static function exact(array $value,array $keys): bool
    {
        if (array_is_list($value)) return false;
        $actual=array_keys($value);sort($actual);sort($keys);
        return $actual===$keys;
    }

    private static function ref(mixed $value): void
    {
        if (!is_string($value)||$value===''||strlen($value)>160
            ||preg_match('/^[A-Za-z0-9._:@\/-]+$/D',$value)!==1)
            throw new InvalidArgumentException('workspace_ref invalid');
    }

    private static function slug(mixed $value): void
    {
        if (!is_string($value)||preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$value)!==1)
            throw new InvalidArgumentException('scope invalid');
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use InvalidArgumentException;

interface ExternalApiSessionSource
{
    /**
     * Resolve current server-side authentication state for opaque references.
     *
     * Implementations are authoritative server-side adapters. They must never
     * treat client-supplied state arrays as canonical evidence.
     */
    public function resolve(
        string $identityId,
        string $scope,
        string $deviceRef,
        string $sessionRef,
        ?string $stepUpRef,
        int $now,
    ): array;
}

final class ExternalApiSessionSourceContract
{
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|public[_ -]?key|api[_ -]?key|otp|dsn)/i';

    /**
     * Normalize the lookup keys passed to an ExternalApiSessionSource.
     */
    public static function query(
        string $identityId,
        string $scope,
        string $deviceRef,
        string $sessionRef,
        ?string $stepUpRef,
        int $now,
    ): array {
        if($now<1) throw new InvalidArgumentException('now invalid.');
        return [
            'identity_id'=>self::identity($identityId),
            'scope'=>self::scope($scope),
            'device_ref'=>self::opaque($deviceRef,'device_ref','device'),
            'session_ref'=>self::opaque($sessionRef,'session_ref','session'),
            'step_up_ref'=>$stepUpRef===null?null:self::opaque($stepUpRef,'step_up_ref','stepup'),
            'now'=>$now,
        ];
    }

    /**
     * Validate and normalize one authoritative source response.
     */
    public static function resolved(array $raw,array $query): array
    {
        self::fields($raw,[
            'version','device','session','step_up','observed_at','freshness','source_ref',
        ],'ResolvedExternalApiSession');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('ResolvedExternalApiSession version invalid.');

        $query=self::query(
            $query['identity_id']??'',
            $query['scope']??'',
            $query['device_ref']??'',
            $query['session_ref']??'',
            $query['step_up_ref']??null,
            $query['now']??0,
        );

        $observed=self::positive($raw['observed_at'],'observed_at');
        if($observed>$query['now']) throw new InvalidArgumentException('observed_at invalid.');
        $freshness=self::oneOf($raw['freshness'],['fresh','stale','unknown'],'freshness');
        if($freshness!=='fresh') throw new InvalidArgumentException('session source freshness unusable.');

        if(!is_array($raw['device'])||!is_array($raw['session']))
            throw new InvalidArgumentException('session source records invalid.');

        $session=ExternalApiSession::session(
            $raw['session'],
            $raw['device'],
            $query['identity_id'],
            $query['scope'],
            $query['now'],
        );
        $device=ExternalApiSession::device($raw['device'],$query['identity_id']);

        if(!hash_equals($query['device_ref'],$device['device_ref'])
            ||!hash_equals($query['session_ref'],$session['session_ref']))
            throw new InvalidArgumentException('session source reference mismatch.');

        $step=null;
        if($query['step_up_ref']===null){
            if($raw['step_up']!==null)
                throw new InvalidArgumentException('unexpected step-up evidence.');
        }else{
            if(!is_array($raw['step_up']))
                throw new InvalidArgumentException('step-up evidence missing.');
            $step=ExternalApiSession::stepUp(
                $raw['step_up'],
                $raw['session'],
                $raw['device'],
                $query['identity_id'],
                $query['scope'],
                $query['now'],
            );
            if(!hash_equals($query['step_up_ref'],$step['step_up_ref']))
                throw new InvalidArgumentException('step-up reference mismatch.');
        }

        $out=[
            'version'=>1,
            'identity_id'=>$query['identity_id'],
            'scope'=>$query['scope'],
            'device'=>$device,
            'session'=>$session,
            'step_up'=>$step,
            'observed_at'=>$observed,
            'freshness'=>$freshness,
            'source_ref'=>self::sourceRef($raw['source_ref']),
        ];
        self::secretFree($out);
        return $out;
    }

    private static function sourceRef(mixed $value): string
    {
        if(!is_string($value)
            ||preg_match('#^controlbot:session-source/[a-z][a-z0-9._/-]{1,119}$#D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('source_ref invalid.');
        return $value;
    }

    private static function opaque(mixed $value,string $label,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function identity(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException('identity_id invalid.');
        return $value;
    }

    private static function scope(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException('scope invalid.');
        return $value;
    }

    private static function oneOf(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function positive(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(array $row,array $expected,string $label): void
    {
        if(array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item) self::secretFree($item);return;}
        if(is_string($value)&&preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('session source contains sensitive material.');
    }
}

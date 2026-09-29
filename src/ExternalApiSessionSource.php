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
        if($now<1
            ||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$identityId)!==1
            ||preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D',$scope)!==1)
            throw new InvalidArgumentException('session source query invalid.');

        $refs=[
            'device_ref'=>[$deviceRef,'device'],
            'session_ref'=>[$sessionRef,'session'],
        ];
        if($stepUpRef!==null) $refs['step_up_ref']=[$stepUpRef,'stepup'];
        foreach($refs as $label=>[$value,$namespace]){
            if(preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
                throw new InvalidArgumentException($label.' invalid.');
        }

        return [
            'identity_id'=>$identityId,
            'scope'=>$scope,
            'device_ref'=>$deviceRef,
            'session_ref'=>$sessionRef,
            'step_up_ref'=>$stepUpRef,
            'now'=>$now,
        ];
    }

    /**
     * Validate and normalize one authoritative source response.
     */
    public static function resolved(array $raw,array $query): array
    {
        $expected=['version','device','session','step_up','observed_at','freshness','source_ref'];
        $actual=array_keys($raw);
        sort($actual,SORT_STRING);
        sort($expected,SORT_STRING);
        if(array_is_list($raw)||$actual!==$expected||($raw['version']??null)!==1)
            throw new InvalidArgumentException('ResolvedExternalApiSession invalid.');

        $query=self::query(
            $query['identity_id']??'',
            $query['scope']??'',
            $query['device_ref']??'',
            $query['session_ref']??'',
            $query['step_up_ref']??null,
            $query['now']??0,
        );

        $observed=$raw['observed_at'];
        if(!is_int($observed)||$observed<1||$observed>$query['now'])
            throw new InvalidArgumentException('observed_at invalid.');
        if($raw['freshness']!=='fresh')
            throw new InvalidArgumentException('session source freshness unusable.');
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

        if($observed<$device['registered_at']
            ||$observed<$session['issued_at']
            ||($step!==null&&$observed<$step['verified_at']))
            throw new InvalidArgumentException('observed_at predates authenticated evidence.');

        $sourceRef=$raw['source_ref'];
        if(!is_string($sourceRef)
            ||preg_match('#^controlbot:session-source/[a-z][a-z0-9._/-]{1,119}$#D',$sourceRef)!==1
            ||preg_match(self::SENSITIVE,$sourceRef)===1)
            throw new InvalidArgumentException('source_ref invalid.');

        $out=[
            'version'=>1,
            'identity_id'=>$query['identity_id'],
            'scope'=>$query['scope'],
            'device'=>$device,
            'session'=>$session,
            'step_up'=>$step,
            'observed_at'=>$observed,
            'freshness'=>'fresh',
            'source_ref'=>$sourceRef,
        ];
        self::secretFree($out);
        return $out;
    }

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item) self::secretFree($item);return;}
        if(is_string($value)&&preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('session source contains sensitive material.');
    }
}

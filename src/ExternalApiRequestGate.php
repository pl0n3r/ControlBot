<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class ExternalApiRequestGate
{
    public static function authorize(
        VerifiedAccessContext $context,
        string $method,
        string $pathTemplate,
        string $expectedScope,
        array $deviceRaw,
        array $sessionRaw,
        ?array $stepUpRaw,
        int $now,
    ): array {
        if($now<1) throw new InvalidArgumentException('now invalid.');
        $summary=$context->safeSummary();
        $identity=$summary['identity_id']??null;
        if(!is_string($identity)||$identity==='') throw new InvalidArgumentException('verified identity invalid.');

        $session=ExternalApiSession::session(
            $sessionRaw,$deviceRaw,$identity,$expectedScope,$now
        );
        $base=ExternalApiAccess::authorize(
            $context,$method,$pathTemplate,$expectedScope,$now
        );

        $decision=$base['decision']??null;
        if($decision==='deny') return self::result($base,$session,null);
        if($decision==='allow'){
            if($stepUpRaw!==null) throw new InvalidArgumentException('step-up not expected.');
            return self::result($base,$session,null);
        }
        if($decision!=='step_up_required')
            throw new InvalidArgumentException('authorization decision invalid.');
        if($stepUpRaw===null) return self::result($base,$session,null);

        $step=ExternalApiSession::stepUp(
            $stepUpRaw,$sessionRaw,$deviceRaw,$identity,$expectedScope,$now
        );
        $base['decision']='allow';
        $base['reasons']=['authorized_with_step_up'];
        return self::result($base,$session,$step);
    }

    private static function result(array $base,array $session,?array $step): array
    {
        foreach(['decision','reasons','operation_id','capability','scope','mutation'] as $field){
            if(!array_key_exists($field,$base))
                throw new InvalidArgumentException('authorization result invalid.');
        }
        return [
            'decision'=>$base['decision'],
            'reasons'=>$base['reasons'],
            'operation_id'=>$base['operation_id'],
            'capability'=>$base['capability'],
            'scope'=>$base['scope'],
            'mutation'=>$base['mutation'],
            'session_ref'=>$session['session_ref'],
            'device_ref'=>$session['device_ref'],
            'step_up_ref'=>$step['step_up_ref']??null,
        ];
    }
}

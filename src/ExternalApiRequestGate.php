<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class ExternalApiRequestGate
{
    /** Authorize one request using server-verified access and session contexts. */
    public static function authorize(
        VerifiedAccessContext $context,
        string $method,
        string $pathTemplate,
        string $expectedScope,
        VerifiedExternalSessionContext $authentication,
        int $now,
    ): array {
        if($now<1) throw new InvalidArgumentException('now invalid.');
        $access=$context->safeSummary();
        $auth=$authentication->safeSummary();
        $identity=$access['identity_id']??null;
        $accessScope=$access['scope']??null;
        $authIdentity=$auth['identity_id']??null;
        $authScope=$auth['scope']??null;
        if(!is_string($identity)||!is_string($accessScope)
            ||!is_string($authIdentity)||!is_string($authScope)
            ||!hash_equals($identity,$authIdentity)
            ||!hash_equals($accessScope,$authScope)
            ||!hash_equals($expectedScope,$authScope))
            throw new InvalidArgumentException('verified authentication context mismatch.');

        $device=$authentication->device();
        $sessionRaw=$authentication->session();
        $session=ExternalApiSession::session($sessionRaw,$device,$identity,$expectedScope,$now);
        $base=ExternalApiAccess::authorize($context,$method,$pathTemplate,$expectedScope,$now);
        $stepRaw=$authentication->stepUp();
        $step=$stepRaw===null?null:ExternalApiSession::stepUp(
            $stepRaw,$sessionRaw,$device,$identity,$expectedScope,$now
        );

        $decision=$base['decision']??null;
        if($decision==='deny'||$decision==='allow') return self::result($base,$session,$step);
        if($decision!=='step_up_required')
            throw new InvalidArgumentException('authorization decision invalid.');

        if($step===null) return self::result($base,$session,null);
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
            'decision'=>$base['decision'],'reasons'=>$base['reasons'],
            'operation_id'=>$base['operation_id'],'capability'=>$base['capability'],
            'scope'=>$base['scope'],'mutation'=>$base['mutation'],
            'session_ref'=>$session['session_ref'],'device_ref'=>$session['device_ref'],
            'step_up_ref'=>$step['step_up_ref']??null,
        ];
    }
}

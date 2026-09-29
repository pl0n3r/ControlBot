<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class ExternalApiMobileAudit
{
    private const OUTCOMES=[
        'read_served','mutation_accepted','mutation_rejected',
        'factory_handoff','verification_succeeded','verification_failed',
    ];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|api[_ -]?key|otp|dsn|ip[_ -]?address|user[_ -]?agent|fingerprint)/i';

    public static function event(
        VerifiedAccessContext $access,
        VerifiedExternalSessionContext $authentication,
        string $method,
        string $pathTemplate,
        string $expectedScope,
        array $requestIds,
        int $occurredAt,
        string $outcome,
        array $refs,
    ): array {
        if($occurredAt<1) throw new InvalidArgumentException('occurred_at invalid.');
        self::fields($refs,['decision_ref','approval_ref','work_item_ref','result_ref'],'AuditRefs');
        $outcome=self::oneOf($outcome,self::OUTCOMES,'outcome');
        $ids=ExternalApiContract::requestIds($requestIds);

        $authorization=ExternalApiRequestGate::authorize(
            $access,$method,$pathTemplate,$expectedScope,$authentication,$occurredAt
        );
        if(($authorization['decision']??null)!=='allow')
            throw new InvalidArgumentException('audit outcome requires authorized request.');

        $accessSummary=$access->safeSummary();
        $authSummary=$authentication->safeSummary();
        if(($accessSummary['identity_id']??null)!==($authSummary['identity_id']??null)
            ||($accessSummary['scope']??null)!==($authSummary['scope']??null)
            ||($authorization['scope']??null)!==($accessSummary['scope']??null))
            throw new InvalidArgumentException('audit verified context mismatch.');

        $mutation=($authorization['mutation']??null)===true;
        if($outcome==='read_served' && $mutation)
            throw new InvalidArgumentException('read_served requires read operation.');
        if($outcome!=='read_served' && !$mutation)
            throw new InvalidArgumentException('mutation outcome requires mutation operation.');

        $decision=self::nullableRef($refs['decision_ref'],'decision_ref');
        $approval=self::nullableRef($refs['approval_ref'],'approval_ref');
        $workItem=self::nullableRef($refs['work_item_ref'],'work_item_ref');
        $result=self::nullableRef($refs['result_ref'],'result_ref');
        if(in_array($outcome,['mutation_accepted','mutation_rejected'],true)&&$decision===null)
            throw new InvalidArgumentException('decision outcome requires decision_ref.');
        if($outcome==='factory_handoff'&&$workItem===null)
            throw new InvalidArgumentException('factory_handoff requires work_item_ref.');
        if(str_starts_with($outcome,'verification_')&&$result===null)
            throw new InvalidArgumentException('verification outcome requires result_ref.');

        $identity=self::slug($accessSummary['identity_id']??null,'identity_id');
        $grant=self::slug($accessSummary['grant_id']??null,'grant_id');
        $capability=self::capability($accessSummary['capability']??null);
        $scope=self::scope($accessSummary['scope']??null);
        $authority=self::oneOf(
            $accessSummary['authority_level']??null,
            ['L0_AI_AUTONOMOUS','L1_OPERATOR','L2_VENTURE_ADMIN','L3_GROUP_INSTITUTION','L4_OWNER'],
            'authority_level'
        );
        $policies=self::policyRefs($accessSummary['policy_refs']??null);
        $device=self::opaque($authSummary['device_ref']??null,'device_ref','device');
        $session=self::opaque($authSummary['session_ref']??null,'session_ref','session');
        $step=$authSummary['step_up_ref']===null?null:self::opaque($authSummary['step_up_ref'],'step_up_ref','stepup');

        $record=[
            'version'=>1,
            'audit_ref'=>'controlbot:audit/mobile/'.self::safeDigest(
                $ids['request_id'].'|'.($authorization['operation_id']??'').'|'.$occurredAt
            ),
            'request_id'=>$ids['request_id'],'correlation_id'=>$ids['correlation_id'],
            'occurred_at'=>$occurredAt,'identity_id'=>$identity,'authority_level'=>$authority,
            'policy_refs'=>$policies,'grant_id'=>$grant,'capability'=>$capability,'scope'=>$scope,
            'operation_id'=>self::operationId($authorization['operation_id']??null),
            'authorization_decision'=>'allow',
            'authorization_reasons'=>self::reasons($authorization['reasons']??null),
            'mutation'=>$mutation,'device_ref'=>$device,'session_ref'=>$session,'step_up_ref'=>$step,
            'outcome'=>$outcome,'decision_ref'=>$decision,'approval_ref'=>$approval,
            'work_item_ref'=>$workItem,'result_ref'=>$result,
        ];
        self::secretFree($record);
        return $record;
    }

    private static function policyRefs(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>16)
            throw new InvalidArgumentException('policy_refs invalid.');
        $out=[];
        foreach($raw as $value){
            $ref=self::ref($value,'policy_ref');
            if(in_array($ref,$out,true)) throw new InvalidArgumentException('policy_refs duplicated.');
            $out[]=$ref;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function reasons(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>16)
            throw new InvalidArgumentException('authorization_reasons invalid.');
        $out=[];
        foreach($raw as $value){
            if(!is_string($value)||preg_match('/^[a-z][a-z0-9_]{1,79}$/D',$value)!==1)
                throw new InvalidArgumentException('authorization reason invalid.');
            $out[$value]=true;
        }
        $out=array_keys($out);sort($out,SORT_STRING);return $out;
    }

    private static function nullableRef(mixed $value,string $label): ?string
    {
        return $value===null?null:self::ref($value,$label);
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)>180
            ||preg_match('#^controlbot:[a-z][a-z0-9._/-]{1,159}$#D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function opaque(mixed $value,string $label,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function capability(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{2,119}$/D',$value)!==1)
            throw new InvalidArgumentException('capability invalid.');
        return $value;
    }

    private static function scope(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException('scope invalid.');
        return $value;
    }

    private static function operationId(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D',$value)!==1)
            throw new InvalidArgumentException('operation_id invalid.');
        return $value;
    }

    private static function oneOf(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function safeDigest(string $value): string
    {
        return implode('x',str_split(hash('sha256',$value),4));
    }

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item) self::secretFree($item);return;}
        if(is_string($value)&&preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('audit contains sensitive material.');
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

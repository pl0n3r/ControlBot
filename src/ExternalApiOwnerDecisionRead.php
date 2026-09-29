<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class ExternalApiOwnerDecisionRead
{
    private const SENSITIVE='/(?:\b(?:password|passwd|secret|token|cookie|authorization|bearer|credential|otp|dsn)\b|private[_ -]?key|public[_ -]?key|api[_ -]?key|user[_ -]?id|customer[_ -]?id)/i';
    private const DIRECT_PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+\d{1,3}(?:[ .()\-]?\d){7,14}|\(\d{2,3}\)[ .\-]?\d{3,4}[ .\-]?\d{4}|\b\d{3}[ .\-]\d{3}[ .\-]\d{4}\b)/i';

    public static function detail(
        VerifiedAccessContext $access,
        VerifiedExternalSessionContext $authentication,
        string $expectedScope,
        string $requestedDecisionId,
        array $meta,
        array $detail,
        int $now,
    ): array {
        $authorization=ExternalApiRequestGate::authorize(
            $access,'GET','/api/v1/owner-decisions/{decision_id}',$expectedScope,$authentication,$now
        );
        self::assertAuthorization($authorization,$expectedScope);
        $requestedDecisionId=self::slug($requestedDecisionId,'decision_id');
        self::exactObject($meta,['request_id','correlation_id','generated_at','freshness'],'ResponseMetaInput');
        $detail=self::exactObject(
            $detail,
            ['decision_id','category','title','question','options','state','deadline_at'],
            'OwnerDecisionData'
        );

        $decisionId=self::slug($detail['decision_id'],'decision_id');
        if(!hash_equals($requestedDecisionId,$decisionId))
            throw new InvalidArgumentException('Requested decision does not match server detail.');

        return [
            'meta'=>ExternalApiContract::responseMeta(['version'=>1]+$meta),
            'data'=>[
                'decision_id'=>$decisionId,
                'category'=>self::slug($detail['category'],'category'),
                'title'=>self::text($detail['title'],'title',160),
                'question'=>self::text($detail['question'],'question',1000),
                'options'=>self::options($detail['options']),
                'state'=>self::state($detail['state']),
                'deadline_at'=>self::deadline($detail['deadline_at']),
            ],
        ];
    }

    private static function assertAuthorization(array $authorization,string $expectedScope): void
    {
        $operation=ExternalApiContract::operation('GET','/api/v1/owner-decisions/{decision_id}');
        $valid=($authorization['decision']??null)==='allow'
            &&($authorization['operation_id']??null)===$operation['operation_id']
            &&($authorization['capability']??null)===$operation['auth_scope']
            &&($authorization['mutation']??null)===$operation['mutation']
            &&($authorization['scope']??null)===$expectedScope;
        if(!$valid) throw new InvalidArgumentException('Owner decision read not authorized.');
    }

    private static function options(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)<2||count($raw)>4)
            throw new InvalidArgumentException('options invalid.');
        $out=[];$seen=[];
        foreach($raw as $row){
            $row=self::exactObject($row,['key','label'],'DecisionOption');
            $key=$row['key'];
            if(!is_string($key)||preg_match('/^[A-D]$/D',$key)!==1||isset($seen[$key]))
                throw new InvalidArgumentException('option key invalid.');
            $seen[$key]=true;
            $out[]=['key'=>$key,'label'=>self::text($row['label'],'option.label',240)];
        }
        return $out;
    }

    private static function slug(mixed $value,string $label): string
    {
        $valid=is_string($value)&&strlen($value)>=2&&strlen($value)<=64
            &&preg_match('/^[a-z][a-z0-9-]*$/D',$value)===1;
        if(!$valid) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $label,int $max): string
    {
        if(!is_string($value) || !mb_check_encoding($value,'UTF-8'))
            throw new InvalidArgumentException($label.' invalid.');
        $clean=trim($value);
        if($clean===''||mb_strlen($clean,'UTF-8')>$max||preg_match('/[<>\x00-\x1f\x7f]/u',$clean)===1
            ||preg_match(self::SENSITIVE,$clean)===1||preg_match(self::DIRECT_PII,$clean)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $clean;
    }

    private static function state(mixed $value): string
    {
        if(!is_string($value)) throw new InvalidArgumentException('state invalid.');
        return match($value){
            'pending','resolved','expired','blocked'=>$value,
            default=>throw new InvalidArgumentException('state invalid.'),
        };
    }

    private static function deadline(mixed $value): ?int
    {
        if($value===null) return null;
        if(is_int($value)&&$value>0) return $value;
        throw new InvalidArgumentException('deadline_at invalid.');
    }

    private static function exactObject(mixed $value,array $keys,string $label): array
    {
        if(!is_array($value)||array_is_list($value)||count($value)!==count($keys))
            throw new InvalidArgumentException($label.' invalid.');
        foreach($keys as $key)
            if(!array_key_exists($key,$value)) throw new InvalidArgumentException($label.' fields invalid.');
        return $value;
    }
}

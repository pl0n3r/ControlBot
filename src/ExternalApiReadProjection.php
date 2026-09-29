<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use InvalidArgumentException;

final class ExternalApiReadProjection
{
    private const STATES=['current','stale','unknown'];
    private const KINDS=['FYI','WATCH','DECISION','CRITICAL'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|public[_ -]?key|api[_ -]?key|otp|dsn|user[_ -]?agent|ip[_ -]?address)/i';

    public static function cockpit(array $meta,array $ventures): array
    {
        self::readOperation('/api/v1/cockpit','cockpit.read');
        return [
            'meta'=>self::meta($meta),
            'data'=>['ventures'=>self::ventures($ventures)],
        ];
    }

    public static function ownerInbox(array $meta,array $entries): array
    {
        self::readOperation('/api/v1/owner-inbox','owner_inbox.read');
        return [
            'meta'=>self::meta($meta),
            'data'=>['entries'=>self::entries($entries)],
        ];
    }

    private static function meta(array $raw): array
    {
        self::fields($raw,['request_id','correlation_id','generated_at','freshness'],'ResponseMetaInput');
        return ExternalApiContract::responseMeta([
            'version'=>1,
            'request_id'=>$raw['request_id'],
            'correlation_id'=>$raw['correlation_id'],
            'generated_at'=>$raw['generated_at'],
            'freshness'=>$raw['freshness'],
        ]);
    }

    private static function readOperation(string $path,string $expectedId): void
    {
        $operation=ExternalApiContract::operation('GET',$path);
        if(($operation['operation_id']??null)!==$expectedId||($operation['mutation']??true)!==false)
            throw new InvalidArgumentException('Public read operation invalid.');
    }

    private static function ventures(array $rows): array
    {
        if(!array_is_list($rows)||count($rows)>100)
            throw new InvalidArgumentException('ventures invalid.');
        $out=[];
        foreach($rows as $row){
            self::fields($row,['venture_ref','business_state','technical_state','pending_decisions','critical_events'],'CockpitVentureSummary');
            $out[]=[
                'venture_ref'=>self::controlbotRef($row['venture_ref'],'venture_ref'),
                'business_state'=>self::enumValue($row['business_state'],self::STATES,'business_state'),
                'technical_state'=>self::enumValue($row['technical_state'],self::STATES,'technical_state'),
                'pending_decisions'=>self::nonNegativeInt($row['pending_decisions'],'pending_decisions'),
                'critical_events'=>self::nonNegativeInt($row['critical_events'],'critical_events'),
            ];
        }
        return $out;
    }

    private static function entries(array $rows): array
    {
        if(!array_is_list($rows)||count($rows)>200)
            throw new InvalidArgumentException('entries invalid.');
        $out=[];
        foreach($rows as $row){
            self::fields($row,['entry_id','kind','venture_ref','title','summary','decision_id','deadline_at'],'OwnerInboxEntry');
            $out[]=[
                'entry_id'=>self::slug($row['entry_id'],'entry_id'),
                'kind'=>self::enumValue($row['kind'],self::KINDS,'kind'),
                'venture_ref'=>$row['venture_ref']===null?null:self::controlbotRef($row['venture_ref'],'venture_ref'),
                'title'=>self::text($row['title'],'title',160),
                'summary'=>self::text($row['summary'],'summary',500),
                'decision_id'=>$row['decision_id']===null?null:self::slug($row['decision_id'],'decision_id'),
                'deadline_at'=>$row['deadline_at']===null?null:self::positiveInt($row['deadline_at'],'deadline_at'),
            ];
        }
        return $out;
    }

    private static function controlbotRef(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match(self::SENSITIVE,$value)===1
            ||preg_match('#^controlbot:[a-z][a-z0-9._/-]{1,119}$#D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $label,int $max): string
    {
        $clean=is_string($value)?trim($value):'';
        $count=preg_match_all('/./us',$clean,$characters);
        if($clean===''||$count===false||$count>$max||preg_match('/[\x00-\x1f\x7f]/',$clean)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $clean;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function enumValue(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function positiveInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nonNegativeInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)
            ||array_fill_keys(array_keys($row),true)!=array_fill_keys($expected,true))
            throw new InvalidArgumentException($label.' fields invalid.');
    }
}

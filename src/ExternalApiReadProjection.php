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
        return [
            'meta'=>self::meta($meta),
            'data'=>['ventures'=>self::ventures($ventures)],
        ];
    }

    public static function ownerInbox(array $meta,array $entries): array
    {
        return [
            'meta'=>self::meta($meta),
            'data'=>['entries'=>self::entries($entries)],
        ];
    }

    private static function meta(array $raw): array
    {
        self::fields($raw,['request_id','correlation_id','generated_at','freshness'],'ResponseMetaInput');
        $freshness=self::freshness($raw['freshness']);
        return [
            'version'=>1,
            'request_id'=>self::hexId($raw['request_id'],'request_id'),
            'correlation_id'=>self::hexId($raw['correlation_id'],'correlation_id'),
            'generated_at'=>self::positiveInt($raw['generated_at'],'generated_at'),
            'freshness'=>$freshness,
        ];
    }

    private static function freshness(mixed $raw): array
    {
        self::fields($raw,['state','observed_at','source_ref'],'Freshness');
        $state=self::enumValue($raw['state'],self::STATES,'freshness.state');
        if($state==='unknown'){
            if($raw['observed_at']!==null||$raw['source_ref']!==null)
                throw new InvalidArgumentException('unknown freshness provenance invalid.');
            return ['state'=>'unknown','observed_at'=>null,'source_ref'=>null];
        }
        return [
            'state'=>$state,
            'observed_at'=>self::positiveInt($raw['observed_at'],'observed_at'),
            'source_ref'=>self::controlbotRef($raw['source_ref'],'source_ref'),
        ];
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
        if(!is_string($value)||$value===''||strlen($value)>$max||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function hexId(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
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

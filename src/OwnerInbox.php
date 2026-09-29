<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class OwnerInbox
{
    private const CLASSES=['fyi','watch','decision','critical'];
    private const FRESHNESS=['current','stale','unknown'];
    private const AUTHORITY=['L0_AI_AUTONOMOUS','L1_OPERATOR','L2_VENTURE_ADMIN','L3_GROUP_INSTITUTION','L4_OWNER'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn|email|phone|user[_ -]?id|customer[_ -]?id)/i';

    public static function entry(array $raw): array
    {
        self::fields($raw,[
            'version','entry_ref','class','scope','title','summary','impact',
            'actor_ref','required_authority_level','decision_ref','options_ref','deadline_at',
            'source_ref','evidence_refs','observed_at','freshness',
        ],'OwnerInboxEntry');
        if($raw['version']!==1) throw new InvalidArgumentException('OwnerInboxEntry version invalid.');

        $class=self::oneOf($raw['class'],self::CLASSES,'class');
        $freshness=self::oneOf($raw['freshness'],self::FRESHNESS,'freshness');
        $source=self::nullableRef($raw['source_ref'],'source_ref');
        $observed=self::nullableTimestamp($raw['observed_at'],'observed_at');
        $evidence=self::refs($raw['evidence_refs'],'evidence_refs');

        if($freshness==='unknown' && ($source!==null||$observed!==null||$evidence!==[]))
            throw new InvalidArgumentException('Unknown freshness cannot carry observed provenance.');
        if($freshness!=='unknown' && ($source===null||$observed===null))
            throw new InvalidArgumentException('Observed freshness requires provenance.');

        $authority=self::nullableAuthority($raw['required_authority_level']);
        $decision=self::nullableRef($raw['decision_ref'],'decision_ref');
        $options=self::nullableRef($raw['options_ref'],'options_ref');
        $deadline=self::nullableTimestamp($raw['deadline_at'],'deadline_at');

        if(in_array($class,['fyi','watch'],true)
            && ($authority!==null||$decision!==null||$options!==null||$deadline!==null))
            throw new InvalidArgumentException('Informational classes cannot carry decision authority.');
        if($class==='decision' && ($authority===null||$decision===null))
            throw new InvalidArgumentException('Decision entry requires authority and decision_ref.');
        if($class==='critical' && $authority===null)
            throw new InvalidArgumentException('Critical entry requires authority.');

        return [
            'version'=>1,
            'entry_ref'=>self::ref($raw['entry_ref'],'entry_ref'),
            'class'=>$class,
            'scope'=>self::scope($raw['scope']),
            'title'=>self::text($raw['title'],'title',120),
            'summary'=>self::text($raw['summary'],'summary',480),
            'impact'=>self::text($raw['impact'],'impact',320),
            'actor_ref'=>self::nullableRef($raw['actor_ref'],'actor_ref'),
            'required_authority_level'=>$authority,
            'decision_ref'=>$decision,
            'options_ref'=>$options,
            'deadline_at'=>$deadline,
            'source_ref'=>$source,
            'evidence_refs'=>$evidence,
            'observed_at'=>$observed,
            'freshness'=>$freshness,
        ];
    }

    public static function collection(array $raw): array
    {
        if(!array_is_list($raw)||count($raw)>100) throw new InvalidArgumentException('OwnerInbox collection invalid.');
        $out=[];$seen=[];
        foreach($raw as $row){
            if(!is_array($row)) throw new InvalidArgumentException('OwnerInbox entry invalid.');
            $entry=self::entry($row);
            if(isset($seen[$entry['entry_ref']])) throw new InvalidArgumentException('OwnerInbox entry duplicated.');
            $seen[$entry['entry_ref']]=true;
            $out[]=$entry;
        }
        $weight=['critical'=>0,'decision'=>1,'watch'=>2,'fyi'=>3];
        usort($out,static function(array $a,array $b) use($weight): int {
            $class=$weight[$a['class']]<=>$weight[$b['class']];
            if($class!==0) return $class;
            $ad=$a['deadline_at']??PHP_INT_MAX;
            $bd=$b['deadline_at']??PHP_INT_MAX;
            return ($ad<=>$bd) ?: ($a['entry_ref']<=>$b['entry_ref']);
        });
        return ['version'=>1,'entries'=>$out];
    }

    private static function scope(mixed $raw): array
    {
        self::fields($raw,['kind','ref'],'scope');
        $kind=self::oneOf($raw['kind'],['group','venture','project','institution'],'scope.kind');
        $ref=self::ref($raw['ref'],'scope.ref');
        if(!str_starts_with($ref,'controlbot:'.$kind.'/'))
            throw new InvalidArgumentException('scope mismatch.');
        return ['kind'=>$kind,'ref'=>$ref];
    }

    private static function refs(mixed $raw,string $label): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>16)
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($raw as $value){
            $ref=self::ref($value,$label);
            if(in_array($ref,$out,true)) throw new InvalidArgumentException($label.' duplicated.');
            $out[]=$ref;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function nullableAuthority(mixed $value): ?string
    {
        return $value===null?null:self::oneOf($value,self::AUTHORITY,'required_authority_level');
    }

    private static function nullableRef(mixed $value,string $label): ?string
    {
        return $value===null?null:self::ref($value,$label);
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<3||strlen($value)>180||str_contains($value,'@')
            ||preg_match('/^controlbot:[a-z][a-z0-9._\/-]{1,159}$/D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $label,int $max): string
    {
        if(!is_string($value)) throw new InvalidArgumentException($label.' invalid.');
        $value=trim($value);
        if($value===''||mb_strlen($value)>$max||preg_match('/[<>\x00-\x1f\x7f]/u',$value)===1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableTimestamp(mixed $value,string $label): ?int
    {
        if($value===null) return null;
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function oneOf(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

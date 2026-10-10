<?php
declare(strict_types=1);

namespace ControlBot\Business;

require_once __DIR__.'/WorkInventorySnapshot.php';
require_once __DIR__.'/FactoryAgentActivity.php';

use InvalidArgumentException;

final class FactoryLiveSnapshot
{
    private const AUTHORITIES=[
        'batches'=>'factory_plan','owner_decisions'=>'owner_inbox',
        'releases'=>'github_project_snapshot','blockers'=>'github_project_snapshot',
        'production'=>'observability_project_status','quality'=>'quality_health',
        'work'=>'github_project_snapshot','learning'=>'incident_lesson',
    ];
    private const STATES=['healthy','degraded','critical','unknown','blocked','pending'];
    private const FRESH=['current','stale','unknown'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';
    private const LIMIT=50;

    public static function build(array $raw,int $now): array
    {
        if($now<1||array_is_list($raw))throw new InvalidArgumentException('Factory live input invalid.');
        $allowed=[...array_keys(self::AUTHORITIES),'tool_usage','work_inventory','agent_activity','signal_summary'];
        foreach(array_keys($raw) as $key)
            if(!is_string($key)||!in_array($key,$allowed,true))
                throw new InvalidArgumentException('Factory live field invalid.');

        $sections=[];
        foreach(self::AUTHORITIES as $section=>$authority)
            $sections[$section]=self::section($raw[$section]??null,$section,$authority,$now);

        $tool=$raw['tool_usage']??null;
        $tool=$tool===null
            ?self::unknown('tool_usage','tool_usage')
            :self::signal($tool,'tool_usage','tool_usage',$now);

        $canonical=['version'=>1,'observed_at'=>$now,'sections'=>$sections,'tool_usage'=>$tool];
        if(array_key_exists('work_inventory',$raw))
            $canonical['work_inventory']=self::workInventory($raw['work_inventory'],$now);
        if(array_key_exists('agent_activity',$raw))
            $canonical['agent_activity']=FactoryAgentActivity::build($raw['agent_activity'],$now);
        if(array_key_exists('signal_summary',$raw))
            $canonical['signal_summary']=self::signalSummary($raw['signal_summary']);
        return $canonical+['fingerprint'=>hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
    }

    private static function signalSummary(mixed $raw): array
    {
        self::fields($raw,['truncated','omitted','reason'],'signal_summary');
        self::fields($raw['omitted'],['blockers','owner_decisions','work'],'signal_summary.omitted');
        if(!is_bool($raw['truncated']))throw new InvalidArgumentException('signal_summary.truncated invalid.');
        $sum=0;
        foreach($raw['omitted'] as $count){
            if(!is_int($count)||$count<0||$count>10000)
                throw new InvalidArgumentException('signal_summary count invalid.');
            $sum+=$count;
        }
        $truncated=$sum>0;
        if($raw['truncated']!==$truncated
            ||$raw['reason']!==($truncated?'bounded_signal_budget':'none'))
            throw new InvalidArgumentException('signal_summary provenance invalid.');
        return $raw;
    }

    private static function workInventory(mixed $raw,int $now): array
    {
        self::fields($raw,['version','source_ref','observed_at','freshness','projects'],'work_inventory');
        if(!is_string($raw['source_ref'])||!is_int($raw['observed_at'])||$raw['observed_at']<1||$raw['observed_at']>$now
            ||!is_string($raw['freshness']))
            throw new InvalidArgumentException('Work inventory provenance invalid.');
        return WorkInventorySnapshot::fromCanonical(
            ['version'=>$raw['version'],'projects'=>$raw['projects']],
            $raw['source_ref'],
            $raw['observed_at'],
            $raw['freshness'],
        );
    }

    private static function section(mixed $raw,string $section,string $authority,int $now): array
    {
        if($raw===null||$raw===[])return [self::unknown($section,$authority)];
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>self::LIMIT)
            throw new InvalidArgumentException($section.' signals invalid.');
        $out=[];$seen=[];
        foreach($raw as $row){
            $signal=self::signal($row,$section,$authority,$now);
            if(isset($seen[$signal['id']]))throw new InvalidArgumentException($section.' duplicated.');
            $seen[$signal['id']]=true;$out[]=$signal;
        }
        usort($out,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);
        return $out;
    }

    private static function signal(mixed $raw,string $section,string $authority,int $now): array
    {
        self::fields($raw,['id','authority','state','source_ref','observed_at','freshness','data'],$section);
        if($raw['authority']!==$authority)throw new InvalidArgumentException($section.' authority mismatch.');
        $id=self::id($raw['id'],$section.'.id');
        $state=self::choice($raw['state'],self::STATES,$section.'.state');
        $fresh=self::choice($raw['freshness'],self::FRESH,$section.'.freshness');
        $source=self::nullableText($raw['source_ref'],$section.'.source_ref',240);
        $observed=self::nullableTime($raw['observed_at'],$section.'.observed_at',$now);
        if($fresh==='unknown'){
            if($source!==null||$observed!==null||$state!=='unknown')
                throw new InvalidArgumentException($section.' unknown incoherent.');
        }elseif($source===null||$observed===null)
            throw new InvalidArgumentException($section.' provenance required.');
        if($fresh==='stale'&&$state==='healthy')
            throw new InvalidArgumentException($section.' stale cannot be healthy.');

        $data=self::data($raw['data'],$section.'.data',0);
        if($section==='owner_decisions'&&$fresh!=='unknown')
            self::decisionRef(is_array($data)?($data['issue_ref']??null):null);

        return [
            'id'=>$id,'authority'=>$authority,'state'=>$state,'source_ref'=>$source,
            'observed_at'=>$observed,'freshness'=>$fresh,
            'age_seconds'=>$observed===null?null:$now-$observed,'data'=>$data,
        ];
    }

    private static function unknown(string $section,string $authority): array
    {
        return [
            'id'=>$section.':unknown','authority'=>$authority,'state'=>'unknown',
            'source_ref'=>null,'observed_at'=>null,'freshness'=>'unknown',
            'age_seconds'=>null,'data'=>[],
        ];
    }

    private static function data(mixed $value,string $label,int $depth): mixed
    {
        if($depth>4)throw new InvalidArgumentException($label.' too deep.');
        if($value===null||is_bool($value)||is_int($value))return $value;
        if(is_float($value)){
            if(!is_finite($value))throw new InvalidArgumentException($label.' float invalid.');
            return $value;
        }
        if(is_string($value))return self::text($value,$label,500);
        if(!is_array($value)||count($value)>self::LIMIT)throw new InvalidArgumentException($label.' invalid.');
        if(array_is_list($value))
            return array_map(fn(mixed $item):mixed=>self::data($item,$label,$depth+1),$value);
        $out=[];
        foreach($value as $key=>$item){
            if(!is_string($key)||preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D',$key)!==1
                ||preg_match(self::SENSITIVE,$key)===1)
                throw new InvalidArgumentException($label.' key invalid.');
            $out[$key]=self::data($item,$label,$depth+1);
        }
        ksort($out,SORT_STRING);return $out;
    }

    private static function decisionRef(mixed $value): void
    {
        if(!is_string($value)||preg_match(
            '~^(?:https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*|github:[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+#[1-9][0-9]*)$~D',
            $value
        )!==1)throw new InvalidArgumentException('Owner decision issue_ref invalid.');
    }

    private static function id(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._:\/#-]{2,160}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return self::text($value,$label,180);
    }

    private static function nullableText(mixed $value,string $label,int $max): ?string
    { return $value===null?null:self::text($value,$label,$max); }

    private static function nullableTime(mixed $value,string $label,int $now): ?int
    {
        if($value===null)return null;
        if(!is_int($value)||$value<1||$value>$now)throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $label,int $max): string
    {
        if(!is_string($value))throw new InvalidArgumentException($label.' invalid.');
        $value=trim($value);
        if($value===''||mb_strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/u',$value)===1
            ||preg_match(self::SENSITIVE,$value)===1||preg_match(self::PII,$value)===1)
            throw new InvalidArgumentException($label.' unsafe.');
        return $value;
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)throw new InvalidArgumentException($label.' fields invalid.');
    }
}

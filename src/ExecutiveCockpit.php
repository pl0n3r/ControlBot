<?php
declare(strict_types=1);

namespace ControlBot\Business;

use ControlBot\Infrastructure\InfrastructureObservation;
use ControlBot\Infrastructure\InfrastructureResource;
use ControlBot\Runtime\RuntimeCapacitySignal;
use InvalidArgumentException;

final class ExecutiveCockpit
{
    private const HEALTH=['healthy','degraded','critical','unknown'];
    private const CLASSES=['fyi','watch','decision','critical'];
    private const SENSITIVE='/(?:-----BEGIN [^-]*PRIVATE KEY-----|\bBearer\s+\S+|(?:password|passwd|secret|token|api[_ -]?key|private[_ -]?key)\s*[:=]\s*\S+)/i';
    private const DIRECT_PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function build(array $rows): array
    {
        if(!array_is_list($rows)||count($rows)>100)
            throw new InvalidArgumentException('Cockpit rows invalid.');

        $out=[];$seen=[];$groupId=null;
        foreach($rows as $row){
            if(!is_array($row)||array_is_list($row))
                throw new InvalidArgumentException('Cockpit row invalid.');
            self::fields($row,[
                'venture','responsible_identity','business_health','technical_health',
                'finance','product_health','infrastructure','runtime','owner_inbox',
            ],'cockpit row');

            $venture=VentureIdentity::normalizeVenture($row['venture']);
            $ventureId=$venture['venture_id'];
            if(isset($seen[$ventureId])) throw new InvalidArgumentException('Venture duplicated.');
            $seen[$ventureId]=true;
            if($groupId===null) $groupId=$venture['group_id'];
            elseif($groupId!==$venture['group_id']) throw new InvalidArgumentException('Cockpit group mismatch.');

            $identity=VentureIdentity::normalizeIdentity($row['responsible_identity']);
            if($identity['identity_id']!==$venture['responsible_identity_id'])
                throw new InvalidArgumentException('Responsible identity mismatch.');

            $out[]=[
                'venture'=>[
                    'venture_id'=>$ventureId,
                    'group_id'=>$venture['group_id'],
                    'title'=>self::safeText($venture['title'],'venture.title'),
                    'state'=>$venture['state'],
                    'strategy_role'=>$venture['strategy_role'],
                    'responsible'=>[
                        'identity_id'=>$identity['identity_id'],
                        'kind'=>$identity['kind'],
                        'state'=>$identity['state'],
                        'source_ref'=>$identity['source_ref'],
                        'observed_at'=>$identity['observed_at'],
                    ],
                ],
                'business_health'=>self::health($row['business_health'],'business_health'),
                'technical_health'=>self::health($row['technical_health'],'technical_health'),
                'finance'=>self::finance($row['finance'],$ventureId),
                'product_health'=>self::product($row['product_health'],$ventureId),
                'infrastructure'=>self::infra($row['infrastructure'],$ventureId),
                'runtime'=>self::runtime($row['runtime'],$ventureId),
                'owner_inbox_counts'=>self::inbox($row['owner_inbox'],$ventureId),
            ];
        }

        usort($out,static fn(array $a,array $b):int=>$a['venture']['venture_id']<=>$b['venture']['venture_id']);
        $result=['version'=>1,'group_id'=>$groupId,'ventures'=>$out];
        self::secretFree($result);
        return $result;
    }

    private static function health(mixed $raw,string $label): array
    {
        self::fields($raw,['state','freshness','source_ref','observed_at'],$label);
        $state=self::oneOf($raw['state'],self::HEALTH,$label.'.state');
        $fresh=self::oneOf($raw['freshness'],['current','stale','unknown'],$label.'.freshness');
        $source=self::nullableRef($raw['source_ref'],$label.'.source_ref');
        $observed=self::nullableTime($raw['observed_at'],$label.'.observed_at');
        if($fresh==='unknown'&&($source!==null||$observed!==null))
            throw new InvalidArgumentException($label.' unknown cannot carry provenance.');
        if($fresh!=='unknown'&&($source===null||$observed===null))
            throw new InvalidArgumentException($label.' provenance required.');
        if($fresh!=='current'&&$state==='healthy')
            throw new InvalidArgumentException($label.' stale/unknown cannot be healthy.');
        return ['state'=>$state,'freshness'=>$fresh,'source_ref'=>$source,'observed_at'=>$observed];
    }

    private static function finance(mixed $raw,string $ventureId): ?array
    {
        if($raw===null) return null;
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException('finance invalid.');
        $v=VentureFinancialSnapshot::normalize($raw);
        if($v['venture_id']!==$ventureId) throw new InvalidArgumentException('finance venture mismatch.');
        return [
            'period'=>$v['period'],'currency'=>$v['currency'],'net_revenue'=>$v['net_revenue'],
            'gross_profit'=>$v['gross_profit'],'operating_result'=>$v['operating_result'],
            'cash_in'=>$v['cash_in'],'cash_out'=>$v['cash_out'],'customers'=>$v['customers'],
            'transactions'=>$v['transactions'],'freshness'=>$v['freshness'],
            'confidence'=>$v['confidence'],'source_ref'=>$v['source_ref'],'observed_at'=>$v['observed_at'],
        ];
    }

    private static function product(mixed $raw,string $ventureId): ?array
    {
        if($raw===null) return null;
        self::fields($raw,['product_id','metrics'],'product_health');
        $productId=self::productId($raw['product_id']);
        if(!is_array($raw['metrics'])) throw new InvalidArgumentException('product health metrics invalid.');
        $snapshot=ProductHealthSnapshot::snapshot($raw['metrics'],$ventureId,$productId);
        return [
            'product_id'=>$snapshot['product_id'],
            'surface'=>$snapshot['surface'],
            'period'=>$snapshot['period'],
            'freshness'=>$snapshot['freshness'],
            'reasons'=>$snapshot['reasons'],
            'dimension_count'=>count($snapshot['dimensions']),
        ];
    }

    private static function infra(mixed $raw,string $ventureId): ?array
    {
        if($raw===null) return null;
        self::fields($raw,['resource','observation'],'infrastructure');
        if(!is_array($raw['resource'])||!is_array($raw['observation']))
            throw new InvalidArgumentException('infrastructure invalid.');
        $resource=InfrastructureResource::normalize($raw['resource']);
        $o=InfrastructureObservation::normalize($raw['observation']);
        if($resource['resource_id']!==$o['resource_id'])
            throw new InvalidArgumentException('Infrastructure observation resource mismatch.');
        if($resource['venture_ref']!=='controlbot:venture/'.$ventureId)
            throw new InvalidArgumentException('infrastructure venture mismatch.');
        return [
            'resource_id'=>$o['resource_id'],'kind'=>$resource['kind'],
            'state'=>$o['state'],'freshness'=>$o['freshness'],
            'source_ref'=>$o['source_ref'],'observed_at'=>$o['observed_at'],
            'incident_count'=>count($o['incident_refs']),
        ];
    }

    private static function runtime(mixed $raw,string $ventureId): ?array
    {
        if($raw===null) return null;
        self::fields($raw,['venture_id','signal'],'runtime');
        if($raw['venture_id']!==$ventureId||!is_array($raw['signal']))
            throw new InvalidArgumentException('runtime venture mismatch.');
        $s=RuntimeCapacitySignal::normalize($raw['signal']);
        return [
            'source'=>$s['source'],'provider_id'=>$s['provider_id'],'state'=>$s['state'],
            'observed_at'=>$s['observed_at'],'heartbeat_at'=>$s['heartbeat_at'],
            'total_capacity'=>$s['total_capacity'],'occupied_capacity'=>$s['occupied_capacity'],
            'assignment_ref'=>$s['assignment_ref'],
        ];
    }

    private static function inbox(mixed $raw,string $ventureId): array
    {
        if(!is_array($raw)||!array_is_list($raw)) throw new InvalidArgumentException('owner_inbox invalid.');
        $collection=OwnerInbox::collection($raw);
        $counts=array_fill_keys(self::CLASSES,0);
        $ref='controlbot:venture/'.$ventureId;
        foreach($collection['entries'] as $entry){
            if($entry['scope']['kind']!=='venture'||$entry['scope']['ref']!==$ref)
                throw new InvalidArgumentException('Owner Inbox venture mismatch.');
            $counts[$entry['class']]++;
        }
        return $counts;
    }

    private static function nullableRef(mixed $v,string $label): ?string
    {
        if($v===null) return null;
        if(!is_string($v)||strlen($v)>180||preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/#-]+$~D',$v)!==1||str_contains($v,'@')
            ||preg_match(self::SENSITIVE,$v)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $v;
    }

    private static function nullableTime(mixed $v,string $label): ?int
    {
        if($v===null) return null;
        if(!is_int($v)||$v<1) throw new InvalidArgumentException($label.' invalid.');
        return $v;
    }

    private static function oneOf(mixed $v,array $allowed,string $label): string
    {
        if(!is_string($v)||!in_array($v,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $v;
    }

    private static function productId(mixed $v): string
    {
        if(!is_string($v)||preg_match('/^product-[a-z0-9][a-z0-9-]{1,79}$/D',$v)!==1)
            throw new InvalidArgumentException('product_id invalid.');
        return $v;
    }

    private static function safeText(mixed $v,string $label): string
    {
        if(!is_string($v)||trim($v)===''||preg_match(self::SENSITIVE,$v)===1||preg_match(self::DIRECT_PII,$v)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return trim($v);
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item) self::secretFree($item);return;}
        if(is_string($value)&&(preg_match(self::SENSITIVE,$value)===1||preg_match(self::DIRECT_PII,$value)===1))
            throw new InvalidArgumentException('Cockpit contains sensitive material.');
    }
}

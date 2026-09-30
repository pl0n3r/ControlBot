<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use InvalidArgumentException;

final class MomentumPerformance
{
    private const FRESH=['current','stale','unknown'];
    private const CLASSIFICATIONS=['observed','inferred','unknown'];

    public static function project(array $raw,array $paid,array $pipelineRaw,array $attributionRaw): array
    {
        self::fields($raw,[
            'version','performance_id','venture_id','campaign_ref','period','currency',
            'spend','funnel','cost','source_ref','evidence_refs','observed_at','freshness','execution',
        ],'Performance');
        if($raw['version']!==1||$raw['execution']!==false)
            throw new InvalidArgumentException('Performance version or execution invalid.');

        $venture=self::venture($raw['venture_id']);
        $campaign=self::opaque($raw['campaign_ref'],'campaign');
        $period=self::period($raw['period']);
        $currency=self::currency($raw['currency']);
        $fresh=self::choice($raw['freshness'],self::FRESH,'freshness');
        $observed=self::natural($raw['observed_at'],'observed_at'); self::within($observed,$period);

        $paid=self::paid($paid,$venture,$campaign,$currency,$period);
        $pipeline=MomentumRevenue::pipeline($pipelineRaw);
        $attribution=MomentumRevenue::attribution($attributionRaw,$pipelineRaw);
        self::scope($pipeline,$attribution,$venture,$campaign,$currency,$period);

        $spend=self::money($raw['spend'],'Spend',['observed','unknown'],$currency,$period,true);
        if($spend['governed_spend_ref']!==$paid['spend_ref'])
            throw new InvalidArgumentException('Spend governance ref mismatch.');
        $funnel=self::funnel($raw['funnel'],$period);
        $cost=self::money($raw['cost'],'Cost',['observed','unknown'],$currency,$period,false);

        $current=$fresh==='current';
        $spendMinor=$current&&$spend['classification']==='observed'&&$spend['freshness']==='current'?$spend['amount_minor']:null;
        $funnelKnown=$current&&$funnel['classification']!=='unknown'&&$funnel['freshness']==='current';
        $leads=$funnelKnown?$funnel['leads']:null; $conversions=$funnelKnown?$funnel['conversions']:null;
        $costMinor=$current&&$cost['classification']==='observed'&&$cost['freshness']==='current'?$cost['amount_minor']:null;
        $observedRevenue=$current&&$attribution['classification']==='observed'&&$attribution['freshness']==='current'
            ?$attribution['demonstrated_amount_minor']:null;
        $inferredRevenue=$current&&$attribution['classification']==='inferred'&&$attribution['freshness']==='current'
            ?$attribution['amount_minor']:null;
        $margin=$spendMinor!==null&&$costMinor!==null&&$observedRevenue!==null?$observedRevenue-$spendMinor-$costMinor:null;
        $cac=$spendMinor!==null&&$conversions!==null&&$conversions>0?intdiv($spendMinor,$conversions):null;
        $roas=$spendMinor!==null&&$spendMinor>0&&$observedRevenue!==null?intdiv($observedRevenue*1000,$spendMinor):null;

        return [
            'version'=>1,'performance_id'=>self::opaque($raw['performance_id'],'performance'),
            'venture_id'=>$venture,'campaign_ref'=>$campaign,'paid_media_spend_ref'=>$paid['spend_ref'],
            'period'=>$period,'currency'=>$currency,'source_ref'=>self::opaque($raw['source_ref'],'source'),
            'evidence_refs'=>self::refs($raw['evidence_refs'],'evidence',64,true),
            'observed_at'=>$observed,'freshness'=>$fresh,'spend'=>$spend,'funnel'=>$funnel,'cost'=>$cost,
            'revenue'=>[
                'classification'=>$attribution['classification'],'amount_minor'=>$attribution['amount_minor'],
                'observed_amount_minor'=>$attribution['demonstrated_amount_minor'],'source_ref'=>$attribution['source_ref'],
                'evidence_refs'=>$attribution['evidence_refs'],'observed_at'=>$attribution['observed_at'],
                'freshness'=>$attribution['freshness'],
            ],
            'derived'=>[
                'spend_minor'=>$spendMinor,'leads'=>$leads,'conversions'=>$conversions,
                'observed_revenue_minor'=>$observedRevenue,'inferred_revenue_minor'=>$inferredRevenue,
                'cost_minor'=>$costMinor,'margin_minor'=>$margin,
            ],
            'unit_economics'=>[
                'cac_minor'=>$cac,'cac_classification'=>$cac===null?'unknown':($funnel['classification']==='observed'?'observed':'inferred'),
                'roas_milli'=>$roas,'roas_classification'=>$roas===null?'unknown':'observed',
                'payback_days'=>null,'payback_classification'=>'unknown','ltv_minor'=>null,'ltv_classification'=>'unknown',
            ],
            'execution'=>false,
        ];
    }

    private static function paid(array $row,string $venture,string $campaign,string $currency,array $period): array
    {
        foreach(['version','campaign_id','venture_id','spend','freshness','observed_at','status','execution'] as $key)
            if(!array_key_exists($key,$row)) throw new InvalidArgumentException('Paid Media evidence incomplete.');
        if($row['version']!==1||$row['status']!=='planned'||$row['execution']!==false||$row['freshness']!=='current'
            ||$row['venture_id']!==$venture||$row['campaign_id']!==$campaign)
            throw new InvalidArgumentException('Paid Media evidence not eligible.');
        self::within(self::natural($row['observed_at'],'paid observed_at'),$period);
        self::fields($row['spend'],['spend_ref','amount_minor','currency','budget_ref','evidence_refs','blast_radius'],'PaidSpend');
        if(self::currency($row['spend']['currency'])!==$currency) throw new InvalidArgumentException('Paid Media currency mismatch.');
        return ['spend_ref'=>self::opaque($row['spend']['spend_ref'],'spend')];
    }

    private static function scope(array $pipeline,array $attr,string $venture,string $campaign,string $currency,array $period): void
    {
        if($pipeline['venture_id']!==$venture||$attr['venture_id']!==$venture
            ||$pipeline['campaign_ref']!==$campaign||$attr['campaign_ref']!==$campaign||$attr['currency']!==$currency)
            throw new InvalidArgumentException('Performance scope mismatch.');
        self::within($pipeline['observed_at'],$period); self::within($attr['observed_at'],$period);
    }

    private static function money(mixed $row,string $label,array $allowed,string $currency,array $period,bool $governed): array
    {
        $fields=['classification','amount_minor','currency','source_ref','evidence_refs','observed_at','freshness'];
        if($governed)$fields[]='governed_spend_ref';
        self::fields($row,$fields,$label);
        $class=self::choice($row['classification'],$allowed,'classification');
        $amount=$row['amount_minor']===null?null:self::natural($row['amount_minor'],'amount_minor');
        $evidence=self::refs($row['evidence_refs'],'evidence',32,$class!=='unknown');
        if($class==='unknown'&&$amount!==null) throw new InvalidArgumentException($label.' unknown amount.');
        if($class!=='unknown'&&$amount===null) throw new InvalidArgumentException($label.' amount required.');
        if(self::currency($row['currency'])!==$currency) throw new InvalidArgumentException($label.' currency mismatch.');
        $time=self::natural($row['observed_at'],'observed_at'); self::within($time,$period);
        $out=['classification'=>$class,'amount_minor'=>$amount,'currency'=>$currency,
            'source_ref'=>self::opaque($row['source_ref'],'source'),'evidence_refs'=>$evidence,
            'observed_at'=>$time,'freshness'=>self::choice($row['freshness'],self::FRESH,'freshness')];
        if($governed)$out['governed_spend_ref']=self::opaque($row['governed_spend_ref'],'spend');
        return $out;
    }

    private static function funnel(mixed $row,array $period): array
    {
        self::fields($row,['classification','leads','conversions','source_ref','evidence_refs','observed_at','freshness'],'Funnel');
        $class=self::choice($row['classification'],self::CLASSIFICATIONS,'classification');
        $leads=$row['leads']===null?null:self::natural($row['leads'],'leads');
        $conv=$row['conversions']===null?null:self::natural($row['conversions'],'conversions');
        $evidence=self::refs($row['evidence_refs'],'evidence',32,$class!=='unknown');
        if($class==='unknown'&&($leads!==null||$conv!==null)) throw new InvalidArgumentException('Unknown funnel counts.');
        if($class!=='unknown'&&($leads===null||$conv===null)) throw new InvalidArgumentException('Funnel counts required.');
        if($leads!==null&&$conv!==null&&$conv>$leads) throw new InvalidArgumentException('Conversions exceed leads.');
        $time=self::natural($row['observed_at'],'observed_at'); self::within($time,$period);
        return ['classification'=>$class,'leads'=>$leads,'conversions'=>$conv,
            'source_ref'=>self::opaque($row['source_ref'],'source'),'evidence_refs'=>$evidence,
            'observed_at'=>$time,'freshness'=>self::choice($row['freshness'],self::FRESH,'freshness')];
    }

    private static function period(mixed $row): array
    {
        self::fields($row,['start_at','end_at'],'Period');
        $start=self::natural($row['start_at'],'start_at'); $end=self::natural($row['end_at'],'end_at');
        if($end<$start) throw new InvalidArgumentException('Period invalid.');
        return ['start_at'=>$start,'end_at'=>$end];
    }
    private static function within(int $v,array $p): void
    { if($v<$p['start_at']||$v>$p['end_at']) throw new InvalidArgumentException('Evidence outside period.'); }
    private static function venture(mixed $v): string
    { if(is_string($v)&&preg_match('/^venture-[a-z][a-z0-9-]{1,55}$/D',$v)===1)return $v; throw new InvalidArgumentException('venture invalid.'); }
    private static function opaque(mixed $v,string $ns): string
    { if(is_string($v)&&preg_match('/^'.preg_quote($ns,'/').':[a-f0-9]{32}$/D',$v)===1)return $v; throw new InvalidArgumentException($ns.' ref invalid.'); }
    private static function refs(mixed $v,string $ns,int $max,bool $required=false): array
    {
        if(!is_array($v)||!array_is_list($v)||count($v)>$max||($required&&$v===[])) throw new InvalidArgumentException($ns.' refs invalid.');
        $r=array_map(static fn(mixed $x):string=>self::opaque($x,$ns),$v);
        if(count(array_unique($r,SORT_STRING))!==count($r)) throw new InvalidArgumentException($ns.' refs duplicated.');
        sort($r,SORT_STRING); return $r;
    }
    private static function currency(mixed $v): string
    { if(is_string($v)&&preg_match('/^[A-Z]{3}$/D',$v)===1)return $v; throw new InvalidArgumentException('currency invalid.'); }
    private static function natural(mixed $v,string $label): int
    { if(is_int($v)&&$v>=0&&$v<=1_000_000_000_000_000)return $v; throw new InvalidArgumentException($label.' invalid.'); }
    private static function choice(mixed $v,array $allowed,string $label): string
    { if(is_string($v)&&in_array($v,$allowed,true))return $v; throw new InvalidArgumentException($label.' invalid.'); }
    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row); sort($actual); sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductDiscoveryExperiment
{
    private const KINDS=[
        'qualitative_interview','landing_test','prototype','fake_door','pilot',
        'benchmark','pricing_research','funnel_test','technical_test','process_experiment',
    ];
    private const STATES=['EXPERIMENT_READY','RUNNING'];

    public static function plan(array $raw,array $initiativeRaw,array $hypothesisRaw): array
    {
        $initiative=ProductDiscovery::initiative($initiativeRaw);
        $hypothesis=ProductDiscovery::hypothesis($hypothesisRaw,$initiativeRaw);
        self::exactFields($raw,[
            'version','experiment_ref','initiative_id','hypothesis_ref','kind','primary_metric_ref',
            'evaluation_window','validation_cost_ref','state',
        ]);
        if($raw['version']!==1) throw new InvalidArgumentException('DiscoveryExperiment version invalid.');
        if($initiative['state']!=='HYPOTHESIS')
            throw new InvalidArgumentException('DiscoveryExperiment initiative state invalid.');

        $initiativeId=self::reference($raw['initiative_id'],'initiative');
        $hypothesisRef=self::reference($raw['hypothesis_ref'],'hypothesis');
        $metricRef=self::reference($raw['primary_metric_ref'],'metric');
        if($initiativeId!==$initiative['initiative_id']
            ||$hypothesisRef!==$hypothesis['hypothesis_ref']
            ||$metricRef!==$hypothesis['primary_metric_ref'])
            throw new InvalidArgumentException('DiscoveryExperiment binding mismatch.');

        return [
            'version'=>1,
            'experiment_ref'=>self::reference($raw['experiment_ref'],'experiment'),
            'initiative_id'=>$initiativeId,
            'hypothesis_ref'=>$hypothesisRef,
            'scope'=>$initiative['scope'],
            'venture_id'=>$initiative['venture_id'],
            'market_ref'=>$initiative['market_ref'],
            'responsible_ref'=>$initiative['responsible_ref'],
            'expected_outcome_ref'=>$hypothesis['expected_outcome_ref'],
            'primary_metric_ref'=>$metricRef,
            'kind'=>self::choice($raw['kind'],self::KINDS,'kind'),
            'evaluation_window'=>self::window($raw['evaluation_window']),
            'validation_cost_ref'=>self::reference($raw['validation_cost_ref'],'cost'),
            'state'=>self::choice($raw['state'],self::STATES,'state'),
            'hypothesis_freshness'=>$hypothesis['freshness'],
            'hypothesis_confidence'=>$hypothesis['confidence'],
            'execution'=>false,
        ];
    }

    private static function window(mixed $value): array
    {
        if(!is_array($value)||array_is_list($value)||count($value)!==2
            ||!array_key_exists('start_at',$value)||!array_key_exists('end_at',$value)
            ||!is_int($value['start_at'])||!is_int($value['end_at'])
            ||$value['start_at']<0||$value['end_at']<0||$value['end_at']<=$value['start_at'])
            throw new InvalidArgumentException('evaluation_window invalid.');
        return ['start_at'=>$value['start_at'],'end_at'=>$value['end_at']];
    }

    private static function reference(mixed $value,string $namespace): string
    {
        if(is_string($value)&&preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)===1)
            return $value;
        throw new InvalidArgumentException($namespace.' ref invalid.');
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(is_string($value)&&in_array($value,$allowed,true)) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function exactFields(mixed $row,array $expected): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException('DiscoveryExperiment invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException('DiscoveryExperiment fields invalid.');
    }
}

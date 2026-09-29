<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductDiscovery
{
    private const SCOPES=['group','venture'];
    private const STATES=['IDEA','RESEARCHING','HYPOTHESIS','PARKED'];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const CONFIDENCE=['low','medium','high','unknown'];

    public static function initiative(array $raw): array
    {
        self::exactFields($raw,[
            'version','initiative_id','scope','venture_id','market_ref','problem_ref','segment_ref',
            'evidence_refs','source_ref','freshness','confidence','state','responsible_ref',
        ],'DiscoveryInitiative');
        if($raw['version']!==1) throw new InvalidArgumentException('DiscoveryInitiative version invalid.');

        $scope=self::choice($raw['scope'],self::SCOPES,'scope');
        $venture=$raw['venture_id']===null?null:self::venture($raw['venture_id']);
        if(($scope==='group'&&$venture!==null)||($scope==='venture'&&$venture===null))
            throw new InvalidArgumentException('DiscoveryInitiative scope invalid.');

        $freshness=self::choice($raw['freshness'],self::FRESHNESS,'freshness');
        $confidence=self::choice($raw['confidence'],self::CONFIDENCE,'confidence');
        self::evidenceState($freshness,$confidence);

        return [
            'version'=>1,
            'initiative_id'=>self::reference($raw['initiative_id'],'initiative'),
            'scope'=>$scope,
            'venture_id'=>$venture,
            'market_ref'=>self::nullableReference($raw['market_ref'],'market'),
            'problem_ref'=>self::reference($raw['problem_ref'],'problem'),
            'segment_ref'=>self::reference($raw['segment_ref'],'segment'),
            'evidence_refs'=>self::references($raw['evidence_refs'],'evidence',32,true),
            'source_ref'=>self::reference($raw['source_ref'],'source'),
            'freshness'=>$freshness,
            'confidence'=>$confidence,
            'state'=>self::choice($raw['state'],self::STATES,'state'),
            'responsible_ref'=>self::reference($raw['responsible_ref'],'responsible'),
        ];
    }

    public static function hypothesis(array $raw,array $initiativeRaw): array
    {
        $initiative=self::initiative($initiativeRaw);
        self::exactFields($raw,[
            'version','hypothesis_ref','initiative_id','expected_outcome_ref','primary_metric_ref',
            'constraint_refs','evidence_refs','source_ref','freshness','confidence',
        ],'DiscoveryHypothesis');
        if($raw['version']!==1) throw new InvalidArgumentException('DiscoveryHypothesis version invalid.');

        $initiativeId=self::reference($raw['initiative_id'],'initiative');
        if($initiativeId!==$initiative['initiative_id'])
            throw new InvalidArgumentException('DiscoveryHypothesis initiative mismatch.');

        $freshness=self::choice($raw['freshness'],self::FRESHNESS,'freshness');
        $confidence=self::choice($raw['confidence'],self::CONFIDENCE,'confidence');
        self::evidenceState($freshness,$confidence);

        return [
            'version'=>1,
            'hypothesis_ref'=>self::reference($raw['hypothesis_ref'],'hypothesis'),
            'initiative_id'=>$initiativeId,
            'expected_outcome_ref'=>self::reference($raw['expected_outcome_ref'],'outcome'),
            'primary_metric_ref'=>self::reference($raw['primary_metric_ref'],'metric'),
            'constraint_refs'=>self::references($raw['constraint_refs'],'constraint',32,true),
            'evidence_refs'=>self::references($raw['evidence_refs'],'evidence',32,false),
            'source_ref'=>self::reference($raw['source_ref'],'source'),
            'freshness'=>$freshness,
            'confidence'=>$confidence,
        ];
    }

    private static function reference(mixed $value,string $namespace): string
    {
        if(is_string($value)&&preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)===1)
            return $value;
        throw new InvalidArgumentException($namespace.' ref invalid.');
    }

    private static function nullableReference(mixed $value,string $namespace): ?string
    {
        return $value===null?null:self::reference($value,$namespace);
    }

    private static function references(mixed $values,string $namespace,int $max,bool $allowEmpty): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>$max||(!$allowEmpty&&$values===[]))
            throw new InvalidArgumentException($namespace.' refs invalid.');
        $refs=array_map(static fn(mixed $value): string=>self::reference($value,$namespace),$values);
        if(count(array_unique($refs,SORT_STRING))!==count($refs))
            throw new InvalidArgumentException($namespace.' ref duplicated.');
        sort($refs,SORT_STRING);
        return $refs;
    }

    private static function venture(mixed $value): string
    {
        if(is_string($value)&&preg_match('/^venture-[a-z0-9][a-z0-9-]{1,79}$/D',$value)===1)
            return $value;
        throw new InvalidArgumentException('venture_id invalid.');
    }

    private static function evidenceState(string $freshness,string $confidence): void
    {
        if($freshness==='unknown'&&$confidence!=='unknown')
            throw new InvalidArgumentException('Unknown freshness requires unknown confidence.');
        if($freshness==='stale'&&$confidence==='high')
            throw new InvalidArgumentException('Stale evidence cannot have high confidence.');
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(is_string($value)&&in_array($value,$allowed,true)) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function exactFields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);
        if(count($actual)===count($expected)&&array_diff($actual,$expected)===[]&&array_diff($expected,$actual)===[]) return;
        throw new InvalidArgumentException($label.' fields invalid.');
    }
}

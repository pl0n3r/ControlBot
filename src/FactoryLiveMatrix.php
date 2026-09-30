<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryLiveMatrix
{
    private const PROJECTS=['Condor','GrindFlow','BRVTAL','FactoryRunner','ControlBot','AutoFactory','Factory'];
    private const DEPARTMENTS=['governance','sre_infra_dba','security','qa_engineering','legal_privacy'];
    private const STATES=['healthy','degraded','critical','unknown','blocked','pending'];
    private const FRESH=['current','stale','unknown'];
    private const STATUS=['GREEN','AMBER','RED','UNKNOWN','STALE'];
    private const SECTIONS=['batches','owner_decisions','releases','blockers','production','quality','work','learning'];

    public static function build(array $snapshot): array
    {
        self::fields($snapshot,['version','observed_at','sections','tool_usage','fingerprint'],'snapshot');
        if($snapshot['version']!==1||!is_int($snapshot['observed_at'])||!is_array($snapshot['sections']))
            throw new InvalidArgumentException('Factory live matrix snapshot invalid.');

        $header=[
            'batches'=>self::header($snapshot['sections']['batches']??null),
            'owner_decisions'=>self::header($snapshot['sections']['owner_decisions']??null),
            'releases'=>self::header($snapshot['sections']['releases']??null),
            'blockers'=>self::header($snapshot['sections']['blockers']??null),
            'incidents'=>self::header(self::incidents($snapshot['sections'])),
        ];

        $cells=[];
        foreach(self::PROJECTS as $project)
            foreach(self::DEPARTMENTS as $department)
                $cells[$project][$department]=['status'=>'UNKNOWN','signal'=>null];

        foreach(self::SECTIONS as $section){
            $rows=$snapshot['sections'][$section]??null;
            if(!is_array($rows)||!array_is_list($rows)||$rows===[])
                throw new InvalidArgumentException('Factory live matrix section invalid.');
            foreach($rows as $row){
                $signal=self::signal($row);
                $data=is_array($row['data'])?$row['data']:[];
                $project=self::project($data['project']??$data['repository']??null);
                $department=self::department($data['department']??null);
                if($project===null||$department===null)continue;
                $current=$cells[$project][$department]['signal'];
                if($current===null||self::better($signal,$current))
                    $cells[$project][$department]=['status'=>$signal['status'],'signal'=>$signal];
            }
        }

        return ['version'=>1,'header'=>$header,'projects'=>self::PROJECTS,'departments'=>self::DEPARTMENTS,'cells'=>$cells];
    }

    private static function incidents(array $sections): array
    {
        $out=[];
        foreach(['blockers','work'] as $section)
            foreach(($sections[$section]??[]) as $row)
                if(is_array($row)&&is_array($row['data']??null)
                    &&($row['data']['kind']??null)==='incident'&&($row['data']['open']??null)===true)
                    $out[]=$row;
        return $out;
    }

    private static function header(mixed $rows): array
    {
        if($rows===[])return ['status'=>'UNKNOWN','count'=>0,'signal'=>null];
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('Factory live matrix header invalid.');
        $best=null;$count=0;
        foreach($rows as $row){
            $signal=self::signal($row);
            if($signal['source_ref']!==null)$count++;
            if($best===null||self::betterHeader($signal,$best))$best=$signal;
        }
        return ['status'=>$best['status']??'UNKNOWN','count'=>$count,'signal'=>$best];
    }

    private static function signal(mixed $row): array
    {
        self::fields($row,['id','authority','state','source_ref','observed_at','freshness','age_seconds','data'],'signal');
        $state=self::choice($row['state'],self::STATES,'state');
        $fresh=self::choice($row['freshness'],self::FRESH,'freshness');
        if(!is_string($row['id'])||trim($row['id'])==='')throw new InvalidArgumentException('Signal id invalid.');
        $source=$row['source_ref'];
        $observed=$row['observed_at'];$age=$row['age_seconds'];
        if($fresh==='unknown'){
            if($state!=='unknown'||$source!==null||$observed!==null||$age!==null)
                throw new InvalidArgumentException('Unknown signal incoherent.');
        }elseif(!is_string($source)||!is_int($observed)||!is_int($age)||$age<0)
            throw new InvalidArgumentException('Signal provenance invalid.');
        if($fresh==='stale'&&$state==='healthy')throw new InvalidArgumentException('Stale signal cannot be green.');
        $data=is_array($row['data'])?$row['data']:[];
        $label=is_string($data['title']??null)?trim($data['title']):$row['id'];
        return [
            'id'=>$row['id'],'label'=>$label,'status'=>self::status($state,$fresh),
            'source_ref'=>$source,'observed_at'=>$observed,'freshness'=>$fresh,'age_seconds'=>$age,
            'evidence_href'=>self::href($data['evidence_ref']??$data['issue_ref']??$source),
        ];
    }

    private static function status(string $state,string $fresh): string
    {
        if($fresh==='stale')return 'STALE';
        if($fresh==='unknown'||$state==='unknown')return 'UNKNOWN';
        return match($state){'healthy'=>'GREEN','critical','blocked'=>'RED','degraded','pending'=>'AMBER',default=>'UNKNOWN'};
    }

    private static function better(array $candidate,array $current): bool
    {
        $rank=['GREEN'=>1,'AMBER'=>2,'UNKNOWN'=>3,'STALE'=>4,'RED'=>5];
        $a=$rank[$candidate['status']]??0;$b=$rank[$current['status']]??0;
        return $a>$b||($a===$b&&$candidate['id']<$current['id']);
    }

    private static function betterHeader(array $candidate,array $current): bool
    {
        $rank=['GREEN'=>1,'AMBER'=>2,'UNKNOWN'=>3,'STALE'=>4,'RED'=>5];
        $a=$rank[$candidate['status']]??0;$b=$rank[$current['status']]??0;
        if($a!==$b)return $a>$b;
        $candidateAge=$candidate['age_seconds'];$currentAge=$current['age_seconds'];
        if(is_int($candidateAge)&&is_int($currentAge)&&$candidateAge!==$currentAge)
            return $candidateAge<$currentAge;
        if(is_int($candidateAge)!==is_int($currentAge))return is_int($candidateAge);
        return $candidate['id']<$current['id'];
    }

    private static function project(mixed $value): ?string
    {
        if(!is_string($value))return null;
        $key=strtolower(preg_replace('/[^a-z0-9]/i','',$value)??'');
        $map=['condor'=>'Condor','grindflow'=>'GrindFlow','brvtal'=>'BRVTAL','factoryrunner'=>'FactoryRunner','controlbot'=>'ControlBot','autofactory'=>'AutoFactory','factory'=>'Factory'];
        return $map[$key]??null;
    }

    private static function department(mixed $value): ?string
    { return is_string($value)&&in_array($value,self::DEPARTMENTS,true)?$value:null; }

    private static function href(mixed $value): ?string
    {
        if(!is_string($value))return null;
        if(preg_match('~^github:([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)#([1-9][0-9]*)$~D',$value,$m)===1)
            return 'https://github.com/'.$m[1].'/'.$m[2].'/issues/'.$m[3];
        return preg_match('~^https://(?:github\.com|sonarcloud\.io)/[^\s<>"\']+$~D',$value)===1?$value:null;
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    { if(!is_string($value)||!in_array($value,$allowed,true))throw new InvalidArgumentException($label.' invalid.');return $value; }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected)throw new InvalidArgumentException($label.' fields invalid.');
    }
}

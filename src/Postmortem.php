<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class Postmortem
{
    private const CLASSES=['root_cause','independent_bug','contributing_factor','preventive_change','unknown'];
    private const RELATIONS=[
        'necessary_cause'=>'root_cause',
        'independent_defect'=>'independent_bug',
        'contributor'=>'contributing_factor',
        'preventive_only'=>'preventive_change',
        'unresolved'=>'unknown',
    ];
    private const SENSITIVE='/(?:bearer\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key)/i';
    private const EMAIL='/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';
    private const NUMERIC_PII='/(?<![0-9-])\+?(?!\d{4}-\d{2}-\d{2}\b)(?:[0-9][(). -]?){10,15}(?![0-9])/';

    public static function analyze(array $raw,array $timeline): array
    {
        self::fields($raw,['version','incident_ref','findings','recovery']);
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('Postmortem version invalid.');
        if(($timeline['version']??null)!==1||($timeline['incident_ref']??null)!==$raw['incident_ref'])
            throw new InvalidArgumentException('Postmortem timeline mismatch.');
        $incident=self::ref($raw['incident_ref'],'incident');
        if(!is_array($raw['findings'])||!array_is_list($raw['findings'])||$raw['findings']===[]||count($raw['findings'])>64)
            throw new InvalidArgumentException('Postmortem findings invalid.');

        $seen=[];$findings=[];
        foreach($raw['findings'] as $row){
            self::fields($row,['finding_ref','classification','evidence_ref','evidence_state','evidence_relation','summary','owner_action_required']);
            $ref=self::ref($row['finding_ref'],'finding');
            if(isset($seen[$ref])) throw new InvalidArgumentException('Postmortem finding duplicated.');
            $seen[$ref]=true;
            $class=self::choice($row['classification'],self::CLASSES,'classification');
            $state=self::choice($row['evidence_state'],['supported','incomplete','contradictory'],'evidence_state');
            $relation=self::choice($row['evidence_relation'],array_keys(self::RELATIONS),'evidence_relation');
            if($state!=='supported'){
                if($relation!=='unresolved'||$class!=='unknown')
                    throw new InvalidArgumentException('Postmortem uncertain evidence must remain unknown.');
            }elseif($class!==self::RELATIONS[$relation]){
                throw new InvalidArgumentException('Postmortem classification contradicts evidence relation.');
            }
            if(!is_bool($row['owner_action_required'])||($class==='unknown'&&!$row['owner_action_required']))
                throw new InvalidArgumentException('Postmortem owner action invalid.');
            $findings[]=[
                'finding_ref'=>$ref,'classification'=>$class,
                'evidence_ref'=>self::ref($row['evidence_ref'],'evidence'),'evidence_state'=>$state,
                'evidence_relation'=>$relation,'summary'=>self::text($row['summary']),
                'owner_action_required'=>$row['owner_action_required'],
            ];
        }
        usort($findings,static fn(array $a,array $b): int=>$a['finding_ref']<=>$b['finding_ref']);

        self::fields($raw['recovery'],['canary_issue','mode','fan_out','serial_queue']);
        $canary=self::issue($raw['recovery']['canary_issue'],'canary_issue');
        if($raw['recovery']['mode']!=='serial'||$raw['recovery']['fan_out']!==false)
            throw new InvalidArgumentException('Postmortem recovery invalid.');
        $queue=self::issueQueue($raw['recovery']['serial_queue'],$canary);

        return [
            'version'=>1,'incident_ref'=>$incident,'duration_seconds'=>$timeline['duration_seconds'],
            'findings'=>$findings,
            'recovery'=>['canary_issue'=>$canary,'mode'=>'serial','fan_out'=>false,'serial_queue'=>$queue],
        ];
    }

    private static function text(mixed $v): string
    {
        if(!is_string($v)||$v===''||strlen($v)>240||preg_match(self::SENSITIVE,$v)===1
            ||preg_match(self::EMAIL,$v)===1||preg_match(self::NUMERIC_PII,$v)===1)
            throw new InvalidArgumentException('summary invalid.');
        return $v;
    }
    private static function issueQueue(mixed $v,int $canary): array
    {
        if(!is_array($v)||!array_is_list($v)||$v===[]||count($v)>64)
            throw new InvalidArgumentException('serial_queue invalid.');
        $seen=[];$out=[];
        foreach($v as $value){
            $issue=self::issue($value,'serial_queue');
            if($issue===$canary||isset($seen[$issue])) throw new InvalidArgumentException('serial_queue invalid.');
            $seen[$issue]=true;$out[]=$issue;
        }
        return $out;
    }
    private static function issue(mixed $v,string $label): int
    { if(is_int($v)&&$v>0) return $v; throw new InvalidArgumentException($label.' invalid.'); }
    private static function choice(mixed $v,array $allowed,string $label): string
    { if(is_string($v)&&in_array($v,$allowed,true)) return $v; throw new InvalidArgumentException($label.' invalid.'); }
    private static function ref(mixed $v,string $ns): string
    { if(is_string($v)&&preg_match('/^'.preg_quote($ns,'/').':[a-f0-9]{32}$/D',$v)===1) return $v; throw new InvalidArgumentException($ns.' ref invalid.'); }
    private static function fields(mixed $row,array $expected): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException('Postmortem row invalid.');
        $a=array_keys($row);sort($a);sort($expected);if($a!==$expected) throw new InvalidArgumentException('Postmortem fields invalid.');
    }
}

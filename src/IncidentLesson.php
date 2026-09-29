<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class IncidentLesson
{
    private const CLASSES=['root_cause','independent_bug','contributing_factor','preventive_change','unknown'];
    private const RELATIONS=[
        'necessary_cause'=>'root_cause',
        'independent_defect'=>'independent_bug',
        'contributor'=>'contributing_factor',
        'preventive_only'=>'preventive_change',
        'unresolved'=>'unknown',
    ];
    private const SENSITIVE='/(?:bearer\\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key)/i';
    private const EMAIL='/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i';
    private const NUMERIC_PII='/(?<![0-9-])\\+?(?!\\d{4}-\\d{2}-\\d{2}\\b)(?:[0-9][(). -]?){10,15}(?![0-9])/';

    public static function candidate(array $postmortem): array
    {
        self::fields($postmortem,['version','incident_ref','duration_seconds','findings','recovery'],'Postmortem');
        if(($postmortem['version']??null)!==1) throw new InvalidArgumentException('Postmortem version invalid.');
        $incident=self::ref($postmortem['incident_ref'],'incident');
        if($postmortem['duration_seconds']!==null
            && (!is_int($postmortem['duration_seconds'])||$postmortem['duration_seconds']<0))
            throw new InvalidArgumentException('Postmortem duration invalid.');
        if(!is_array($postmortem['findings'])||!array_is_list($postmortem['findings'])
            ||$postmortem['findings']===[]||count($postmortem['findings'])>64)
            throw new InvalidArgumentException('Postmortem findings invalid.');
        self::recovery($postmortem['recovery']);

        $root=[];$bugs=[];$factors=[];$rules=[];$evidence=[];$ownerAction=false;$seen=[];
        foreach($postmortem['findings'] as $raw){
            self::fields($raw,['finding_ref','classification','evidence_ref','evidence_state','evidence_relation','summary','owner_action_required'],'Finding');
            $findingRef=self::ref($raw['finding_ref'],'finding');
            if(isset($seen[$findingRef])) throw new InvalidArgumentException('Finding duplicated.');
            $seen[$findingRef]=true;
            $class=self::choice($raw['classification'],self::CLASSES,'classification');
            $state=self::choice($raw['evidence_state'],['supported','incomplete','contradictory','unknown'],'evidence_state');
            $relation=self::choice($raw['evidence_relation'],array_keys(self::RELATIONS),'evidence_relation');
            if(!is_bool($raw['owner_action_required'])) throw new InvalidArgumentException('owner_action_required invalid.');
            if($state!=='supported'){
                if($class!=='unknown'||$relation!=='unresolved'||$raw['owner_action_required']!==true)
                    throw new InvalidArgumentException('Uncertain finding must remain unresolved.');
            }elseif($class!==self::RELATIONS[$relation]){
                throw new InvalidArgumentException('Finding classification invalid.');
            }
            if($class==='unknown'&&$raw['owner_action_required']!==true)
                throw new InvalidArgumentException('Unknown finding requires owner action.');

            $summary=self::text($raw['summary']);
            $evidence[]=self::ref($raw['evidence_ref'],'evidence');
            $ownerAction=$ownerAction||$raw['owner_action_required']||$state!=='supported'||$class==='unknown';
            if($state!=='supported') continue;
            match($class){
                'root_cause'=>$root[]=$summary,
                'independent_bug'=>$bugs[]=$summary,
                'contributing_factor'=>$factors[]=$summary,
                'preventive_change'=>$rules[]=$summary,
                default=>null,
            };
        }

        $root=self::canonical($root);$bugs=self::canonical($bugs);$factors=self::canonical($factors);
        $rules=self::canonical($rules);$evidence=self::canonical($evidence);
        $title=$root!==[]?'Incident lesson: '.self::clip($root[0],96):'Incident lesson pending causal confirmation';
        $summary=$root!==[]
            ?'Reusable lesson grounded in validated root cause: '.self::clip($root[0],160)
            :'Causal evidence remains unresolved; owner action is required.';

        $payload=[
            'version'=>1,'incident_ref'=>$incident,'publication_state'=>'pending','title'=>$title,'summary'=>$summary,
            'root_cause_facts'=>$root,'independent_bugs'=>$bugs,'contributing_factors'=>$factors,
            'preventive_rules'=>$rules,'evidence_refs'=>$evidence,'owner_action_required'=>$ownerAction,
        ];
        $fingerprint=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $payload+[
            'candidate_fingerprint'=>$fingerprint,
            'dedupe_marker'=>'incident-lesson-v1:'.$fingerprint,
        ];
    }

    private static function recovery(mixed $raw): void
    {
        self::fields($raw,['canary_issue','mode','fan_out','serial_queue'],'Recovery');
        if(!is_int($raw['canary_issue'])||$raw['canary_issue']<1||$raw['mode']!=='serial'||$raw['fan_out']!==false
            ||!is_array($raw['serial_queue'])||!array_is_list($raw['serial_queue']))
            throw new InvalidArgumentException('Recovery invalid.');
    }
    private static function canonical(array $values): array
    { $values=array_values(array_unique($values));sort($values,SORT_STRING);return $values; }
    private static function clip(string $value,int $max): string
    { return strlen($value)<=$max?$value:rtrim(substr($value,0,$max-1)).'…'; }
    private static function text(mixed $value): string
    {
        if(!is_string($value)||$value===''||strlen($value)>240||preg_match(self::SENSITIVE,$value)===1
            ||preg_match(self::EMAIL,$value)===1||preg_match(self::NUMERIC_PII,$value)===1)
            throw new InvalidArgumentException('Lesson text invalid.');
        return $value;
    }
    private static function choice(mixed $value,array $allowed,string $label): string
    { if(is_string($value)&&in_array($value,$allowed,true)) return $value; throw new InvalidArgumentException($label.' invalid.'); }
    private static function ref(mixed $value,string $namespace): string
    { if(is_string($value)&&preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)===1) return $value; throw new InvalidArgumentException($namespace.' ref invalid.'); }
    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

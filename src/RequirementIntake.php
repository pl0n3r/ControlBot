<?php
declare(strict_types=1);
namespace ControlBot\Requirements;
use InvalidArgumentException;

final class RequirementIntake
{
    private const SOURCE_KINDS=['text','transcript'];
    private const FACT_FIELDS=['problem','user','client','budget','deadline'];
    private const LIST_FIELDS=['constraints','objectives','dependencies','out_of_scope'];
    private const SENSITIVE='/(?:-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?-----END [^-]*PRIVATE KEY-----|\bbearer\s+[A-Za-z0-9._~+\/-]{8,}|\b(?:password|passwd|token|secret|api[_ -]?key|private[_ -]?key|dsn|otp|recovery[_ -]?code|session[_ -]?token)\s*[:=]\s*[^\s,;]+|\b(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|\b(?:sk|rk|pk)-[A-Za-z0-9_-]{12,})/i';

    public static function normalize(array $raw): array
    {
        self::fields($raw,['version','source_kind','source_ref','captured_at','content'],'RequirementInput');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('RequirementInput version invalid.');
        $kind=self::enum($raw['source_kind'],self::SOURCE_KINDS,'source_kind');
        $ref=self::ref($raw['source_ref'],'source_ref');
        $at=self::positiveInt($raw['captured_at'],'captured_at');
        [$intent,$redactions]=self::redact(self::content($raw['content']));
        $facts=self::parseExplicitFacts($intent);
        $fingerprint=self::digest(['source_ref'=>$ref,'captured_at'=>$at,'intent'=>$intent,'facts'=>$facts]);
        $out=['version'=>1,'draft_ref'=>'requirement-draft:'.substr($fingerprint,0,40),'source_kind'=>$kind,
            'source_ref'=>$ref,'captured_at'=>$at,'intent'=>$intent]+$facts+['redaction_count'=>$redactions,'fingerprint'=>$fingerprint];
        self::secretFree($out); return $out;
    }

    public static function analyze(array $draftRaw,array $projectCandidatesRaw): array
    {
        $draft=self::draft($draftRaw); $match=self::projectMatch($draft,self::projects($projectCandidatesRaw)); $unknown=[];
        foreach(array_merge(self::FACT_FIELDS,self::LIST_FIELDS) as $field) if($draft[$field]['status']==='unknown') $unknown[]=$field;
        $questions=array_map(static fn(string $f):string=>'Clarify '.$f.'.',$unknown); $risks=[];
        if($unknown!==[]) $risks[]='unknown_requirements';
        if($draft['redaction_count']>0) $risks[]='sensitive_material_was_redacted';
        if($match['status']==='ambiguous') $risks[]='project_match_ambiguous';
        $slice=['slice_key'=>'discovery','title'=>'Validate requirement proposal','acceptance'=>[
            'No unknown field is converted into a factual value without explicit evidence.',
            'Project matching remains a link proposal and performs no creation or mutation.']];
        if($draft['redaction_count']>0) $slice['acceptance'][]='Redacted secret material is never restored or propagated.';
        $fingerprint=self::digest(['draft_ref'=>$draft['draft_ref'],'project_match'=>$match,'risks'=>$risks,'questions'=>$questions,'slice'=>$slice]);
        $out=['version'=>1,'proposal_ref'=>'epic-proposal:'.substr($fingerprint,0,40),'draft_ref'=>$draft['draft_ref'],
            'problem'=>$draft['problem'],'user'=>$draft['user'],'objectives'=>$draft['objectives'],'out_of_scope'=>$draft['out_of_scope'],
            'risks'=>$risks,'dependencies'=>$draft['dependencies'],'questions'=>$questions,'slices'=>[$slice],'project_match'=>$match,
            'requires_approval'=>true,'execution'=>false,'fingerprint'=>$fingerprint];
        self::secretFree($out); return $out;
    }

    private static function parseExplicitFacts(string $intent): array
    {
        $out=[]; foreach(self::FACT_FIELDS as $f) $out[$f]=self::unknownScalar(); foreach(self::LIST_FIELDS as $f) $out[$f]=self::unknownList();
        $aliases=['problem'=>'problem','user'=>'user','client'=>'client','budget'=>'budget','deadline'=>'deadline',
            'constraint'=>'constraints','constraints'=>'constraints','objective'=>'objectives','objectives'=>'objectives',
            'dependency'=>'dependencies','dependencies'=>'dependencies','out_of_scope'=>'out_of_scope','out of scope'=>'out_of_scope'];
        foreach(preg_split('/\R/u',$intent)?:[] as $line){
            if(preg_match('/^\s*([A-Za-z_ ]{3,24})\s*:\s*(.+?)\s*$/u',$line,$m)!==1) continue;
            $key=strtolower(trim($m[1])); if(!isset($aliases[$key])) continue; $field=$aliases[$key]; $value=self::factText($m[2],$field);
            if(in_array($field,self::FACT_FIELDS,true)){ $out[$field]=['status'=>'known','value'=>$value]; continue; }
            $values=array_values(array_filter(array_map('trim',preg_split('/\s*;\s*/u',$value)?:[]),static fn(string $v):bool=>$v!==''));
            if($values!==[]) $out[$field]=['status'=>'known','values'=>array_values(array_unique($values))];
        }
        return $out;
    }

    private static function draft(array $raw): array
    {
        self::fields($raw,['version','draft_ref','source_kind','source_ref','captured_at','intent','problem','user','client','budget','deadline',
            'constraints','objectives','dependencies','out_of_scope','redaction_count','fingerprint'],'RequirementDraft');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('RequirementDraft version invalid.');
        $n=['version'=>1,'draft_ref'=>self::typedRef($raw['draft_ref'],'requirement-draft','draft_ref'),
            'source_kind'=>self::enum($raw['source_kind'],self::SOURCE_KINDS,'source_kind'),'source_ref'=>self::ref($raw['source_ref'],'source_ref'),
            'captured_at'=>self::positiveInt($raw['captured_at'],'captured_at'),'intent'=>self::content($raw['intent'])];
        foreach(self::FACT_FIELDS as $f) $n[$f]=self::scalarFact($raw[$f],$f);
        foreach(self::LIST_FIELDS as $f) $n[$f]=self::listFact($raw[$f],$f);
        $n['redaction_count']=self::nonNegativeInt($raw['redaction_count'],'redaction_count'); $n['fingerprint']=self::hexDigest($raw['fingerprint'],'fingerprint');
        self::secretFree($n); $facts=[]; foreach(array_merge(self::FACT_FIELDS,self::LIST_FIELDS) as $f) $facts[$f]=$n[$f];
        $expected=self::digest(['source_ref'=>$n['source_ref'],'captured_at'=>$n['captured_at'],'intent'=>$n['intent'],'facts'=>$facts]);
        if($n['fingerprint']!==$expected||$n['draft_ref']!=='requirement-draft:'.substr($expected,0,40))
            throw new InvalidArgumentException('RequirementDraft integrity invalid.');
        return $n;
    }

    private static function projects(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>50) throw new InvalidArgumentException('project candidates invalid.');
        $out=[]; $seen=[];
        foreach($rows as $row){
            self::fields($row,['project_ref','title','aliases'],'ProjectCandidate'); $ref=self::projectRef($row['project_ref']);
            if(isset($seen[$ref])) throw new InvalidArgumentException('project candidate duplicated.'); $seen[$ref]=true;
            if(!is_array($row['aliases'])||!array_is_list($row['aliases'])||count($row['aliases'])>20) throw new InvalidArgumentException('project aliases invalid.');
            $aliases=[]; foreach($row['aliases'] as $a) $aliases[]=self::factText($a,'project alias');
            $out[]=['project_ref'=>$ref,'title'=>self::factText($row['title'],'project title'),'aliases'=>array_values(array_unique($aliases))];
        }
        return $out;
    }

    private static function projectMatch(array $draft,array $projects): array
    {
        $haystack=strtolower($draft['intent']); $scored=[];
        foreach($projects as $project){
            $score=0; $matched=[]; foreach(array_values(array_unique(array_merge([$project['title']],$project['aliases']))) as $term){
                $needle=strtolower($term); if(strlen($needle)>=3&&str_contains($haystack,$needle)){ $score++; $matched[]=$term; }
            }
            if($score>0) $scored[]=['project_ref'=>$project['project_ref'],'score'=>$score,'matched_terms'=>$matched];
        }
        usort($scored,static fn(array $a,array $b):int=>[$b['score'],$a['project_ref']]<=>[$a['score'],$b['project_ref']]);
        if($scored===[]) return ['status'=>'none','project_ref'=>null,'score'=>0,'matched_terms'=>[],'action'=>'no_match','auto_create'=>false];
        $top=$scored[0]; $ambiguous=isset($scored[1])&&$scored[1]['score']===$top['score'];
        return ['status'=>$ambiguous?'ambiguous':'matched','project_ref'=>$ambiguous?null:$top['project_ref'],'score'=>$top['score'],
            'matched_terms'=>$ambiguous?[]:$top['matched_terms'],'action'=>$ambiguous?'review_matches':'link_proposal','auto_create'=>false];
    }

    private static function redact(string $value): array
    {
        $count=0; $redacted=preg_replace(self::SENSITIVE,'[REDACTED]',$value,-1,$count);
        if($redacted===null) throw new InvalidArgumentException('requirement content invalid.');
        $redacted=preg_replace('/[ \t]+/u',' ',$redacted)??$redacted; $redacted=preg_replace('/ *\R */u',"\n",$redacted)??$redacted;
        return [trim($redacted),$count];
    }

    private static function scalarFact(mixed $raw,string $label): array
    {
        self::fields($raw,['status','value'],$label); $status=self::enum($raw['status'],['known','unknown'],$label.'.status');
        if($status==='unknown'){ if($raw['value']!==null) throw new InvalidArgumentException($label.' unknown value invalid.'); return self::unknownScalar(); }
        return ['status'=>'known','value'=>self::factText($raw['value'],$label.'.value')];
    }

    private static function listFact(mixed $raw,string $label): array
    {
        self::fields($raw,['status','values'],$label); $status=self::enum($raw['status'],['known','unknown'],$label.'.status');
        if(!is_array($raw['values'])||!array_is_list($raw['values'])||count($raw['values'])>50) throw new InvalidArgumentException($label.' values invalid.');
        if($status==='unknown'){ if($raw['values']!==[]) throw new InvalidArgumentException($label.' unknown values invalid.'); return self::unknownList(); }
        if($raw['values']===[]) throw new InvalidArgumentException($label.' known values invalid.');
        $out=[]; foreach($raw['values'] as $v) $out[]=self::factText($v,$label.'.value'); return ['status'=>'known','values'=>array_values(array_unique($out))];
    }

    private static function unknownScalar(): array { return ['status'=>'unknown','value'=>null]; }
    private static function unknownList(): array { return ['status'=>'unknown','values'=>[]]; }
    private static function content(mixed $v): string { if(!is_string($v)||trim($v)===''||strlen($v)>8000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$v)===1) throw new InvalidArgumentException('requirement content invalid.'); return trim($v); }
    private static function factText(mixed $v,string $label): string { if(!is_string($v)||trim($v)===''||strlen($v)>500||preg_match('/[\x00-\x1F\x7F]/',$v)===1) throw new InvalidArgumentException($label.' invalid.'); self::secretFree($v); return trim($v); }
    private static function projectRef(mixed $v): string { if(!is_string($v)||preg_match('/^controlbot:project\/[a-z][a-z0-9._-]{0,79}$/D',$v)!==1) throw new InvalidArgumentException('project_ref invalid.'); return $v; }
    private static function ref(mixed $v,string $label): string { if(!is_string($v)||preg_match('/^controlbot:[A-Za-z0-9][A-Za-z0-9._:\/#@-]{0,179}$/D',$v)!==1) throw new InvalidArgumentException($label.' invalid.'); self::secretFree($v); return $v; }
    private static function typedRef(mixed $v,string $ns,string $label): string { if(!is_string($v)||preg_match('/^'.preg_quote($ns,'/').':[a-f0-9]{40}$/D',$v)!==1) throw new InvalidArgumentException($label.' invalid.'); return $v; }
    private static function enum(mixed $v,array $allowed,string $label): string { $i=is_string($v)?array_search($v,$allowed,true):false; if($i===false) throw new InvalidArgumentException($label.' invalid.'); return $allowed[$i]; }
    private static function positiveInt(mixed $v,string $label): int { return self::boundedInt($v,$label,1); }
    private static function nonNegativeInt(mixed $v,string $label): int { return self::boundedInt($v,$label,0); }
    private static function boundedInt(mixed $v,string $label,int $min): int { if(!is_int($v)||$v<$min) throw new InvalidArgumentException($label.' invalid.'); return $v; }
    private static function hexDigest(mixed $v,string $label): string { if(!is_string($v)||strlen($v)!==64||!ctype_xdigit($v)||strtolower($v)!==$v) throw new InvalidArgumentException($label.' invalid.'); return $v; }
    private static function digest(array $v): string { return hash('sha256',serialize(self::ordered($v))); }
    private static function ordered(mixed $v): mixed { if(!is_array($v)) return $v; if(!array_is_list($v)) ksort($v,SORT_STRING); foreach($v as $k=>$x) $v[$k]=self::ordered($x); return $v; }
    private static function secretFree(mixed $v): void { $s=is_string($v)?$v:json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); if(preg_match(self::SENSITIVE,$s)===1) throw new InvalidArgumentException('Requirement contract contains sensitive material.'); }
    private static function fields(mixed $row,array $expected,string $label): void { if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.'); $keys=array_keys($row); if(count($keys)!==count($expected)||array_diff($keys,$expected)!==[]||array_diff($expected,$keys)!==[]) throw new InvalidArgumentException($label.' fields invalid.'); }
}

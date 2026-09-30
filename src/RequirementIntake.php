<?php
declare(strict_types=1);
namespace ControlBot\Requirements;
use InvalidArgumentException;

final class RequirementIntake
{
    private const FACT=['problem','user','client','budget','deadline'];
    private const LIST=['constraints','objectives','dependencies','out_of_scope'];
    private const SENSITIVE='/(?:-----BEGIN [^-]*PRIVATE KEY-----[\\s\\S]*?-----END [^-]*PRIVATE KEY-----|\\bbearer\\s+[A-Za-z0-9._~+\\/-]{8,}|\\b(?:password|passwd|token|secret|api[_ -]?key|private[_ -]?key|dsn|otp|recovery[_ -]?code|session[_ -]?token)\\s*[:=]\\s*[^\\s,;]+|\\b(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|\\b(?:sk|rk|pk)-[A-Za-z0-9_-]{12,})/i';

    public static function normalize(array $r): array
    {
        self::fields($r,['version','source_kind','source_ref','captured_at','content'],'RequirementInput');
        if(($r['version']??null)!==1) self::bad('RequirementInput version');
        $kind=self::one($r['source_kind'],['text','transcript'],'source_kind');
        $ref=self::ref($r['source_ref'],'source_ref'); $at=self::int($r['captured_at'],'captured_at',1);
        [$intent,$redactions]=self::redact(self::content($r['content'])); $facts=self::parse($intent);
        $fp=self::digest(['source_ref'=>$ref,'captured_at'=>$at,'intent'=>$intent,'facts'=>$facts]);
        $out=['version'=>1,'draft_ref'=>'requirement-draft:'.substr($fp,0,40),'source_kind'=>$kind,'source_ref'=>$ref,
            'captured_at'=>$at,'intent'=>$intent,'redaction_count'=>$redactions,'fingerprint'=>$fp]+$facts;
        self::secretFree($out); return $out;
    }

    public static function analyze(array $raw,array $projects): array
    {
        $d=self::draft($raw); $match=self::match($d,self::projects($projects)); $unknown=[];
        foreach(array_merge(self::FACT,self::LIST) as $f) if($d[$f]['status']==='unknown') $unknown[]=$f;
        $questions=array_map(static fn($f)=>"Clarify $f.",$unknown); $risks=[];
        if($unknown) $risks[]='unknown_requirements';
        if($d['redaction_count']) $risks[]='sensitive_material_was_redacted';
        if($match['status']==='ambiguous') $risks[]='project_match_ambiguous';
        $slice=['slice_key'=>'discovery','title'=>'Validate requirement proposal','acceptance'=>[
            'No unknown field is converted into a factual value without explicit evidence.',
            'Project matching remains a link proposal and performs no creation or mutation.',
        ]];
        if($d['redaction_count']) $slice['acceptance'][]='Redacted secret material is never restored or propagated.';
        $fp=self::digest(['draft_ref'=>$d['draft_ref'],'project_match'=>$match,'risks'=>$risks,'questions'=>$questions,'slice'=>$slice]);
        $out=['version'=>1,'proposal_ref'=>'epic-proposal:'.substr($fp,0,40),'draft_ref'=>$d['draft_ref'],
            'problem'=>$d['problem'],'user'=>$d['user'],'objectives'=>$d['objectives'],'out_of_scope'=>$d['out_of_scope'],
            'risks'=>$risks,'dependencies'=>$d['dependencies'],'questions'=>$questions,'slices'=>[$slice],
            'project_match'=>$match,'requires_approval'=>true,'execution'=>false,'fingerprint'=>$fp];
        self::secretFree($out); return $out;
    }

    private static function parse(string $intent): array
    {
        $out=[]; foreach(self::FACT as $f) $out[$f]=['status'=>'unknown','value'=>null];
        foreach(self::LIST as $f) $out[$f]=['status'=>'unknown','values'=>[]];
        $aliases=['problem'=>'problem','user'=>'user','client'=>'client','budget'=>'budget','deadline'=>'deadline',
            'constraint'=>'constraints','constraints'=>'constraints','objective'=>'objectives','objectives'=>'objectives',
            'dependency'=>'dependencies','dependencies'=>'dependencies','out_of_scope'=>'out_of_scope','out of scope'=>'out_of_scope'];
        foreach(preg_split('/\\R/u',$intent)?:[] as $line){
            if(preg_match('/^\\s*([A-Za-z_ ]{3,24})\\s*:\\s*(.+?)\\s*$/u',$line,$m)!==1) continue;
            $key=strtolower(trim($m[1])); if(!isset($aliases[$key])) continue; $f=$aliases[$key]; $v=self::text($m[2],$f);
            if(in_array($f,self::FACT,true)){ $out[$f]=['status'=>'known','value'=>$v]; continue; }
            $vals=array_values(array_unique(array_filter(array_map('trim',preg_split('/\\s*;\\s*/u',$v)?:[]))));
            if($vals) $out[$f]=['status'=>'known','values'=>$vals];
        }
        return $out;
    }

    private static function draft(array $r): array
    {
        $fields=['version','draft_ref','source_kind','source_ref','captured_at','intent','problem','user','client','budget',
            'deadline','constraints','objectives','dependencies','out_of_scope','redaction_count','fingerprint'];
        self::fields($r,$fields,'RequirementDraft'); if(($r['version']??null)!==1) self::bad('RequirementDraft version');
        $n=['version'=>1,'draft_ref'=>self::typed($r['draft_ref'],'requirement-draft','draft_ref'),
            'source_kind'=>self::one($r['source_kind'],['text','transcript'],'source_kind'),'source_ref'=>self::ref($r['source_ref'],'source_ref'),
            'captured_at'=>self::int($r['captured_at'],'captured_at',1),'intent'=>self::content($r['intent']),
            'redaction_count'=>self::int($r['redaction_count'],'redaction_count',0),'fingerprint'=>self::hex($r['fingerprint'],'fingerprint')];
        foreach(self::FACT as $f) $n[$f]=self::fact($r[$f],$f,false);
        foreach(self::LIST as $f) $n[$f]=self::fact($r[$f],$f,true);
        self::secretFree($n); $facts=[]; foreach(array_merge(self::FACT,self::LIST) as $f) $facts[$f]=$n[$f];
        $expected=self::digest(['source_ref'=>$n['source_ref'],'captured_at'=>$n['captured_at'],'intent'=>$n['intent'],'facts'=>$facts]);
        if($n['fingerprint']!==$expected||$n['draft_ref']!=='requirement-draft:'.substr($expected,0,40)) self::bad('RequirementDraft integrity');
        return $n;
    }

    private static function projects(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>50) self::bad('project candidates');
        $out=[];$seen=[];
        foreach($rows as $r){
            self::fields($r,['project_ref','title','aliases'],'ProjectCandidate'); $ref=self::projectRef($r['project_ref']);
            if(isset($seen[$ref])) self::bad('project candidate duplicated'); $seen[$ref]=1;
            if(!is_array($r['aliases'])||!array_is_list($r['aliases'])||count($r['aliases'])>20) self::bad('project aliases');
            $aliases=[]; foreach($r['aliases'] as $a) $aliases[]=self::text($a,'project alias');
            $out[]=['project_ref'=>$ref,'title'=>self::text($r['title'],'project title'),'aliases'=>array_values(array_unique($aliases))];
        }
        return $out;
    }

    private static function match(array $d,array $projects): array
    {
        $hay=strtolower($d['intent']); $hits=[];
        foreach($projects as $p){
            $score=0;$terms=[];
            foreach(array_unique(array_merge([$p['title']],$p['aliases'])) as $term){
                if(strlen($term)>=3&&str_contains($hay,strtolower($term))){$score++;$terms[]=$term;}
            }
            if($score) $hits[]=['project_ref'=>$p['project_ref'],'score'=>$score,'matched_terms'=>$terms];
        }
        usort($hits,static fn($a,$b)=>[$b['score'],$a['project_ref']]<=>[$a['score'],$b['project_ref']]);
        if(!$hits) return ['status'=>'none','project_ref'=>null,'score'=>0,'matched_terms'=>[],'action'=>'no_match','auto_create'=>false];
        $top=$hits[0]; $amb=isset($hits[1])&&$hits[1]['score']===$top['score'];
        return ['status'=>$amb?'ambiguous':'matched','project_ref'=>$amb?null:$top['project_ref'],'score'=>$top['score'],
            'matched_terms'=>$amb?[]:$top['matched_terms'],'action'=>$amb?'review_matches':'link_proposal','auto_create'=>false];
    }

    private static function fact(mixed $r,string $label,bool $list): array
    {
        self::fields($r,$list?['status','values']:['status','value'],$label); $s=self::one($r['status'],['known','unknown'],"$label.status");
        if(!$list){
            if($s==='unknown'){ if($r['value']!==null) self::bad("$label unknown value"); return ['status'=>'unknown','value'=>null]; }
            return ['status'=>'known','value'=>self::text($r['value'],"$label.value")];
        }
        if(!is_array($r['values'])||!array_is_list($r['values'])||count($r['values'])>50) self::bad("$label values");
        if($s==='unknown'){ if($r['values']) self::bad("$label unknown values"); return ['status'=>'unknown','values'=>[]]; }
        if(!$r['values']) self::bad("$label known values");
        $v=[]; foreach($r['values'] as $x) $v[]=self::text($x,"$label.value");
        return ['status'=>'known','values'=>array_values(array_unique($v))];
    }

    private static function redact(string $v): array
    {
        $n=0; $v=preg_replace(self::SENSITIVE,'[REDACTED]',$v,-1,$n); if($v===null) self::bad('requirement content');
        $v=preg_replace('/[ \\t]+/u',' ',$v)??$v; $v=preg_replace('/ *\\R */u',"\n",$v)??$v; return [trim($v),$n];
    }
    private static function content(mixed $v): string
    { if(!is_string($v)||trim($v)===''||strlen($v)>8000||preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/',$v)) self::bad('requirement content'); return trim($v); }
    private static function text(mixed $v,string $l): string
    { if(!is_string($v)||trim($v)===''||strlen($v)>500||preg_match('/[\\x00-\\x1F\\x7F]/',$v)) self::bad($l); self::secretFree($v); return trim($v); }
    private static function ref(mixed $v,string $l): string
    { if(!is_string($v)||preg_match('/^controlbot:[A-Za-z0-9][A-Za-z0-9._:\\/#@-]{0,179}$/D',$v)!==1) self::bad($l); self::secretFree($v); return $v; }
    private static function projectRef(mixed $v): string
    { if(!is_string($v)||preg_match('/^controlbot:project\\/[a-z][a-z0-9._-]{0,79}$/D',$v)!==1) self::bad('project_ref'); return $v; }
    private static function typed(mixed $v,string $ns,string $l): string
    { if(!is_string($v)||preg_match('/^'.preg_quote($ns,'/').':[a-f0-9]{40}$/D',$v)!==1) self::bad($l); return $v; }
    private static function one(mixed $v,array $a,string $l): string
    { if(!is_string($v)||!in_array($v,$a,true)) self::bad($l); return $v; }
    private static function int(mixed $v,string $l,int $min): int
    { if(!is_int($v)||$v<$min) self::bad($l); return $v; }
    private static function hex(mixed $v,string $l): string
    { if(!is_string($v)||!preg_match('/^[a-f0-9]{64}$/D',$v)) self::bad($l); return $v; }
    private static function digest(array $v): string { return hash('sha256',serialize(self::ordered($v))); }
    private static function ordered(mixed $v): mixed
    { if(!is_array($v)) return $v; if(!array_is_list($v)) ksort($v,SORT_STRING); foreach($v as $k=>$x)$v[$k]=self::ordered($x); return $v; }
    private static function secretFree(mixed $v): void
    { $s=is_string($v)?$v:json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); if(preg_match(self::SENSITIVE,$s)) self::bad('Requirement contract contains sensitive material'); }
    private static function fields(mixed $r,array $e,string $l): void
    { if(!is_array($r)||array_is_list($r)) self::bad($l); $k=array_keys($r); if(count($k)!==count($e)||array_diff($k,$e)||array_diff($e,$k)) self::bad("$l fields"); }
    private static function bad(string $m): never { throw new InvalidArgumentException("$m invalid."); }
}

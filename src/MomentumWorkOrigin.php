<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use InvalidArgumentException;

final class MomentumWorkOrigin
{
    private const TYPES=['marketing_growth','content','data_analytics','sales_support'];
    private const PRIORITIES=['critical','high','medium'];
    private const FRESH=['current','stale','unknown'];
    private const POLICY='controlbot:policy/business-os-v1';
    private const SECRET='/(?:bearer\s+|password|passwd|secret|token|api[_-]?key|private[_-]?key|gh[pousr]_|github_pat_|sk-)/i';

    public static function materialize(
        array $raw,array $campaign,array $creative,array $email,array $experiment,array $paid,array $performance,
    ): array {
        try {
            self::fields($raw,[
                'version','group_id','venture_id','project_id','repository_ref','work_type','requested_capabilities',
                'required_roles','priority_class','depends_on','claims','evidence_refs','freshness','observed_at',
                'idempotency_key','execution',
            ],'MomentumWorkOrigin');
            if($raw['version']!==1||$raw['execution']!==false) throw new InvalidArgumentException('version/execution invalid.');
            $venture=self::ref($raw['venture_id'],'venture_id');
            $campaignId=self::contracts($venture,$campaign,$creative,$email,$experiment,$paid,$performance);
            $fresh=self::choice($raw['freshness'],self::FRESH,'freshness');
            $reasons=self::gates($fresh,$email,$experiment,$paid,$performance);
            if($reasons!==[]) return self::blocked($reasons,$venture,$campaignId,self::gateFreshness($fresh,$experiment,$paid,$performance));

            $type=self::choice($raw['work_type'],self::TYPES,'work_type');
            $key=self::text($raw['idempotency_key'],'idempotency_key',128);
            $authority=strtolower(self::text($paid['authority']['required_authority_level']??null,'authority_level',64));
            $item=[
                'work_id'=>'momentum:work/'.substr(hash('sha256',$venture.'|'.$campaignId.'|'.$type.'|'.$key),0,40),
                'origin_mode'=>'automatic','origin_system'=>'momentum','group_id'=>self::ref($raw['group_id'],'group_id'),
                'venture_id'=>$venture,'work_type'=>$type,
                'requested_capabilities'=>self::slugs($raw['requested_capabilities'],'requested_capabilities'),
                'required_roles'=>self::slugs($raw['required_roles'],'required_roles'),
                'authority_level'=>self::slug($authority,'authority_level'),'producer_ref'=>'momentum:work-origin',
                'priority_class'=>self::choice($raw['priority_class'],self::PRIORITIES,'priority_class'),
                'depends_on'=>self::items($raw['depends_on'],'depends_on',true),'claims'=>self::items($raw['claims'],'claims',true),
                'budget_ref'=>self::ref($paid['spend']['budget_ref']??null,'budget_ref'),'policy_ref'=>self::POLICY,
                'evidence_refs'=>self::evidence($raw['evidence_refs'],$campaign,$creative,$email,$experiment,$paid,$performance),
                'observed_at'=>self::timestamp($raw['observed_at']),'idempotency_key'=>$key,
            ];
            if($raw['project_id']!==null) $item['project_id']=self::ref($raw['project_id'],'project_id');
            if($raw['repository_ref']!==null) $item['repository_ref']=self::repository($raw['repository_ref']);
            return ['version'=>1,'status'=>'materialized','reasons'=>['factory_handoff_ready'],'freshness'=>'current',
                'work_item'=>$item,'gate_ref'=>null,'execution'=>false];
        } catch(InvalidArgumentException) {
            return self::blocked(['invalid_input'],'unknown','unknown','unknown');
        }
    }

    private static function contracts(
        string $venture,array $campaign,array $creative,array $email,array $experiment,array $paid,array $performance,
    ): string {
        $campaignId=self::ref($campaign['campaign_id']??null,'campaign_id');
        if(($campaign['venture_id']??null)!==$venture||($campaign['execution']??null)!==false
            ||!in_array($campaign['status']??null,['planned','approved','active'],true)) throw new InvalidArgumentException('Campaign invalid.');
        if(($creative['venture_id']??null)!==$venture||($creative['status']??null)!=='approved'
            ||!in_array($creative['variant_id']??null,$campaign['creative_variant_refs']??[],true)) throw new InvalidArgumentException('Creative invalid.');
        if(($email['venture_id']??null)!==$venture||($email['audience_ref']??null)!==($campaign['audience_ref']??null)
            ||($email['execution']??null)!==false) throw new InvalidArgumentException('Email invalid.');
        if(($experiment['venture_id']??null)!==$venture||($experiment['campaign_id']??null)!==$campaignId
            ||($experiment['execution']??null)!==false) throw new InvalidArgumentException('Experiment invalid.');
        if(($paid['venture_id']??null)!==$venture||($paid['campaign_id']??null)!==$campaignId
            ||($paid['execution']??null)!==false) throw new InvalidArgumentException('Paid Media invalid.');
        if(($performance['venture_id']??null)!==$venture||($performance['campaign_ref']??null)!==$campaignId
            ||($performance['execution']??null)!==false) throw new InvalidArgumentException('Performance invalid.');
        return $campaignId;
    }

    private static function gates(string $fresh,array $email,array $experiment,array $paid,array $performance): array
    {
        $r=[];
        if($fresh!=='current') $r[]='origin_evidence_'.$fresh;
        if(($email['marketing_eligible']??null)!==true) $r[]='email_not_eligible';
        $result=$experiment['result']??[];
        if(($experiment['status']??null)!=='completed') $r[]='experiment_not_completed';
        if(($result['state']??'unknown')==='unknown') $r[]='experiment_result_unknown';
        if(($result['freshness']??'unknown')!=='current') $r[]='experiment_evidence_'.($result['freshness']??'unknown');
        if(($paid['freshness']??'unknown')!=='current') $r[]='paid_media_evidence_'.($paid['freshness']??'unknown');
        foreach(['authority','capital'] as $gate){
            $d=$paid[$gate]['decision']??'unknown';
            if($d==='owner_decision_required') $r[]=$gate.'_owner_gate'; elseif($d==='deny') $r[]=$gate.'_denied'; elseif($d!=='allow') $r[]=$gate.'_unknown';
        }
        if(($paid['status']??'unknown')!=='planned'){
            foreach($paid['reasons']??[] as $reason) if(is_string($reason)) $r[]=self::slug($reason,'reason');
            if(($paid['reasons']??[])===[]) $r[]='paid_media_not_ready';
        }
        if(($performance['freshness']??'unknown')!=='current') $r[]='performance_evidence_'.($performance['freshness']??'unknown');
        return array_values(array_unique($r));
    }

    private static function gateFreshness(string $origin,array $experiment,array $paid,array $performance): string
    {
        foreach([$origin,$experiment['result']['freshness']??'unknown',$paid['freshness']??'unknown',$performance['freshness']??'unknown'] as $v)
            if($v!=='current') return in_array($v,self::FRESH,true)?$v:'unknown';
        return 'current';
    }
    private static function blocked(array $reasons,string $venture,string $campaign,string $fresh): array
    {return ['version'=>1,'status'=>'blocked','reasons'=>$reasons,'freshness'=>$fresh,'work_item'=>null,
        'gate_ref'=>'momentum:gate/'.substr(hash('sha256',$venture.'|'.$campaign.'|'.implode('|',$reasons)),0,40),'execution'=>false];}

    private static function evidence(array $extra,array ...$rows): array
    {
        $out=[];foreach($extra as $v)$out[self::ref($v,'evidence_ref')]=true;
        $push=static function(mixed $v) use (&$out):void{if($v===null)return;$out[self::ref($v,'upstream_evidence_ref')]=true;};
        foreach($rows as $row){
            foreach(['campaign_id','variant_id','email_program_id','experiment_id','performance_id','source_ref'] as $k)$push($row[$k]??null);
            foreach(['evidence_refs','provenance_refs'] as $k)if(is_array($row[$k]??null))foreach($row[$k] as $v)$push($v);
            foreach(['consent','suppression','unsubscribe'] as $k)if(is_array($row[$k]??null))$push($row[$k]['source_ref']??null);
            if(is_array($row['spend']??null))foreach($row['spend']['evidence_refs']??[] as $v)$push($v);
            if(is_array($row['result']??null))foreach($row['result']['evidence_refs']??[] as $v)$push($v);
        }
        $refs=array_keys($out);sort($refs,SORT_STRING);return $refs;
    }
    private static function items(mixed $v,string $label,bool $empty):array
    {if(!is_array($v)||!array_is_list($v)||count($v)>50||(!$empty&&$v===[]))throw new InvalidArgumentException($label.' invalid.');$o=[];foreach($v as $x)$o[self::ref($x,$label)]=true;$o=array_keys($o);sort($o,SORT_STRING);return $o;}
    private static function slugs(mixed $v,string $label):array
    {if(!is_array($v)||!array_is_list($v)||$v===[]||count($v)>50)throw new InvalidArgumentException($label.' invalid.');$o=[];foreach($v as $x)$o[self::slug($x,$label)]=true;$o=array_keys($o);sort($o,SORT_STRING);return $o;}
    private static function repository(mixed $v):string
    {if(is_string($v)&&preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$v)===1)return $v;throw new InvalidArgumentException('repository_ref invalid.');}
    private static function timestamp(mixed $v):string
    {if(is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$v)===1)return $v;throw new InvalidArgumentException('observed_at invalid.');}
    private static function ref(mixed $v,string $label):string
    {$v=is_string($v)?trim($v):'';if($v===''||strlen($v)>240||str_contains($v,'@')||strpbrk($v,"\n\r\0")!==false||preg_match(self::SECRET,$v)===1)throw new InvalidArgumentException($label.' invalid.');return $v;}
    private static function text(mixed $v,string $label,int $max):string
    {$v=self::ref($v,$label);if(strlen($v)>$max)throw new InvalidArgumentException($label.' invalid.');return $v;}
    private static function slug(mixed $v,string $label):string
    {if(is_string($v)&&preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$v)===1)return $v;throw new InvalidArgumentException($label.' invalid.');}
    private static function choice(mixed $v,array $allowed,string $label):string
    {if(is_string($v)&&in_array($v,$allowed,true))return $v;throw new InvalidArgumentException($label.' invalid.');}
    private static function fields(mixed $row,array $expected,string $label):void
    {if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');$a=array_keys($row);sort($a);sort($expected);if($a!==$expected)throw new InvalidArgumentException($label.' fields invalid.');}
}

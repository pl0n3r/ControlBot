<?php
declare(strict_types=1);
namespace ControlBot\Business;
use InvalidArgumentException;

final class ProductDiscoveryWorkOrigin
{
    private const WORK_TYPES=['engineering','security','infrastructure','operations','data_analytics','product','content','marketing_growth','sales_support','finance_analysis','compliance_review','knowledge_documentation'];
    private const PRIORITIES=['critical','high','medium'];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const SENSITIVE='/(?:bearer\s+|password|passwd|secret|token|api[_ -]?key|private[_ -]?key|cookie|authorization|dsn|gh[pousr]_|github_pat_|sk-)/i';
    private const DIRECT_PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?[0-9][0-9(). -]{7,}[0-9])/i';

    public static function materialize(array $raw,array $decisionRaw,array $assessmentRaw,array $experimentRaw,array $initiativeRaw,array $hypothesisRaw,array $outcomeRaw,array $baselineRaw,array $variantRaw,string $expectedVentureId,string $expectedProductId): array
    {
        try {
            self::fields($raw,['version','work_id','group_id','project_id','repository_ref','work_type','requested_capabilities','required_roles','authority_level','priority_class','policy_ref','depends_on','claims','execution']);
            if(($raw['version']??null)!==1||($raw['execution']??null)!==false) throw new InvalidArgumentException('version/execution invalid.');
            $workId=self::ref($raw['work_id'],'work_id'); $groupId=self::ref($raw['group_id'],'group_id');
            $projectId=$raw['project_id']===null?null:self::ref($raw['project_id'],'project_id');
            $repository=$raw['repository_ref']===null?null:self::repository($raw['repository_ref']);
            $workType=self::choice($raw['work_type'],self::WORK_TYPES,'work_type');
            $capabilities=self::list($raw['requested_capabilities'],'requested_capabilities',false,true);
            $roles=self::list($raw['required_roles'],'required_roles',false,true);
            $authority=self::slug($raw['authority_level'],'authority_level');
            $priority=self::choice($raw['priority_class'],self::PRIORITIES,'priority_class');
            $policy=self::ref($raw['policy_ref'],'policy_ref');
            $dependsOn=self::list($raw['depends_on'],'depends_on',true,false); $claims=self::list($raw['claims'],'claims',true,false);
            $decision=ProductDiscoveryDecision::decide($decisionRaw,$assessmentRaw,$experimentRaw,$initiativeRaw,$hypothesisRaw,$outcomeRaw,$baselineRaw,$variantRaw,$expectedVentureId,$expectedProductId);
            $fresh=self::choice($decision['freshness']??null,self::FRESHNESS,'freshness');
            $observed=self::observedAt($decision['evaluation_window']??null); $prov=self::provenance($decision,$observed);
            $reasons=[];
            if(($decision['decision']??null)!=='BUILD') $reasons[]='decision_not_build';
            if(($decision['classification']??null)!=='VALIDATED') $reasons[]='discovery_not_validated';
            if($fresh!=='fresh') $reasons[]='discovery_evidence_'.$fresh;
            if($reasons!==[]) return self::blocked($reasons,$fresh,$prov);
            $item=['work_id'=>$workId,'origin_mode'=>'automatic','origin_system'=>'controlbot','group_id'=>$groupId,'venture_id'=>$decision['venture_id'],'work_type'=>$workType,'requested_capabilities'=>$capabilities,'required_roles'=>$roles,'authority_level'=>$authority,'producer_ref'=>'controlbot:product-discovery','priority_class'=>$priority,'depends_on'=>$dependsOn,'claims'=>$claims,'policy_ref'=>$policy,'evidence_refs'=>self::evidence($prov),'observed_at'=>$observed,'idempotency_key'=>self::idempotency($decision,$groupId,$projectId,$workType)];
            if($projectId!==null) $item['project_id']=$projectId; if($repository!==null) $item['repository_ref']=$repository;
            $result=['version'=>1,'status'=>'materialized','reasons'=>['factory_work_item_materialized'],'freshness'=>$fresh,'work_item'=>$item,'provenance'=>$prov,'gate_ref'=>null,'execution'=>false];
            self::safe($result); return $result;
        } catch(InvalidArgumentException) { return self::blocked(['invalid_input'],'unknown',null); }
    }

    private static function provenance(array $d,string $observed): array
    {
        $p=[];
        foreach(['initiative_id','hypothesis_ref','experiment_ref','assessment_ref','decision_ref','decision_reason_ref','assessment_rule_ref','source_ref','evidence_ref'] as $k) $p[$k]=self::ref($d[$k]??null,$k);
        foreach(['decision_evidence_refs','assessment_evidence_refs'] as $k) $p[$k]=self::list($d[$k]??null,$k,false,false,false);
        $p['freshness']=self::choice($d['freshness']??null,self::FRESHNESS,'freshness'); $p['observed_at']=$observed; return $p;
    }

    private static function evidence(array $p): array
    {
        $set=[]; foreach($p as $k=>$v){ if(in_array($k,['freshness','observed_at'],true)) continue; foreach(is_array($v)?$v:[$v] as $ref) $set[$ref]=true; }
        $refs=array_keys($set); sort($refs,SORT_STRING); return $refs;
    }

    private static function idempotency(array $d,string $group,?string $project,string $type): string
    {
        $parts=[$group,$project??'',$d['venture_id'],$d['product_id'],$type,$d['initiative_id'],$d['hypothesis_ref'],$d['experiment_ref'],$d['assessment_ref'],$d['decision_ref']];
        return 'product-discovery:'.substr(hash('sha256',implode('|',$parts)),0,40);
    }

    private static function observedAt(mixed $w): string
    {
        if(!is_array($w)||array_is_list($w)||array_keys($w)!==['start_at','end_at']||!is_int($w['start_at'])||!is_int($w['end_at'])||$w['start_at']<0||$w['end_at']<=$w['start_at']||$w['end_at']>253402300799) throw new InvalidArgumentException('evaluation_window invalid.');
        return gmdate('Y-m-d\TH:i:s\Z',$w['end_at']);
    }

    private static function blocked(array $reasons,string $fresh,?array $prov): array
    {
        $reasons=array_values(array_unique($reasons)); sort($reasons,SORT_STRING); $seed=$prov['decision_ref']??'unknown';
        $result=['version'=>1,'status'=>'blocked','reasons'=>$reasons,'freshness'=>$fresh,'work_item'=>null,'provenance'=>$prov,'gate_ref'=>'product-discovery:gate/'.substr(hash('sha256',$seed.'|'.implode('|',$reasons)),0,40),'execution'=>false];
        self::safe($result); return $result;
    }

    private static function fields(mixed $row,array $expected): void
    { if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException('invalid object.'); $actual=array_keys($row); sort($actual); sort($expected); if($actual!==$expected) throw new InvalidArgumentException('fields invalid.'); }

    private static function list(mixed $values,string $label,bool $empty,bool $slug,bool $rejectDuplicates=true): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>50||(!$empty&&$values===[])) throw new InvalidArgumentException($label.' invalid.');
        $out=[]; foreach($values as $value){ $v=$slug?self::slug($value,$label):self::ref($value,$label); if($rejectDuplicates&&isset($out[$v])) throw new InvalidArgumentException($label.' duplicated.'); $out[$v]=true; }
        $result=array_keys($out); sort($result,SORT_STRING); return $result;
    }

    private static function repository(mixed $v): string
    { if(is_string($v)&&preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$v)===1) return $v; throw new InvalidArgumentException('repository_ref invalid.'); }
    private static function ref(mixed $v,string $label): string
    { $v=is_string($v)?trim($v):''; if($v===''||strlen($v)>240||str_contains($v,'@')||strpbrk($v,"\n\r\0")!==false||preg_match(self::SENSITIVE,$v)===1||preg_match(self::DIRECT_PII,$v)===1) throw new InvalidArgumentException($label.' invalid.'); return $v; }
    private static function slug(mixed $v,string $label): string
    { if(is_string($v)&&preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$v)===1) return $v; throw new InvalidArgumentException($label.' invalid.'); }
    private static function choice(mixed $v,array $allowed,string $label): string
    { if(is_string($v)&&in_array($v,$allowed,true)) return $v; throw new InvalidArgumentException($label.' invalid.'); }
    private static function safe(mixed $value): void
    {
        if(is_array($value)){ foreach($value as $item) self::safe($item); return; }
        if(!is_string($value)||preg_match('/^(?:\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z|product-discovery:(?:gate\/)?[a-f0-9]{40})$/D',$value)===1) return;
        if(preg_match(self::SENSITIVE,$value)===1||preg_match(self::DIRECT_PII,$value)===1) throw new InvalidArgumentException('sensitive material.');
    }
}

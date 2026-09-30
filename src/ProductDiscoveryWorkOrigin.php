<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductDiscoveryWorkOrigin
{
    private const WORK_TYPES=[
        'engineering','security','infrastructure','operations','data_analytics','product',
        'content','marketing_growth','sales_support','finance_analysis','compliance_review',
        'knowledge_documentation',
    ];
    private const PRIORITIES=['critical','high','medium'];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const SENSITIVE='/(?:bearer\s+|password|passwd|secret|token|api[_ -]?key|private[_ -]?key|cookie|authorization|dsn|gh[pousr]_|github_pat_|sk-)/i';
    private const DIRECT_PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?[0-9][0-9(). -]{7,}[0-9])/i';

    public static function materialize(
        array $raw,
        array $decisionRaw,
        array $assessmentRaw,
        array $experimentRaw,
        array $initiativeRaw,
        array $hypothesisRaw,
        array $outcomeRaw,
        array $baselineRaw,
        array $variantRaw,
        string $expectedVentureId,
        string $expectedProductId
    ): array {
        try {
            self::fields($raw,[
                'version','work_id','group_id','project_id','repository_ref','work_type',
                'requested_capabilities','required_roles','authority_level','priority_class',
                'policy_ref','depends_on','claims','execution',
            ]);
            if(($raw['version']??null)!==1||($raw['execution']??null)!==false)
                throw new InvalidArgumentException('ProductDiscoveryWorkOrigin version/execution invalid.');

            $workId=self::ref($raw['work_id'],'work_id');
            $groupId=self::ref($raw['group_id'],'group_id');
            $projectId=$raw['project_id']===null?null:self::ref($raw['project_id'],'project_id');
            $repository=$raw['repository_ref']===null?null:self::repository($raw['repository_ref']);
            $workType=self::choice($raw['work_type'],self::WORK_TYPES,'work_type');
            $capabilities=self::slugs($raw['requested_capabilities'],'requested_capabilities',false);
            $roles=self::slugs($raw['required_roles'],'required_roles',false);
            $authority=self::slug($raw['authority_level'],'authority_level');
            $priority=self::choice($raw['priority_class'],self::PRIORITIES,'priority_class');
            $policy=self::ref($raw['policy_ref'],'policy_ref');
            $dependsOn=self::refs($raw['depends_on'],'depends_on',true);
            $claims=self::refs($raw['claims'],'claims',true);

            $decision=ProductDiscoveryDecision::decide(
                $decisionRaw,$assessmentRaw,$experimentRaw,$initiativeRaw,$hypothesisRaw,
                $outcomeRaw,$baselineRaw,$variantRaw,$expectedVentureId,$expectedProductId
            );
            $freshness=self::choice($decision['freshness']??null,self::FRESHNESS,'freshness');
            $observedAt=self::observedAt($decision['evaluation_window']??null);
            $provenance=self::provenance($decision,$observedAt);

            $reasons=[];
            if(($decision['decision']??null)!=='BUILD') $reasons[]='decision_not_build';
            if(($decision['classification']??null)!=='VALIDATED') $reasons[]='discovery_not_validated';
            if($freshness!=='fresh') $reasons[]='discovery_evidence_'.$freshness;
            if($reasons!==[]) return self::blocked($reasons,$freshness,$provenance);

            $evidence=self::evidence($provenance);
            $idempotency=self::idempotency($decision,$groupId,$projectId,$workType);
            $item=[
                'work_id'=>$workId,
                'origin_mode'=>'automatic',
                'origin_system'=>'controlbot',
                'group_id'=>$groupId,
                'venture_id'=>$decision['venture_id'],
                'work_type'=>$workType,
                'requested_capabilities'=>$capabilities,
                'required_roles'=>$roles,
                'authority_level'=>$authority,
                'producer_ref'=>'controlbot:product-discovery',
                'priority_class'=>$priority,
                'depends_on'=>$dependsOn,
                'claims'=>$claims,
                'policy_ref'=>$policy,
                'evidence_refs'=>$evidence,
                'observed_at'=>$observedAt,
                'idempotency_key'=>$idempotency,
            ];
            if($projectId!==null) $item['project_id']=$projectId;
            if($repository!==null) $item['repository_ref']=$repository;

            $result=[
                'version'=>1,
                'status'=>'materialized',
                'reasons'=>['factory_work_item_materialized'],
                'freshness'=>$freshness,
                'work_item'=>$item,
                'provenance'=>$provenance,
                'gate_ref'=>null,
                'execution'=>false,
            ];
            self::safe($result);
            return $result;
        } catch(InvalidArgumentException) {
            return self::blocked(['invalid_input'],'unknown',null);
        }
    }

    private static function provenance(array $decision,string $observedAt): array
    {
        return [
            'initiative_id'=>self::ref($decision['initiative_id']??null,'initiative_id'),
            'hypothesis_ref'=>self::ref($decision['hypothesis_ref']??null,'hypothesis_ref'),
            'experiment_ref'=>self::ref($decision['experiment_ref']??null,'experiment_ref'),
            'assessment_ref'=>self::ref($decision['assessment_ref']??null,'assessment_ref'),
            'decision_ref'=>self::ref($decision['decision_ref']??null,'decision_ref'),
            'decision_reason_ref'=>self::ref($decision['decision_reason_ref']??null,'decision_reason_ref'),
            'assessment_rule_ref'=>self::ref($decision['assessment_rule_ref']??null,'assessment_rule_ref'),
            'source_ref'=>self::ref($decision['source_ref']??null,'source_ref'),
            'evidence_ref'=>self::ref($decision['evidence_ref']??null,'evidence_ref'),
            'decision_evidence_refs'=>self::derivedRefs($decision['decision_evidence_refs']??null,'decision_evidence_refs'),
            'assessment_evidence_refs'=>self::derivedRefs($decision['assessment_evidence_refs']??null,'assessment_evidence_refs'),
            'freshness'=>self::choice($decision['freshness']??null,self::FRESHNESS,'freshness'),
            'observed_at'=>$observedAt,
        ];
    }

    private static function evidence(array $provenance): array
    {
        $set=[];
        foreach(['initiative_id','hypothesis_ref','experiment_ref','assessment_ref','decision_ref','decision_reason_ref','assessment_rule_ref','source_ref','evidence_ref'] as $key)
            $set[$provenance[$key]]=true;
        foreach(['decision_evidence_refs','assessment_evidence_refs'] as $key)
            foreach($provenance[$key] as $ref) $set[$ref]=true;
        $refs=array_keys($set);sort($refs,SORT_STRING);return $refs;
    }

    private static function idempotency(array $decision,string $groupId,?string $projectId,string $workType): string
    {
        $parts=[
            $groupId,$projectId??'',(string)$decision['venture_id'],(string)$decision['product_id'],$workType,
            (string)$decision['initiative_id'],(string)$decision['hypothesis_ref'],(string)$decision['experiment_ref'],
            (string)$decision['assessment_ref'],(string)$decision['decision_ref'],
        ];
        return 'product-discovery:'.substr(hash('sha256',implode('|',$parts)),0,40);
    }

    private static function observedAt(mixed $window): string
    {
        if(!is_array($window)||array_is_list($window)||array_keys($window)!==['start_at','end_at']
            ||!is_int($window['start_at'])||!is_int($window['end_at'])
            ||$window['start_at']<0||$window['end_at']<=$window['start_at']||$window['end_at']>253402300799)
            throw new InvalidArgumentException('evaluation_window invalid.');
        return gmdate('Y-m-d\TH:i:s\Z',$window['end_at']);
    }

    private static function blocked(array $reasons,string $freshness,?array $provenance): array
    {
        $reasons=array_values(array_unique($reasons));sort($reasons,SORT_STRING);
        $seed=$provenance['decision_ref']??'unknown';
        $result=[
            'version'=>1,
            'status'=>'blocked',
            'reasons'=>$reasons,
            'freshness'=>$freshness,
            'work_item'=>null,
            'provenance'=>$provenance,
            'gate_ref'=>'product-discovery:gate/'.substr(hash('sha256',$seed.'|'.implode('|',$reasons)),0,40),
            'execution'=>false,
        ];
        self::safe($result);
        return $result;
    }

    private static function fields(mixed $row,array $expected): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException('ProductDiscoveryWorkOrigin invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException('ProductDiscoveryWorkOrigin fields invalid.');
    }

    private static function refs(mixed $values,string $label,bool $allowEmpty): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>50||(!$allowEmpty&&$values===[]))
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            $ref=self::ref($value,$label);
            if(isset($out[$ref])) throw new InvalidArgumentException($label.' duplicated.');
            $out[$ref]=true;
        }
        $refs=array_keys($out);sort($refs,SORT_STRING);return $refs;
    }

    private static function derivedRefs(mixed $values,string $label): array
    {
        if(!is_array($values)||!array_is_list($values)||$values===[]||count($values)>50)
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value) $out[self::ref($value,$label)]=true;
        $refs=array_keys($out);sort($refs,SORT_STRING);return $refs;
    }

    private static function slugs(mixed $values,string $label,bool $allowEmpty): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>50||(!$allowEmpty&&$values===[]))
            throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            $slug=self::slug($value,$label);
            if(isset($out[$slug])) throw new InvalidArgumentException($label.' duplicated.');
            $out[$slug]=true;
        }
        $slugs=array_keys($out);sort($slugs,SORT_STRING);return $slugs;
    }

    private static function repository(mixed $value): string
    {
        if(is_string($value)&&preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$value)===1) return $value;
        throw new InvalidArgumentException('repository_ref invalid.');
    }

    private static function ref(mixed $value,string $label): string
    {
        $value=is_string($value)?trim($value):'';
        if($value===''||strlen($value)>240||str_contains($value,'@')||strpbrk($value,"\n\r\0")!==false
            ||preg_match(self::SENSITIVE,$value)===1||preg_match(self::DIRECT_PII,$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(is_string($value)&&preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)===1) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(is_string($value)&&in_array($value,$allowed,true)) return $value;
        throw new InvalidArgumentException($label.' invalid.');
    }

    private static function safe(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item) self::safe($item);return;}
        if(is_string($value)
            &&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value)!==1
            &&(preg_match(self::SENSITIVE,$value)===1||preg_match(self::DIRECT_PII,$value)===1))
            throw new InvalidArgumentException('ProductDiscoveryWorkOrigin contains sensitive material.');
    }
}

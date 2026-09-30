<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductDiscoveryWorkOrigin
{
    private const WORK_TYPES='engineering|security|infrastructure|operations|data_analytics|product|content|marketing_growth|sales_support|finance_analysis|compliance_review|knowledge_documentation';
    private const PRIORITIES='critical|high|medium';
    private const FRESHNESS='fresh|stale|unknown';

    public static function materialize(array $raw,array $decisionRaw,array $assessmentRaw,array $experimentRaw,array $initiativeRaw,array $hypothesisRaw,array $outcomeRaw,array $baselineRaw,array $variantRaw,string $expectedVentureId,string $expectedProductId): array
    {
        try {
            self::exactObject($raw,['version','work_id','group_id','project_id','repository_ref','work_type','requested_capabilities','required_roles','authority_level','priority_class','policy_ref','depends_on','claims','execution']);
            if($raw['version']!==1||$raw['execution']!==false) throw new InvalidArgumentException('version/execution invalid.');

            $workId=self::reference($raw['work_id'],'work_id');
            $groupId=self::reference($raw['group_id'],'group_id');
            $projectId=$raw['project_id']===null?null:self::reference($raw['project_id'],'project_id');
            $repository=$raw['repository_ref']===null?null:self::repository($raw['repository_ref']);
            $workType=self::catalog($raw['work_type'],self::WORK_TYPES,'work_type');
            $capabilities=self::ordered($raw['requested_capabilities'],'requested_capabilities',false,true);
            $roles=self::ordered($raw['required_roles'],'required_roles',false,true);
            $authority=self::identifier($raw['authority_level'],'authority_level');
            $priority=self::catalog($raw['priority_class'],self::PRIORITIES,'priority_class');
            $policy=self::reference($raw['policy_ref'],'policy_ref');
            $dependsOn=self::ordered($raw['depends_on'],'depends_on',true,false);
            $claims=self::ordered($raw['claims'],'claims',true,false);

            $decision=ProductDiscoveryDecision::decide(
                $decisionRaw,$assessmentRaw,$experimentRaw,$initiativeRaw,$hypothesisRaw,
                $outcomeRaw,$baselineRaw,$variantRaw,$expectedVentureId,$expectedProductId
            );
            $fresh=self::catalog($decision['freshness']??null,self::FRESHNESS,'freshness');
            $observed=self::observedAt($decision['evaluation_window']??null);
            $provenance=self::provenance($decision,$observed);

            $reasons=[];
            if(($decision['decision']??null)!=='BUILD') $reasons[]='decision_not_build';
            if(($decision['classification']??null)!=='VALIDATED') $reasons[]='discovery_not_validated';
            if($fresh!=='fresh') $reasons[]='discovery_evidence_'.$fresh;
            if($reasons!==[]) return self::blocked($reasons,$fresh,$provenance);

            $item=[
                'work_id'=>$workId,'origin_mode'=>'automatic','origin_system'=>'controlbot',
                'group_id'=>$groupId,'venture_id'=>$decision['venture_id'],'work_type'=>$workType,
                'requested_capabilities'=>$capabilities,'required_roles'=>$roles,'authority_level'=>$authority,
                'producer_ref'=>'controlbot:product-discovery','priority_class'=>$priority,
                'depends_on'=>$dependsOn,'claims'=>$claims,'policy_ref'=>$policy,
                'evidence_refs'=>self::evidence($provenance),'observed_at'=>$observed,
                'idempotency_key'=>self::idempotency($decision,$groupId,$projectId,$workType),
            ];
            if($projectId!==null) $item['project_id']=$projectId;
            if($repository!==null) $item['repository_ref']=$repository;

            $result=[
                'version'=>1,'status'=>'materialized','reasons'=>['factory_work_item_materialized'],
                'freshness'=>$fresh,'work_item'=>$item,'provenance'=>$provenance,
                'gate_ref'=>null,'execution'=>false,
            ];
            self::assertSafe($result);
            return $result;
        } catch(InvalidArgumentException) {
            return self::blocked(['invalid_input'],'unknown',null);
        }
    }

    private static function provenance(array $decision,string $observed): array
    {
        $out=[];
        $single=['initiative_id','hypothesis_ref','experiment_ref','assessment_ref','decision_ref','decision_reason_ref','assessment_rule_ref','source_ref','evidence_ref'];
        foreach($single as $field) $out[$field]=self::reference($decision[$field]??null,$field);
        foreach(['decision_evidence_refs','assessment_evidence_refs'] as $field)
            $out[$field]=self::ordered($decision[$field]??null,$field,false,false,false);
        $out['freshness']=self::catalog($decision['freshness']??null,self::FRESHNESS,'freshness');
        $out['observed_at']=$observed;
        return $out;
    }

    private static function evidence(array $provenance): array
    {
        $refs=[];
        foreach($provenance as $field=>$value){
            if($field==='freshness'||$field==='observed_at') continue;
            foreach(is_array($value)?$value:[$value] as $ref) $refs[$ref]=true;
        }
        $result=array_keys($refs);
        sort($result,SORT_STRING);
        return $result;
    }

    private static function idempotency(array $decision,string $group,?string $project,string $type): string
    {
        $parts=[
            $group,$project??'',$decision['venture_id'],$decision['product_id'],$type,
            $decision['initiative_id'],$decision['hypothesis_ref'],$decision['experiment_ref'],
            $decision['assessment_ref'],$decision['decision_ref'],
        ];
        return 'product-discovery:'.substr(hash('sha256',implode('|',$parts)),0,40);
    }

    private static function observedAt(mixed $window): string
    {
        self::exactObject($window,['start_at','end_at']);
        $start=$window['start_at'];
        $end=$window['end_at'];
        if(!is_int($start)||!is_int($end)||$start<0||$end<=$start||$end>253402300799)
            throw new InvalidArgumentException('evaluation_window invalid.');
        return gmdate('Y-m-d\TH:i:s\Z',$end);
    }

    private static function blocked(array $reasons,string $freshness,?array $provenance): array
    {
        $reasons=array_values(array_unique($reasons));
        sort($reasons,SORT_STRING);
        $seed=$provenance['decision_ref']??'unknown';
        $result=[
            'version'=>1,'status'=>'blocked','reasons'=>$reasons,'freshness'=>$freshness,
            'work_item'=>null,'provenance'=>$provenance,
            'gate_ref'=>'product-discovery:gate/'.substr(hash('sha256',$seed.'|'.implode('|',$reasons)),0,40),
            'execution'=>false,
        ];
        self::assertSafe($result);
        return $result;
    }

    private static function exactObject(mixed $value,array $fields): array
    {
        if(!is_array($value)||array_is_list($value)) throw new InvalidArgumentException('object invalid.');
        $actual=array_keys($value);
        sort($actual,SORT_STRING);
        sort($fields,SORT_STRING);
        if($actual!==$fields) throw new InvalidArgumentException('object fields invalid.');
        return $value;
    }

    private static function ordered(mixed $values,string $field,bool $allowEmpty,bool $identifiers,bool $rejectDuplicates=true): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>50||(!$allowEmpty&&$values===[]))
            throw new InvalidArgumentException($field.' invalid.');
        $seen=[];
        foreach($values as $value){
            $normalized=$identifiers?self::identifier($value,$field):self::reference($value,$field);
            if($rejectDuplicates&&isset($seen[$normalized])) throw new InvalidArgumentException($field.' duplicated.');
            $seen[$normalized]=true;
        }
        $result=array_keys($seen);
        sort($result,SORT_STRING);
        return $result;
    }

    private static function catalog(mixed $value,string $catalog,string $field): string
    {
        if(!is_string($value)||!in_array($value,explode('|',$catalog),true))
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function identifier(mixed $value,string $field): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)!==1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function repository(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$value)!==1)
            throw new InvalidArgumentException('repository_ref invalid.');
        return $value;
    }

    private static function reference(mixed $value,string $field): string
    {
        if(!is_string($value)) throw new InvalidArgumentException($field.' invalid.');
        $value=trim($value);
        $lower=strtolower($value);
        $keywords=['bearer ','password','passwd','secret','token','api_key','api-key','private_key','private-key','cookie','authorization','dsn','github_pat_','ghp_','gho_','ghu_','ghs_','ghr_','sk-'];
        $sensitive=false;
        foreach($keywords as $keyword) if(str_contains($lower,$keyword)){ $sensitive=true; break; }
        $email=preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',$value)===1;
        $phone=preg_match('/(?<![A-Za-z0-9])\+?[0-9][0-9(). -]{7,}[0-9](?![A-Za-z0-9])/',$value)===1;
        if($value===''||strlen($value)>240||strpbrk($value,"\n\r\0")!==false||$sensitive||$email||$phone)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function assertSafe(mixed $value): void
    {
        if(is_array($value)){
            foreach($value as $item) self::assertSafe($item);
            return;
        }
        if(!is_string($value)) return;
        if(preg_match('/^(?:\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z|product-discovery:(?:gate\/)?[a-f0-9]{40})$/D',$value)===1)
            return;
        self::reference($value,'output');
    }
}

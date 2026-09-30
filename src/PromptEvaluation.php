<?php
declare(strict_types=1);

namespace ControlBot\Prompt;

use InvalidArgumentException;

final class PromptEvaluation
{
    private const METRICS=['acceptance_rate','rework_rate','review_findings_rate','handoff_rate','latency_ms','cost_units'];
    private const DIRECTIONS=[
        'acceptance_rate'=>'higher',
        'rework_rate'=>'lower',
        'review_findings_rate'=>'lower',
        'handoff_rate'=>'lower',
        'latency_ms'=>'lower',
        'cost_units'=>'lower',
    ];
    private const OUTCOMES=['pass','fail'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code)/i';

    public static function evaluationSet(array $raw): array
    {
        self::fields($raw,['evaluation_set_id','version','task_class','sample_size','metric_names'],'EvaluationSet');
        $metrics=self::metricNames($raw['metric_names']);
        $set=[
            'evaluation_set_id'=>self::slug($raw['evaluation_set_id'],'evaluation_set_id'),
            'version'=>self::positiveInt($raw['version'],'version'),
            'task_class'=>self::slug($raw['task_class'],'task_class'),
            'sample_size'=>self::positiveInt($raw['sample_size'],'sample_size'),
            'metric_names'=>$metrics,
        ];
        $set['fingerprint']=self::fingerprint($set);
        return $set;
    }

    public static function result(array $raw): array
    {
        self::fields($raw,[
            'template_id','prompt_version','task_class','evaluation_set_id','evaluation_set_version',
            'sample_size','metrics','safety_result','policy_result'
        ],'EvaluationResult');
        $metrics=self::metrics($raw['metrics']);
        return [
            'template_id'=>self::slug($raw['template_id'],'template_id'),
            'prompt_version'=>self::positiveInt($raw['prompt_version'],'prompt_version'),
            'task_class'=>self::slug($raw['task_class'],'task_class'),
            'evaluation_set_id'=>self::slug($raw['evaluation_set_id'],'evaluation_set_id'),
            'evaluation_set_version'=>self::positiveInt($raw['evaluation_set_version'],'evaluation_set_version'),
            'sample_size'=>self::positiveInt($raw['sample_size'],'sample_size'),
            'metrics'=>$metrics,
            'safety_result'=>self::enum($raw['safety_result'],self::OUTCOMES,'safety_result'),
            'policy_result'=>self::enum($raw['policy_result'],self::OUTCOMES,'policy_result'),
        ];
    }

    public static function resultFingerprint(array $raw): string
    {
        return self::fingerprint(self::result($raw));
    }

    public static function compare(array $setRaw,array $currentRaw,array $candidateRaw,int $minimumSample=20): array
    {
        if($minimumSample<1) throw new InvalidArgumentException('minimumSample invalid.');
        $set=self::evaluationSet($setRaw);
        $current=self::result($currentRaw);
        $candidate=self::result($candidateRaw);
        self::compatible($set,$current,$candidate);

        $reasons=[];
        $decision='keep_current';

        if($candidate['safety_result']!=='pass'||$candidate['policy_result']!=='pass'){
            $reasons[]='candidate_safety_or_policy_failed';
        }elseif($current['sample_size']<$minimumSample||$candidate['sample_size']<$minimumSample){
            $reasons[]='insufficient_evidence';
        }else{
            $better=0; $worse=0;
            foreach($set['metric_names'] as $metric){
                $a=$current['metrics'][$metric]; $b=$candidate['metrics'][$metric];
                if($a===$b) continue;
                $direction=self::DIRECTIONS[$metric];
                $candidateBetter=$direction==='higher' ? $b>$a : $b<$a;
                $candidateBetter ? $better++ : $worse++;
            }
            if($better>0&&$worse===0){
                $decision='candidate_better';
                $reasons[]='candidate_dominates_current';
            }elseif($better===0&&$worse===0){
                $reasons[]='tie_keep_current';
            }else{
                $reasons[]='candidate_not_strictly_better';
            }
        }

        sort($reasons,SORT_STRING);
        $out=[
            'version'=>1,
            'decision'=>$decision,
            'template_id'=>$current['template_id'],
            'current_version'=>$current['prompt_version'],
            'candidate_version'=>$candidate['prompt_version'],
            'evaluation_set_fingerprint'=>$set['fingerprint'],
            'current_result_fingerprint'=>self::fingerprint($current),
            'candidate_result_fingerprint'=>self::fingerprint($candidate),
            'reasons'=>$reasons,
            'authority'=>'advisory_only',
        ];
        $out['fingerprint']=self::fingerprint($out);
        return $out;
    }

    private static function compatible(array $set,array $current,array $candidate): void
    {
        foreach([$current,$candidate] as $result){
            if($result['task_class']!==$set['task_class']
                ||$result['evaluation_set_id']!==$set['evaluation_set_id']
                ||$result['evaluation_set_version']!==$set['version']
                ||$result['sample_size']!==$set['sample_size']) {
                throw new InvalidArgumentException('Evaluation result incompatible with evaluation set.');
            }
            if(array_keys($result['metrics'])!==$set['metric_names'])
                throw new InvalidArgumentException('Evaluation metrics incompatible.');
        }
        if($current['template_id']!==$candidate['template_id']
            ||$candidate['prompt_version']<=$current['prompt_version']) {
            throw new InvalidArgumentException('Prompt versions incompatible.');
        }
    }

    private static function metrics(mixed $raw): array
    {
        if(!is_array($raw)||array_is_list($raw)||$raw===[]) throw new InvalidArgumentException('metrics invalid.');
        $out=[];
        foreach($raw as $name=>$value){
            if(!is_string($name)||!in_array($name,self::METRICS,true)) throw new InvalidArgumentException('metric unknown.');
            if(!is_int($value)&&!is_float($value)) throw new InvalidArgumentException('metric value invalid.');
            $float=(float)$value;
            if(!is_finite($float)) throw new InvalidArgumentException('metric value non-finite.');
            $out[$name]=$float;
        }
        ksort($out,SORT_STRING);
        return $out;
    }

    private static function metricNames(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||$raw===[]) throw new InvalidArgumentException('metric_names invalid.');
        $out=[];
        foreach($raw as $name){
            if(!is_string($name)||!in_array($name,self::METRICS,true)||in_array($name,$out,true))
                throw new InvalidArgumentException('metric_names invalid.');
            self::safe($name); $out[]=$name;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' fields invalid.');
        $actual=array_keys($raw); sort($actual,SORT_STRING); sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        self::safe($value); return $value;
    }

    private static function positiveInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function safe(string $value): void
    {
        if(preg_match(self::SENSITIVE,$value)===1) throw new InvalidArgumentException('Sensitive evaluation metadata invalid.');
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }
}

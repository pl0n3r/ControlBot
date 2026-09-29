<?php
declare(strict_types=1);

namespace ControlBot\Prompt;

use InvalidArgumentException;

final class PromptPromotionDecision
{
    private const EVALUATION_DECISIONS=['keep_current','candidate_better'];
    private const EVALUATION_REASONS=[
        'candidate_safety_or_policy_failed',
        'insufficient_evidence',
        'candidate_dominates_current',
        'tie_keep_current',
        'candidate_not_strictly_better',
    ];

    public static function decide(
        array $history,
        string $templateId,
        int $candidateVersion,
        array $evaluation
    ): array {
        if($candidateVersion<1) throw new InvalidArgumentException('candidateVersion invalid.');

        $current=PromptRegistry::active($history,$templateId);
        $candidate=self::candidateFromValidatedHistory($history,$templateId,$candidateVersion);

        if($current['status']!=='approved') throw new InvalidArgumentException('Current prompt must be approved.');
        if($candidate['status']!=='candidate') throw new InvalidArgumentException('Candidate prompt must be candidate.');
        if($candidate['version']<=$current['version']||$candidate['supersedes']!==$current['version'])
            throw new InvalidArgumentException('Candidate is not the direct successor of current.');
        if(
            $candidate['template_id']!==$current['template_id']
            ||$candidate['task_class']!==$current['task_class']
            ||$candidate['provider_scope']!==$current['provider_scope']
            ||$candidate['variables_schema']!==$current['variables_schema']
        ) throw new InvalidArgumentException('Prompt contract mismatch.');

        $evaluation=self::evaluation($evaluation);
        if(
            $evaluation['current_version']!==$current['version']
            ||$evaluation['candidate_version']!==$candidate['version']
        ) throw new InvalidArgumentException('Evaluation prompt versions mismatch.');

        $decision='hold';
        $reasons=$evaluation['reasons'];
        if($evaluation['decision']==='candidate_better'){
            if(!in_array('candidate_dominates_current',$reasons,true))
                throw new InvalidArgumentException('Candidate-better evidence incomplete.');
            $decision='eligible_for_human_approval';
            $reasons=['evaluation_supports_candidate'];
        }

        sort($reasons,SORT_STRING);
        $out=[
            'version'=>1,
            'decision'=>$decision,
            'template_id'=>$current['template_id'],
            'current_version'=>$current['version'],
            'candidate_version'=>$candidate['version'],
            'evaluation_fingerprint'=>$evaluation['fingerprint'],
            'evaluation_set_fingerprint'=>$evaluation['evaluation_set_fingerprint'],
            'reasons'=>$reasons,
            'human_gate_required'=>true,
            'authority'=>'human_approval_required',
        ];
        $out['fingerprint']=self::fingerprint($out);
        return $out;
    }

    private static function candidateFromValidatedHistory(array $history,string $templateId,int $candidateVersion): array
    {
        // active() validates the complete PromptRegistry history before returning.
        PromptRegistry::active($history,$templateId);
        foreach($history as $row){
            if(
                is_array($row)
                &&($row['template_id']??null)===$templateId
                &&($row['version']??null)===$candidateVersion
            ) return $row;
        }
        throw new InvalidArgumentException('Candidate prompt version unavailable.');
    }

    private static function evaluation(array $raw): array
    {
        $expected=[
            'version','decision','current_version','candidate_version','evaluation_set_fingerprint',
            'reasons','authority','fingerprint'
        ];
        $actual=array_keys($raw); sort($actual,SORT_STRING); sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException('Evaluation decision fields invalid.');
        if($raw['version']!==1) throw new InvalidArgumentException('Evaluation decision version invalid.');
        if(!is_string($raw['decision'])||!in_array($raw['decision'],self::EVALUATION_DECISIONS,true))
            throw new InvalidArgumentException('Evaluation decision invalid.');
        if(!is_int($raw['current_version'])||$raw['current_version']<1
            ||!is_int($raw['candidate_version'])||$raw['candidate_version']<1)
            throw new InvalidArgumentException('Evaluation versions invalid.');
        if(!is_string($raw['evaluation_set_fingerprint'])
            ||preg_match('/^[a-f0-9]{64}$/D',$raw['evaluation_set_fingerprint'])!==1)
            throw new InvalidArgumentException('Evaluation set fingerprint invalid.');
        if($raw['authority']!=='advisory_only') throw new InvalidArgumentException('Evaluation authority invalid.');
        if(!is_array($raw['reasons'])||!array_is_list($raw['reasons'])||$raw['reasons']===[])
            throw new InvalidArgumentException('Evaluation reasons invalid.');
        $reasons=[];
        foreach($raw['reasons'] as $reason){
            if(!is_string($reason)||!in_array($reason,self::EVALUATION_REASONS,true)||in_array($reason,$reasons,true))
                throw new InvalidArgumentException('Evaluation reason invalid.');
            $reasons[]=$reason;
        }
        sort($reasons,SORT_STRING);
        $canonical=[
            'version'=>1,
            'decision'=>$raw['decision'],
            'current_version'=>$raw['current_version'],
            'candidate_version'=>$raw['candidate_version'],
            'evaluation_set_fingerprint'=>$raw['evaluation_set_fingerprint'],
            'reasons'=>$reasons,
            'authority'=>'advisory_only',
        ];
        $fingerprint=self::fingerprint($canonical);
        if(!is_string($raw['fingerprint'])||!hash_equals($fingerprint,$raw['fingerprint']))
            throw new InvalidArgumentException('Evaluation fingerprint invalid.');
        return $canonical+['fingerprint'=>$fingerprint];
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }
}

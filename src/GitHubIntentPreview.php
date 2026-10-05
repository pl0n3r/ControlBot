<?php
declare(strict_types=1);
namespace ControlBot\GitHub;

use InvalidArgumentException;

final class GitHubIntentPreview
{
    private const DECISIONS=['allow','owner_decision_required','deny','unknown'];
    private const SENSITIVE='/(?:\b(?:password|passwd|secret|token|cookie|authorization|bearer|credential|otp|dsn)\b|private[_ -]?key|public[_ -]?key|api[_ -]?key|user[_ -]?id|customer[_ -]?id)/i';
    private const DIRECT_PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+\d{1,3}(?:[ .()\-]?\d){7,14}|\b\d{3}[ .\-]\d{3}[ .\-]\d{4}\b)/i';

    public static function preview(array $envelope,array $policy): array
    {
        try{
            $intent=self::intent($envelope);
            $policy=self::policy($policy);
            self::safe($intent);
        }catch(\Throwable){
            return self::minimal();
        }

        $decision=$policy['decision'];
        return [
            'version'=>1,
            'intent'=>[
                'intent_id'=>$intent['intent_id'],
                'project_ref'=>$intent['project_ref'],
                'repository_ref'=>$intent['repository_ref'],
                'type'=>$intent['type'],
                'params'=>$intent['params'],
            ],
            'policy'=>[
                'decision'=>$decision,
                'reasons'=>$policy['reasons'],
            ],
            'approval'=>[
                'required'=>$decision==='owner_decision_required',
                'state'=>match($decision){
                    'owner_decision_required'=>'required',
                    'allow'=>'not_required',
                    default=>'unavailable',
                },
            ],
            'evidence_refs'=>$intent['evidence_refs'],
            'idempotency_key'=>$intent['idempotency_key'],
            'mutation_controls'=>[],
            'execution'=>false,
        ];
    }

    private static function intent(array $envelope): array
    {
        if(($envelope['execution']??null)!==false)
            throw new InvalidArgumentException('execution invalid.');
        unset($envelope['execution']);
        return GitHubIntentEnvelope::envelope($envelope);
    }

    private static function policy(array $policy): array
    {
        self::fields($policy,['decision','reasons','execution']);
        if(($policy['execution']??null)!==false
            ||!is_string($policy['decision'])
            ||!in_array($policy['decision'],self::DECISIONS,true)
            ||!is_array($policy['reasons'])
            ||!array_is_list($policy['reasons'])
            ||$policy['reasons']===[]||count($policy['reasons'])>8)
            throw new InvalidArgumentException('policy invalid.');

        foreach($policy['reasons'] as $reason){
            if(!is_string($reason)
                ||preg_match('/^[a-z][a-z0-9._:-]{1,79}$/D',$reason)!==1
                ||preg_match(self::SENSITIVE,$reason)===1)
                throw new InvalidArgumentException('policy reason invalid.');
        }
        return $policy;
    }

    private static function safe(array $intent): void
    {
        $encoded=json_encode($intent,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(preg_match(self::SENSITIVE,$encoded)===1||preg_match(self::DIRECT_PII,$encoded)===1)
            throw new InvalidArgumentException('sensitive preview context.');
    }

    private static function fields(array $row,array $expected): void
    {
        if(array_is_list($row)) throw new InvalidArgumentException('fields invalid.');
        $actual=array_keys($row);sort($actual);sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException('fields invalid.');
    }

    private static function minimal(): array
    {
        return [
            'version'=>1,
            'intent'=>null,
            'policy'=>['decision'=>'unknown','reasons'=>['invalid_or_sensitive_context']],
            'approval'=>['required'=>false,'state'=>'unavailable'],
            'evidence_refs'=>[],
            'idempotency_key'=>null,
            'mutation_controls'=>[],
            'execution'=>false,
        ];
    }
}

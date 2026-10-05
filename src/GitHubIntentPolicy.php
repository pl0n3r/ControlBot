<?php
declare(strict_types=1);
namespace ControlBot\GitHub;

use ControlBot\Production\CapabilityGrant;
use InvalidArgumentException;

final class GitHubIntentPolicy
{
    private const CONTEXT_FIELDS=[
        'restrictions','control_issue','run_id','subject','evidence_ref',
        'evidence_observed_at','evidence_max_age_seconds',
    ];
    private const CAPABILITIES=[
        'issue.create'=>'github.issue.write',
        'issue.update'=>'github.issue.write',
        'issue.close'=>'github.issue.write',
        'issue.reserve'=>'github.issue.write',
        'issue.release'=>'github.issue.write',
        'pr.review'=>'github.pr.review',
        'pr.merge'=>'github.pr.merge',
        'workflow.dispatch'=>'github.workflow.dispatch',
        'release.approve'=>'github.release.approve',
        'project.freeze'=>'github.project.write',
        'project.unfreeze'=>'github.project.write',
    ];

    public static function evaluate(array $envelope, ?CapabilityGrant $grant, array $context, int $now): array
    {
        try { $intent=self::intent($envelope); }
        catch (\Throwable) { return self::result('unknown','invalid_intent'); }

        try { self::context($context,$now); }
        catch (\Throwable) { return self::result('deny','invalid_authority'); }

        if(!in_array($context['evidence_ref'],$intent['evidence_refs'],true))
            return self::result('deny','evidence_mismatch');
        if($now-$context['evidence_observed_at']>$context['evidence_max_age_seconds'])
            return self::result('deny','stale_evidence');

        if(!isset(self::CAPABILITIES[$intent['type']]))
            return self::result('unknown','unknown_intent_capability');

        // V1 has no trusted freshness projection. A raw ref + timestamp/TTL is
        // caller-controlled, so it cannot authorize or escalate any GitHub intent.
        // The grant stays in the signature for compatibility but is intentionally
        // not consumed until a separate trusted-evidence contract exists.
        return self::result('deny','untrusted_evidence_freshness');
    }

    private static function intent(array $envelope): array
    {
        if(!array_key_exists('execution',$envelope)||$envelope['execution']!==false)
            throw new InvalidArgumentException('execution invalid.');
        unset($envelope['execution']);
        return GitHubIntentEnvelope::envelope($envelope);
    }

    private static function context(array $context,int $now): void
    {
        self::fields($context,self::CONTEXT_FIELDS);
        if(
            $now<1
            || !is_int($context['evidence_observed_at'])
            || !is_int($context['evidence_max_age_seconds'])
            || $context['evidence_observed_at']<1
            || $context['evidence_observed_at']>$now
            || $context['evidence_max_age_seconds']<1
            || $context['evidence_max_age_seconds']>900
        ) throw new InvalidArgumentException('evidence time invalid.');
        if(
            !is_array($context['restrictions'])
            || !is_string($context['control_issue'])
            || !is_string($context['run_id'])
            || !is_string($context['subject'])
            || !is_string($context['evidence_ref'])
        ) throw new InvalidArgumentException('authority invalid.');
    }

    private static function fields(array $row,array $expected): void
    {
        if(array_is_list($row)) throw new InvalidArgumentException('context invalid.');
        $actual=array_keys($row); sort($actual); sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException('context fields invalid.');
    }

    private static function result(string $decision,string $reason): array
    { return ['decision'=>$decision,'reasons'=>[$reason],'execution'=>false]; }
}

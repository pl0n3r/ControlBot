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


    /**
     * Offline V2. The snapshot, serverContext and clock MUST be supplied by
     * trusted application code, not by a request or intention payload.
     * Freshness describes an observation; it does not confer permission.
     */
    public static function evaluateTrusted(
        array $envelope,
        ?CapabilityGrant $grant,
        array $serverContext,
        array $canonicalSnapshot,
        int $trustedNow
    ): array {
        try { $intent = self::intent($envelope); }
        catch (\Throwable) { return self::result('unknown', 'invalid_intent'); }

        try {
            self::fields($serverContext, ['project_ref', 'repository_ref']);
            if (
                $trustedNow < 1
                || !is_string($serverContext['project_ref'])
                || !is_string($serverContext['repository_ref'])
            ) throw new InvalidArgumentException('server context invalid.');

            // describe() consumes a raw eight-field Envelope, not the
            // normalized Envelope containing its derived execution flag.
            $raw = $intent;
            unset($raw['execution']);
            $req = GitHubIntentAuthorityRequirements::describe(
                $raw, $serverContext['project_ref'], $serverContext['repository_ref']
            );
            $expectedScope = in_array($intent['type'], ['project.freeze', 'project.unfreeze'], true)
                ? 'project' : 'repository';
            $expectedRef = $expectedScope === 'project'
                ? $serverContext['project_ref'] : $serverContext['repository_ref'];
            if (
                ($req['state'] ?? null) !== 'requirements'
                || ($req['type'] ?? null) !== $intent['type']
                || ($req['capability'] ?? null) !== (self::CAPABILITIES[$intent['type']] ?? null)
                || ($req['scope'] ?? null) !== $expectedScope
                || ($req['scope_ref'] ?? null) !== $expectedRef
                || ($req['scope_parameters'] ?? null) !== [$expectedScope . '_ref' => $expectedRef]
                || ($req['approval_class'] ?? null) !== 'owner_required'
            ) return self::result('deny', 'requirements_invalid');

            foreach (['authorized', 'grant_issued', 'approval_issued', 'approval_verified', 'execution'] as $flag) {
                if (!array_key_exists($flag, $req) || $req[$flag] !== false)
                    return self::result('deny', 'requirements_invalid');
            }
            // No caller-controlled evidence_ref, timestamp or TTL is used.
            // Envelope refs use github:/controlbot:, whereas the trusted
            // observation has an HTTPS commit URL. They are NOT interchangeable.
            $trusted = GitHubIntentTrustedEvidence::derive(
                $canonicalSnapshot, $serverContext['repository_ref'], $trustedNow
            );
            $expectedSource = 'https://github.com/' . $serverContext['repository_ref'];
            if (
                ($trusted['state'] ?? null) !== 'current'
                || ($trusted['freshness'] ?? null) !== 'current'
                || ($trusted['repository_ref'] ?? null) !== $serverContext['repository_ref']
                || ($trusted['source_ref'] ?? null) !== $expectedSource
                || !is_string($trusted['evidence_ref'] ?? null)
                || !preg_match(
                    '#^' . preg_quote($expectedSource, '#') . '/commit/[a-f0-9]{40}$#D',
                    $trusted['evidence_ref']
                )
                || ($trusted['execution'] ?? null) !== false
            ) return self::result('deny', 'untrusted_or_stale_snapshot');

            // All GitHub capabilities remain forbidden in the current catalog.
            // A CapabilityGrant from another domain or an approval_ref string
            // must not become authority. No grant inspection, issue or adapter.
            $classification = \ControlBot\Production\CapabilityPolicy::classify($req['capability']);
            if (
                ($classification['known'] ?? null) !== false
                || ($classification['decision'] ?? null) !== 'forbidden'
                || ($classification['auto_grant_allowed'] ?? null) !== false
            ) return self::result('deny', 'authority_catalog_changed');
            return self::result('deny', 'grant_required');
        } catch (\Throwable) {
            return self::result('deny', 'requirements_invalid');
        }
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

<?php
declare(strict_types=1);
namespace ControlBot\Guardrail;

use ControlBot\Scheduler\SchedulerCore;
use InvalidArgumentException;

final class GuardrailInfrastructureClassification
{
    private const FRESH=['fresh','stale','unknown'];
    private const EXT=['blocked','healthy','unknown'];
    private const KINDS=['capacity','quota','billing','entitlement'];
    private const OUTCOME=['success','failure','unknown'];

    public static function project(
        array $agentAnalysis,
        array $runs,
        ?array $external,
        ?string $activeExternalFingerprint = null
    ): array {
        $agent=self::agent($agentAnalysis);
        $runs=self::runs($runs);
        $external=self::external($external);
        $active=self::nullableSha($activeExternalFingerprint, 'active_external_fingerprint');

        $pre=array_values(array_filter($runs, fn($r)=>$r['startup_failure']&&$r['steps']===null));
        $private=array_values(array_filter($pre, fn($r)=>$r['visibility']==='private'));
        $publicHealthy=array_values(array_filter(
            $runs, fn($r)=>$r['visibility']==='public'&&!$r['startup_failure']
                &&$r['runner_id']>0&&$r['steps']!==null&&$r['steps']!==[]
                &&$r['outcome']==='success'&&$r['freshness']==='fresh'
        ));
        $correlated=self::correlatedPrivateFailure($private);
        $latestRunObservedAt=max(array_column($runs, 'observed_at'));
        $externalClearsAgent=$external===null
            ||($external['freshness']==='fresh'&&$external['state']==='healthy'
                &&$external['observed_at']>=$latestRunObservedAt);

        $classification='unknown';
        $externalFp=null;
        if ($correlated!==null
            &&$publicHealthy!==[]
            &&$external!==null
            &&$external['freshness']==='fresh'
            &&$external['state']==='blocked'
            &&$external['dependency_ref']===$correlated['dependency_ref']
            &&$external['observed_at']>=$correlated['observed_at']
            &&hash_equals($external['fingerprint'], $correlated['fingerprint'])
        ) {
            $classification='blocked_by_infrastructure';
            $externalFp=$external['fingerprint'];
        } elseif ($pre===[]&&$externalClearsAgent&&$agent['health']==='stuck') {
            $classification='agent_stuck';
        }

        $retry=null;
        $handoff=null;
        if ($classification==='blocked_by_infrastructure') {
            $retry=[
                'action'=>'suppress_retry','fingerprint'=>$externalFp,
                'dependency_ref'=>$correlated['dependency_ref'],
                'deduplicated'=>$active!==null&&hash_equals($active, $externalFp),
            ];
            $evidence=$correlated['evidence_refs'];
            $evidence[]=$external['evidence_ref'];
            $evidence=array_values(array_unique($evidence));
            sort($evidence, SORT_STRING);
            $handoff=[
                'status'=>'waiting_dependency','source_ref'=>$correlated['source_ref'],
                'cause'=>'blocked_by_infrastructure','dependency_ref'=>$correlated['dependency_ref'],
                'fingerprint'=>$externalFp,'evidence_refs'=>$evidence,
                'next_action'=>'wait for fresh infrastructure recovery then run one canary',
            ];
        }

        $canary=null;
        if ($active!==null
            &&$correlated!==null
            &&$external!==null
            &&$external['freshness']==='fresh'
            &&$external['state']==='healthy'
            &&$external['dependency_ref']===$correlated['dependency_ref']
            &&$external['observed_at']>$correlated['observed_at']
            &&hash_equals($active, $correlated['fingerprint'])
            &&!hash_equals($active, $external['fingerprint'])
        ) {
            $source=$correlated['source_ref'];
            $projectId=self::projectIdFromSourceRef($source);
            $canary=SchedulerCore::workItem([
                'version'=>1,'work_item_id'=>'infra-canary-'.substr($active, 0, 20),
                'project_id'=>$projectId,'source_ref'=>$source,'type'=>'verification',
                'priority'=>'critical','state'=>'queued','dependency_ids'=>[],
                'required_capabilities'=>['ci'],'generation'=>1,'attempt'=>1,
                'reservation_id'=>null,'assigned_session_id'=>null,
            ]);
        }

        $result=[
            'version'=>1,'classification'=>$classification,
            'agent_fingerprint'=>$agent['alert_fingerprint'],
            'external_fingerprint'=>$externalFp,
            'retry_intent'=>$retry,'handoff'=>$handoff,'canary_work_item'=>$canary,
            'queue_release_allowed'=>false,
        ];
        return $result+[
            'fingerprint'=>hash(
                'sha256',
                json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
            ),
        ];
    }

    private static function correlatedPrivateFailure(array $runs): ?array
    {
        if (count($runs)<2) {
            return null;
        }
        $first=$runs[0];
        $evidence=[];
        $repositories=[];
        $sources=[];
        $observedAt=0;
        foreach ($runs as $run) {
            if ($run['freshness']!=='fresh'||$run['runner_id']!==0||$run['outcome']!=='failure'
                ||!hash_equals($first['failure_fingerprint'], $run['failure_fingerprint'])
                ||$first['dependency_ref']!==$run['dependency_ref']
            ) {
                return null;
            }
            $evidence[]=$run['evidence_ref'];
            $repositories[]=strstr($run['source_ref'], '#', true);
            $sources[]=$run['source_ref'];
            $observedAt=max($observedAt, $run['observed_at']);
        }
        if (count(array_unique($repositories))<2) {
            return null;
        }
        $evidence=array_values(array_unique($evidence));
        sort($evidence, SORT_STRING);
        $sources=array_values(array_unique($sources));
        sort($sources, SORT_STRING);
        return [
            'fingerprint'=>$first['failure_fingerprint'],
            'dependency_ref'=>$first['dependency_ref'],'observed_at'=>$observedAt,
            'source_ref'=>$sources[0],'evidence_refs'=>$evidence,
        ];
    }

    private static function agent(array $raw): array
    {
        if (!in_array($raw['health']??null, ['healthy','degraded','stuck'], true)
            ||!is_bool($raw['pause_required']??null)
            ||!is_bool($raw['escalation_required']??null)
        ) {
            throw new InvalidArgumentException('agent analysis invalid.');
        }
        self::sha($raw['alert_fingerprint']??null, 'agent alert_fingerprint');
        return $raw;
    }

    private static function runs(mixed $rows): array
    {
        if (!is_array($rows)||!array_is_list($rows)||$rows===[]||count($rows)>32) {
            throw new InvalidArgumentException('run evidence invalid.');
        }
        $out=[];
        foreach ($rows as $r) {
            self::fields(
                $r,
                [
                    'source_ref','visibility','startup_failure','runner_id','steps','outcome',
                    'failure_fingerprint','dependency_ref','evidence_ref','observed_at','freshness',
                ],
                'RunEvidence'
            );
            if (!in_array($r['visibility'], ['private','public'], true)||!is_bool($r['startup_failure'])
                ||!is_int($r['runner_id'])||$r['runner_id']<0
                ||($r['steps']!==null&&(!is_array($r['steps'])||!array_is_list($r['steps'])))
                ||!in_array($r['outcome'], self::OUTCOME, true)
                ||!is_int($r['observed_at'])||$r['observed_at']<1
                ||!in_array($r['freshness'], self::FRESH, true)
            ) {
                throw new InvalidArgumentException('run evidence invalid.');
            }
            $out[]=[
                'source_ref'=>self::workRef($r['source_ref']),
                'visibility'=>$r['visibility'],
                'startup_failure'=>$r['startup_failure'],
                'runner_id'=>$r['runner_id'],
                'steps'=>$r['steps'],
                'outcome'=>$r['outcome'],
                'failure_fingerprint'=>self::sha($r['failure_fingerprint'], 'failure_fingerprint'),
                'dependency_ref'=>self::ref($r['dependency_ref'], 'dependency_ref'),
                'evidence_ref'=>self::ref($r['evidence_ref'], 'evidence_ref'),
                'observed_at'=>$r['observed_at'],'freshness'=>$r['freshness'],
            ];
        }
        return $out;
    }

    private static function external(?array $r): ?array
    {
        if ($r===null) {
            return null;
        }
        self::fields(
            $r,
            ['state','kind','dependency_ref','fingerprint','evidence_ref','observed_at','freshness'],
            'ExternalSignal'
        );
        if (!in_array($r['state'], self::EXT, true)||!in_array($r['kind'], self::KINDS, true)
            ||!is_int($r['observed_at'])||$r['observed_at']<1
            ||!in_array($r['freshness'], self::FRESH, true)
        ) {
            throw new InvalidArgumentException('external signal invalid.');
        }
        return $r+[
            'dependency_ref'=>self::ref($r['dependency_ref'], 'dependency_ref'),
            'fingerprint'=>self::sha($r['fingerprint'], 'external fingerprint'),
            'evidence_ref'=>self::ref($r['evidence_ref'], 'external evidence_ref'),
        ];
    }

    private static function projectIdFromSourceRef(string $sourceRef): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]+\\/([A-Za-z0-9_.-]+)#[1-9][0-9]*$/D', $sourceRef, $match)!==1) {
            throw new InvalidArgumentException('source_ref invalid.');
        }
        $projectId=strtolower($match[1]);
        if (preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $projectId)!==1) {
            throw new InvalidArgumentException('source_ref project_id invalid.');
        }
        return $projectId;
    }

    private static function nullableSha(mixed $v, string $l): ?string
    {
        return $v===null?null:self::sha($v, $l);
    }

    private static function sha(mixed $v, string $l): string
    {
        if (!is_string($v)||preg_match('/^[0-9a-f]{64}$/D', $v)!==1) {
            throw new InvalidArgumentException($l.' invalid.');
        }
        return $v;
    }

    private static function workRef(mixed $v): string
    {
        if (!is_string($v)||preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+#[1-9][0-9]*$/D', $v)!==1) {
            throw new InvalidArgumentException('source_ref invalid.');
        }
        return $v;
    }

    private static function ref(mixed $v, string $l): string
    {
        if (!is_string($v)
            ||preg_match('/^controlbot:[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,179}$/D', $v)!==1
            ||preg_match('/(?:token|secret|password|cookie|authorization|dsn)/i', $v)===1
        ) {
            throw new InvalidArgumentException($l.' invalid.');
        }
        return $v;
    }

    private static function fields(mixed $r, array $e, string $l): void
    {
        if (!is_array($r)||array_is_list($r)) {
            throw new InvalidArgumentException($l.' invalid.');
        }
        $a=array_keys($r);
        sort($a);
        sort($e);
        if ($a!==$e) {
            throw new InvalidArgumentException($l.' fields invalid.');
        }
    }
}

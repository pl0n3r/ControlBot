<?php
declare(strict_types=1);
namespace ControlBot\Guardrail;

use ControlBot\Runtime\AgentRuntime;
use ControlBot\Runtime\PauseControl;
use ControlBot\Scheduler\SchedulerCore;
use ControlBot\Scheduler\SchedulerRequeuePlan;
use InvalidArgumentException;

final class GuardrailIntegration
{
    private const ACTIVE=['assigned','running','review'];
    private const SESSION_ACTIVE=['assigned','working','waiting_tool','waiting_human','reviewing','blocked'];
    private const ASSIGNMENT_ACTIVE=['assigned','running','review','waiting','blocked'];

    public static function plan(
        array $snapshot,array $thresholds,array $runtime,array $workRaw,
        bool $safePoint,?string $activeAlertFingerprint,int $now
    ): array {
        self::fields($runtime,['session','assignment'],'runtime');
        $analysis=ExecutionGuardrail::analyze($snapshot,$thresholds,$now);
        $session=AgentRuntime::session($runtime['session']);
        $assignment=AgentRuntime::assignment($runtime['assignment']);
        $work=SchedulerCore::workItem($workRaw);
        self::assertOwnership($analysis,$session,$assignment,$work);
        $activeAlert=self::nullableSha($activeAlertFingerprint,'active_alert_fingerprint');
        $alert=self::alertIntent($analysis,$activeAlert);

        $base=[
            'version'=>1,'guardrail'=>$analysis,'work_item'=>$work,'alert_intent'=>$alert,
            'pause_intent'=>null,'checkpoint'=>null,'requeue_intent'=>null,'escalation_intent'=>null,
            'execution_status'=>'observed',
        ];

        if($analysis['escalation_required']){
            $base['execution_status']='waiting_owner';
            $base['escalation_intent']=[
                'type'=>'owner.guardrail_escalation','action'=>'escalate','reason'=>'non_preemptible',
                'session_id'=>$session['session_id'],'work_item_id'=>$work['work_item_id'],
                'fingerprint'=>$analysis['alert_fingerprint'],
            ];
            return self::finish($base);
        }
        if(!$analysis['pause_required']) return self::finish($base);

        if(!$safePoint){
            $base['execution_status']='waiting_safe_point';
            $base['pause_intent']=[
                'type'=>'runtime.session.pause','action'=>'wait_safe_point','target_state'=>'active',
                'scope_type'=>'session','scope_id'=>$session['session_id'],'preemptibility'=>'safe_point',
                'safe_point_observed'=>false,'fingerprint'=>$analysis['alert_fingerprint'],
            ];
            return self::finish($base);
        }

        if($work['generation']===PHP_INT_MAX||$work['attempt']===PHP_INT_MAX)
            throw new InvalidArgumentException('WorkItem recovery counter overflow.');

        $fp=$analysis['alert_fingerprint'];
        $pause=PauseControl::state([
            'version'=>1,'pause_id'=>'pause:guardrail-'.substr($fp,0,24),
            'scope_type'=>'session','scope_id'=>$session['session_id'],'state'=>'unknown',
            'reason'=>'guardrail stuck safe pause','source'=>'system','created_at'=>$now,
            'activated_at'=>null,'released_at'=>null,'preemptibility'=>'safe_point','safe_point_at'=>null,
            'policy_version'=>null,'incident_id'=>null,'evidence_ref'=>null,
        ]);
        $h=$analysis['handoff'];
        $checkpoint=AgentRuntime::handoff([
            'version'=>1,'handoff_id'=>'handoff:guardrail-'.substr($fp,0,24),
            'assignment_id'=>$assignment['assignment_id'],'from_session_id'=>$session['session_id'],'to_session_id'=>null,
            'objective'=>'Recover guarded work safely','issue_ref'=>$work['source_ref'],'pr_ref'=>$h['pr_ref'],
            'sha'=>$h['sha'],'last_result'=>$h['last_result'],
            'evidence_ref'=>'controlbot:guardrail/'.substr($fp,0,24),
            'blocker'=>'Guardrail stuck: '.implode(',',$analysis['signals']),'next_action'=>$h['next_approach'],
        ]);
        $next=SchedulerCore::workItem(array_replace($work,[
            'state'=>'queued','generation'=>$work['generation']+1,'attempt'=>$work['attempt']+1,
            'reservation_id'=>null,'assigned_session_id'=>null,
        ]));
        $fence=[
            'previous_session_id'=>$session['session_id'],
            'previous_generation'=>$work['generation'],
            'next_generation'=>$next['generation'],
            'previous_attempt'=>$work['attempt'],
            'next_attempt'=>$next['attempt'],
        ];

        $base['execution_status']='pending_execution';
        $base['pause_intent']=[
            'type'=>'runtime.session.pause','action'=>'request_activation','target_state'=>'active',
            'safe_point_observed'=>true,'state'=>$pause,
        ];
        $base['checkpoint']=$checkpoint;
        $base['requeue_intent']=[
            'type'=>'scheduler.work_requeue','action'=>'requeue',
            'requires_receipts'=>['pause','checkpoint'],
            'release_reservation'=>[
                'reservation_id'=>$work['reservation_id'],'generation'=>$work['generation'],'active'=>false,
            ],
            'work_item'=>$next,'handoff'=>$checkpoint,'fence'=>$fence,
        ];
        return self::finish($base);
    }

    public static function reconcile(array $plan,array $receipts): array
    {
        self::fields($receipts,['pause','checkpoint','requeue'],'receipts');
        foreach($receipts as $value) if(!is_bool($value)) throw new InvalidArgumentException('receipt invalid.');
        if(($plan['execution_status']??null)!=='pending_execution'
            ||!is_array($plan['pause_intent']??null)||!is_array($plan['checkpoint']??null)
            ||!is_array($plan['requeue_intent']??null))
            throw new InvalidArgumentException('Executable guardrail plan required.');
        if(($plan['pause_intent']['action']??null)!=='request_activation'
            ||($plan['requeue_intent']['requires_receipts']??null)!==['pause','checkpoint'])
            throw new InvalidArgumentException('Guardrail execution contract invalid.');
        if($receipts['checkpoint']&&!$receipts['pause']) throw new InvalidArgumentException('Checkpoint before pause evidence.');
        if($receipts['requeue']&&!$receipts['checkpoint']) throw new InvalidArgumentException('Requeue before checkpoint evidence.');
        $complete=$receipts['pause']&&$receipts['checkpoint']&&$receipts['requeue'];
        $phase=$complete?'done':(!$receipts['pause']?'pause_pending':(!$receipts['checkpoint']?'checkpoint_pending':'requeue_pending'));
        return ['complete'=>$complete,'success'=>$complete,'recoverable'=>!$complete,'phase'=>$phase];
    }

    public static function ownerEventGuard(array $currentWork,string $sessionId,int $generation): array
    {
        return SchedulerRequeuePlan::ownerEventGuard($currentWork,$sessionId,$generation);
    }

    private static function assertOwnership(array $analysis,array $session,array $assignment,array $work): void
    {
        $sessionSource=$session['repository']===null||$session['issue_number']===null
            ?null:$session['repository'].'#'.$session['issue_number'];
        if(!in_array($work['state'],self::ACTIVE,true)
            ||!in_array($session['status'],self::SESSION_ACTIVE,true)
            ||!in_array($assignment['status'],self::ASSIGNMENT_ACTIVE,true)
            ||$sessionSource!==$work['source_ref']
            ||$session['assignment_id']!==$assignment['assignment_id']
            ||$assignment['session_id']!==$session['session_id']
            ||$work['assigned_session_id']!==$session['session_id']
            ||$assignment['project_id']!==$work['project_id']
            ||$assignment['source_ref']!==$work['source_ref']
            ||$analysis['handoff']['issue_ref']!==$work['source_ref'])
            throw new InvalidArgumentException('Guardrail runtime ownership mismatch.');
    }

    private static function alertIntent(array $analysis,?string $active): ?array
    {
        if($analysis['health']==='healthy')
            return $active===null?null:[
                'type'=>'observability.guardrail_alert','action'=>'resolve',
                'previous_fingerprint'=>$active,'current_fingerprint'=>null,
            ];
        $current=self::sha($analysis['alert_fingerprint'],'alert_fingerprint');
        if($active===null) return [
            'type'=>'observability.guardrail_alert','action'=>'open',
            'previous_fingerprint'=>null,'current_fingerprint'=>$current,
        ];
        if(hash_equals($active,$current)) return [
            'type'=>'observability.guardrail_alert','action'=>'keep',
            'previous_fingerprint'=>$active,'current_fingerprint'=>$current,
        ];
        return [
            'type'=>'observability.guardrail_alert','action'=>'replace',
            'previous_fingerprint'=>$active,'current_fingerprint'=>$current,
        ];
    }

    private static function finish(array $value): array
    {
        $basis=$value;
        return $basis+['fingerprint'=>hash('sha256',json_encode($basis,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
    }
    private static function nullableSha(mixed $value,string $label): ?string{return $value===null?null:self::sha($value,$label);}
    private static function sha(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[0-9a-f]{64}$/D',$value)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

use ControlBot\Runner\RunnerGateway;
use ControlBot\Scheduler\SchedulerCore;
use InvalidArgumentException;

final class PauseResumePlan
{
    private const ACTIVE_STATES=['assigned','running','review'];

    public static function plan(array $beforeRaw,array $releasedRaw,array $workRaw,array $orderRaw): array
    {
        $before=PauseControl::state($beforeRaw);
        $released=PauseControl::state($releasedRaw);
        self::assertRelease($before,$released);

        $work=SchedulerCore::workItem($workRaw);
        $order=RunnerGateway::order($orderRaw);

        if(!in_array($work['state'],self::ACTIVE_STATES,true)
            ||$work['reservation_id']===null
            ||$work['assigned_session_id']===null)
            throw new InvalidArgumentException('WorkItem cannot resume.');

        if($order['work_item_id']!==$work['work_item_id']
            ||$order['generation']!==$work['generation']
            ||$order['attempt']!==$work['attempt'])
            throw new InvalidArgumentException('ExecutionOrder WorkItem drift.');

        $basis=[
            'version'=>1,
            'resume_key'=>self::resumeKey($released['pause_id'],$work['work_item_id'],$order),
            'pause_id'=>$released['pause_id'],
            'work_item_id'=>$work['work_item_id'],
            'order_id'=>$order['order_id'],
            'attempt_id'=>$order['attempt_id'],
            'generation'=>$order['generation'],
            'attempt'=>$order['attempt'],
            'runner_id'=>$order['runner_id'],
            'capability'=>$order['capability'],
            'scope'=>$order['scope'],
            'issued_at'=>$order['issued_at'],
            'expires_at'=>$order['expires_at'],
            'instruction_ref'=>$order['instruction_ref'],
            'order_fingerprint'=>RunnerGateway::orderFingerprint($order),
            'create_work_item'=>false,
            'create_order'=>false,
            'reuse_current_attempt'=>true,
            'requires_existing_authority'=>true,
        ];
        return $basis+['fingerprint'=>self::fingerprint($basis)];
    }

    public static function assertReplay(array $existingRaw,array $incomingRaw): void
    {
        $existing=self::resumePlan($existingRaw);
        $incoming=self::resumePlan($incomingRaw);
        if(!hash_equals($existing['resume_key'],$incoming['resume_key']))
            throw new InvalidArgumentException('Resume key differs.');
        if($existing!==$incoming)
            throw new InvalidArgumentException('Conflicting resume replay.');
    }

    private static function assertRelease(array $before,array $released): void
    {
        if(!in_array($before['state'],['active','releasing'],true)
            ||$released['state']!=='released'
            ||$before['released_at']!==null
            ||$released['released_at']===null)
            throw new InvalidArgumentException('Pause release state invalid.');

        foreach([
            'pause_id','scope_type','scope_id','reason','source','created_at','activated_at',
            'preemptibility','safe_point_at','policy_version','incident_id','evidence_ref',
        ] as $field){
            if($before[$field]!==$released[$field])
                throw new InvalidArgumentException('Pause release provenance drift.');
        }
    }

    private static function resumePlan(mixed $raw): array
    {
        self::fields($raw,[
            'version','resume_key','pause_id','work_item_id','order_id','attempt_id','generation','attempt',
            'runner_id','capability','scope','issued_at','expires_at','instruction_ref','order_fingerprint','create_work_item',
            'create_order','reuse_current_attempt','requires_existing_authority','fingerprint',
        ],'PauseResumePlan');
        if(($raw['version']??null)!==1
            ||($raw['create_work_item']??null)!==false
            ||($raw['create_order']??null)!==false
            ||($raw['reuse_current_attempt']??null)!==true
            ||($raw['requires_existing_authority']??null)!==true)
            throw new InvalidArgumentException('PauseResumePlan flags invalid.');

        $basis=$raw;
        unset($basis['fingerprint']);
        $fingerprint=self::sha($raw['fingerprint']??null,'fingerprint');
        if(!hash_equals(self::fingerprint($basis),$fingerprint))
            throw new InvalidArgumentException('PauseResumePlan fingerprint invalid.');

        $resumeKey=self::sha($raw['resume_key']??null,'resume_key');
        $orderFingerprint=self::sha($raw['order_fingerprint']??null,'order_fingerprint');
        if(!is_string($raw['pause_id']??null)
            ||preg_match('#^[A-Za-z0-9][A-Za-z0-9._:@/\\\\-]{0,179}$#D',$raw['pause_id'])!==1
            ||!is_string($raw['work_item_id']??null)
            ||!is_string($raw['order_id']??null)||!is_string($raw['attempt_id']??null)
            ||!is_string($raw['runner_id']??null)||!is_string($raw['capability']??null)
            ||!is_string($raw['scope']??null)||!is_string($raw['instruction_ref']??null)
            ||!is_int($raw['issued_at']??null)||$raw['issued_at']<1
            ||!is_int($raw['expires_at']??null)||$raw['expires_at']<1
            ||!is_int($raw['generation']??null)||$raw['generation']<1
            ||!is_int($raw['attempt']??null)||$raw['attempt']<1)
            throw new InvalidArgumentException('PauseResumePlan identity invalid.');

        $work=SchedulerCore::workItem([
            'version'=>1,'work_item_id'=>$raw['work_item_id'],'project_id'=>'resume-validation',
            'source_ref'=>'pl0n3r/ControlBot#385','type'=>'resume','priority'=>'critical','state'=>'assigned',
            'dependency_ids'=>[],'required_capabilities'=>[$raw['capability']],
            'generation'=>$raw['generation'],'attempt'=>$raw['attempt'],
            'reservation_id'=>'resume-validation','assigned_session_id'=>'resume-session',
        ]);
        $order=RunnerGateway::order([
            'version'=>1,'order_id'=>$raw['order_id'],'attempt_id'=>$raw['attempt_id'],
            'generation'=>$raw['generation'],'work_item_id'=>$raw['work_item_id'],
            'runner_id'=>$raw['runner_id'],'capability'=>$raw['capability'],'attempt'=>$raw['attempt'],
            'scope'=>$raw['scope'],'issued_at'=>$raw['issued_at'],'expires_at'=>$raw['expires_at'],
            'instruction_ref'=>$raw['instruction_ref'],
        ]);
        if($work['work_item_id']!==$order['work_item_id'])
            throw new InvalidArgumentException('PauseResumePlan work/order mismatch.');
        if(!hash_equals(RunnerGateway::orderFingerprint($order),$orderFingerprint))
            throw new InvalidArgumentException('PauseResumePlan order_fingerprint invalid.');
        $expectedKey=self::resumeKey($raw['pause_id'],$raw['work_item_id'],$order);
        if(!hash_equals($expectedKey,$resumeKey))
            throw new InvalidArgumentException('PauseResumePlan resume_key invalid.');

        return $basis+[
            'resume_key'=>$resumeKey,
            'order_fingerprint'=>$orderFingerprint,
            'fingerprint'=>$fingerprint,
        ];
    }

    private static function resumeKey(string $pauseId,string $workItemId,array $order): string
    {
        return hash('sha256',implode('|',[
            $pauseId,$workItemId,$order['order_id'],$order['attempt_id'],
            (string)$order['generation'],(string)$order['attempt'],
        ]));
    }

    private static function sha(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[0-9a-f]{64}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

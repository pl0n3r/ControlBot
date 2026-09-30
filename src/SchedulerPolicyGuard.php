<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

use ControlBot\Runtime\PresenceAdapter;
use InvalidArgumentException;

final class SchedulerPolicyGuard
{
    private const POLICY='factory-dispatcher-v2';
    private const ACTIVE_STATES=['assigned','running','review'];
    private const LOWER_PRIORITIES=[
        'critical'=>['high','medium','low'],
        'high'=>['medium','low'],
        'medium'=>['low'],
        'low'=>[],
    ];

    public static function focusGuard(
        ValidatedSchedulerSelection $selection,
        array $workRows,
        array $traceRaw,
    ): array {
        $context=self::context($selection,$workRows,$traceRaw);
        $trace=$context['trace'];
        $selected=$context['selected_key'];
        $protectedReady=[];
        foreach($context['ready_ids'] as $id){
            if(self::protected($context['work'][$id])) $protectedReady[]=$id;
        }
        sort($protectedReady,SORT_STRING);

        if($trace['focus_influenced'] && $protectedReady!==[] && !in_array($selected,$protectedReady,true))
            throw new InvalidArgumentException('Weekly focus cannot outrank protected work.');

        $basis=[
            'version'=>1,
            'policy_ref'=>self::POLICY,
            'request_fingerprint'=>$trace['request_fingerprint'],
            'selected_key'=>$selected,
            'focus'=>[
                'version'=>$trace['focus_version'],
                'position'=>$trace['focus_position'],
                'influenced'=>$trace['focus_influenced'],
            ],
            'ready_ids'=>$context['ready_ids'],
            'excluded_ids'=>$context['excluded_ids'],
            'protected_ready_ids'=>$protectedReady,
        ];
        return $basis+['fingerprint'=>self::fingerprint($basis)];
    }

    public static function preemptionIntent(
        ValidatedSchedulerSelection $selection,
        array $workRows,
        array $traceRaw,
        array $presenceSnapshot,
        string $runningWorkItemId,
        string $sessionId,
        int $expectedGeneration,
    ): array {
        $context=self::context($selection,$workRows,$traceRaw);
        $selected=$context['work'][$context['selected_key']];
        $runningId=self::id($runningWorkItemId,'running_work_item_id');
        if(!isset($context['work'][$runningId])) throw new InvalidArgumentException('Running WorkItem unknown.');
        if(hash_equals($runningId,$selected['work_item_id'])) throw new InvalidArgumentException('Selected WorkItem cannot preempt itself.');
        $running=$context['work'][$runningId];
        if(!in_array($running['state'],self::ACTIVE_STATES,true))
            throw new InvalidArgumentException('Running WorkItem state invalid.');
        $session=self::ref($sessionId,'session_id');
        if($running['assigned_session_id']===null || !hash_equals($running['assigned_session_id'],$session))
            throw new InvalidArgumentException('Running WorkItem owner drift.');
        if($expectedGeneration!==$running['generation'])
            throw new InvalidArgumentException('Running WorkItem generation drift.');

        $guard=PresenceAdapter::replanGuard($presenceSnapshot,$session,$expectedGeneration,'preempt');
        $presence=self::presenceSession($presenceSnapshot,$session);
        if(($presence['work_item']??null)!==$running['source_ref'])
            throw new InvalidArgumentException('Presence WorkItem drift.');

        $reasons=[];
        if(!self::protected($selected)) $reasons[]='selected_not_preemption_authority';
        if(self::protected($running)) $reasons[]='running_work_protected';
        elseif(!in_array($running['priority'],self::LOWER_PRIORITIES[$selected['priority']],true)) $reasons[]='running_priority_not_lower';
        foreach(($guard['reasons']??[]) as $reason) $reasons[]=$reason;
        $reasons=array_values(array_unique($reasons));
        sort($reasons,SORT_STRING);

        $basis=[
            'version'=>1,
            'policy_ref'=>self::POLICY,
            'request_fingerprint'=>$context['trace']['request_fingerprint'],
            'selected_work_item_id'=>$selected['work_item_id'],
            'running_work_item_id'=>$running['work_item_id'],
            'session_id'=>$session,
            'generation'=>$running['generation'],
            'allowed'=>$reasons===[],
            'reasons'=>$reasons,
            'presence_guard'=>[
                'allowed'=>$guard['allowed'],
                'reasons'=>$guard['reasons'],
                'assignment_id'=>$guard['assignment_id'],
                'generation'=>$guard['generation'],
            ],
        ];
        return $basis+['fingerprint'=>self::fingerprint($basis)];
    }

    private static function context(
        ValidatedSchedulerSelection $selection,
        array $workRows,
        array $traceRaw,
    ): array {
        $value=$selection->value();
        $trace=self::trace($traceRaw);
        if(!hash_equals($value['request_fingerprint'],$trace['request_fingerprint']))
            throw new InvalidArgumentException('Policy trace request drift.');
        if(!hash_equals($value['selected_key'],$trace['selected_key']))
            throw new InvalidArgumentException('Policy trace selection drift.');

        if(!array_is_list($workRows)||$workRows===[]||count($workRows)>64)
            throw new InvalidArgumentException('WorkItem set invalid.');
        $work=[];
        foreach($workRows as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('WorkItem invalid.');
            $item=SchedulerCore::workItem($raw);
            if(isset($work[$item['work_item_id']])) throw new InvalidArgumentException('WorkItem duplicated.');
            $work[$item['work_item_id']]=$item;
        }
        ksort($work,SORT_STRING);

        $selectedKey=$value['selected_key'];
        if(!isset($work[$selectedKey])) throw new InvalidArgumentException('Selected WorkItem missing.');
        self::assertSelectedWork($value['selected'],$work[$selectedKey]);

        $readyIds=array_merge([$selectedKey],$value['telemetry']['ready_not_selected']);
        $excludedIds=array_keys($value['telemetry']['excluded']);
        sort($readyIds,SORT_STRING);
        sort($excludedIds,SORT_STRING);
        $selectionIds=array_values(array_unique(array_merge($readyIds,$excludedIds)));
        sort($selectionIds,SORT_STRING);
        $workIds=array_keys($work);
        sort($workIds,SORT_STRING);
        if($selectionIds!==$workIds) throw new InvalidArgumentException('Selection WorkItem set drift.');

        return [
            'trace'=>$trace,
            'selected_key'=>$selectedKey,
            'ready_ids'=>$readyIds,
            'excluded_ids'=>$excludedIds,
            'work'=>$work,
        ];
    }

    private static function assertSelectedWork(array $selected,array $work): void
    {
        $expected=[
            'key'=>$work['work_item_id'],
            'source_ref'=>$work['source_ref'],
            'priority'=>$work['priority'],
            'generation'=>$work['generation'],
            'required_capabilities'=>$work['required_capabilities'],
        ];
        foreach($expected as $field=>$value){
            if(($selected[$field]??null)!==$value)
                throw new InvalidArgumentException('Selected WorkItem provenance drift.');
        }
    }

    private static function trace(array $raw): array
    {
        self::fields($raw,[
            'version','policy_ref','request_fingerprint','selected_key',
            'focus_version','focus_position','focus_influenced',
        ],'PolicyTrace');
        if(($raw['version']??null)!==1||($raw['policy_ref']??null)!==self::POLICY)
            throw new InvalidArgumentException('Policy trace version invalid.');
        if(!is_bool($raw['focus_influenced'])) throw new InvalidArgumentException('focus_influenced invalid.');
        $focusVersion=self::nullablePositive($raw['focus_version'],'focus_version');
        $focusPosition=self::nullablePositive($raw['focus_position'],'focus_position');
        if(($focusVersion===null)!==($focusPosition===null))
            throw new InvalidArgumentException('Focus provenance incomplete.');
        if($raw['focus_influenced'] && $focusVersion===null)
            throw new InvalidArgumentException('Influential focus requires provenance.');
        return [
            'version'=>1,
            'policy_ref'=>self::POLICY,
            'request_fingerprint'=>self::sha($raw['request_fingerprint'],'request_fingerprint'),
            'selected_key'=>self::id($raw['selected_key'],'selected_key'),
            'focus_version'=>$focusVersion,
            'focus_position'=>$focusPosition,
            'focus_influenced'=>$raw['focus_influenced'],
        ];
    }

    private static function protected(array $work): bool
    {
        return in_array($work['type'],['incident','health'],true)||$work['priority']==='critical';
    }

    private static function presenceSession(array $snapshot,string $sessionId): array
    {
        if(($snapshot['policy_ref']??null)!==self::POLICY||!is_array($snapshot['sessions']??null)
            ||!array_is_list($snapshot['sessions']))
            throw new InvalidArgumentException('Presence snapshot invalid.');
        foreach($snapshot['sessions'] as $row){
            if(is_array($row)&&($row['session_id']??null)===$sessionId) return $row;
        }
        throw new InvalidArgumentException('Presence session missing.');
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);
        sort($actual,SORT_STRING);
        sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function nullablePositive(mixed $value,string $label): ?int
    {
        if($value===null) return null;
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function sha(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[0-9a-f]{64}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function id(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<1||strlen($value)>180||str_contains($value,'@')
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\\/#-]*$/D',$value)!==1
            ||preg_match('/(?:token|secret|password|cookie|authorization|dsn):/i',$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}

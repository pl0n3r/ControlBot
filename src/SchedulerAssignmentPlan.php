<?php
declare(strict_types=1);
namespace ControlBot\Scheduler;
use ControlBot\Runtime\AgentRuntime;
use InvalidArgumentException;
use LogicException;

final class ValidatedSchedulerSelection
{
    private function __construct(private readonly array $value) {}

    public static function fromDecision(array $request,array $decision,array $currentRows): self
    {
        return new self(SchedulerSelection::validateDecision($request,$decision,$currentRows));
    }

    public function value(): array
    {
        return $this->value;
    }

    private function __clone(): void {}

    public function __serialize(): array
    {
        throw new LogicException('ValidatedSchedulerSelection cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('ValidatedSchedulerSelection cannot be unserialized.');
    }
}

final class SchedulerAssignmentPlan
{
    private const POLICY='factory-dispatcher-v2';

    public static function plan(
        mixed $selectionRaw,array $workRaw,array $sessionRaw,array $agentRaw,
        string $reservationId,string $assignmentId,int $now,int $staleAfter=90,int $offlineAfter=300
    ): array {
        if(!$selectionRaw instanceof ValidatedSchedulerSelection)
            throw new InvalidArgumentException('Validated selection provenance invalid.');
        $selection=$selectionRaw->value();$work=SchedulerCore::workItem($workRaw);
        $session=AgentRuntime::session($sessionRaw);$agent=AgentRuntime::agent($agentRaw);
        if(!in_array($work['state'],['queued','eligible'],true)||$work['reservation_id']!==null||$work['assigned_session_id']!==null)
            throw new InvalidArgumentException('WorkItem already owned or not assignable.');
        self::matchesWork($selection,$work);
        if($session['status']!=='idle'||$session['assignment_id']!==null) throw new InvalidArgumentException('Session not idle.');
        $health=AgentRuntime::sessionHealth($session,$now,$staleAfter,$offlineAfter);
        if(!$health['eligible']||$health['health']!=='healthy') throw new InvalidArgumentException('Session not healthy.');
        if($session['account_id']!==$selection['selected']['account_id']) throw new InvalidArgumentException('Session account mismatch.');
        if($session['agent_id']!==$agent['agent_id']) throw new InvalidArgumentException('Session agent mismatch.');
        foreach($work['required_capabilities'] as $capability)
            if(!in_array($capability,$agent['capabilities'],true)) throw new InvalidArgumentException('Agent capability missing.');

        $reservation=['reservation_id'=>$reservationId,'owner_session_id'=>$session['session_id'],'generation'=>$work['generation'],'active'=>true];
        $assignedWork=SchedulerCore::workItem(array_replace($work,[
            'state'=>'assigned','reservation_id'=>$reservationId,'assigned_session_id'=>$session['session_id'],
        ]));
        $assignment=AgentRuntime::assignment([
            'version'=>1,'assignment_id'=>$assignmentId,'session_id'=>$session['session_id'],
            'project_id'=>$work['project_id'],'source_ref'=>$work['source_ref'],'status'=>'assigned',
        ]);
        [$repository,$issue]=self::workTarget($work['source_ref']);
        $assignedSession=AgentRuntime::session(array_replace($session,[
            'status'=>'assigned','assignment_id'=>$assignment['assignment_id'],'repository'=>$repository,'issue_number'=>$issue,
        ]));
        $preconditions=[
            'work_item_sha256'=>self::fingerprint($work),'session_sha256'=>self::fingerprint($session),
            'agent_sha256'=>self::fingerprint($agent),
        ];
        $preconditions['cas_sha256']=self::fingerprint($preconditions);
        $plan=[
            'version'=>1,'policy_ref'=>self::POLICY,
            'selection_ref'=>['request_fingerprint'=>$selection['request_fingerprint'],'selected_key'=>$selection['selected_key']],
            'preconditions'=>$preconditions,'reservation'=>$reservation,'work_item'=>$assignedWork,
            'session'=>$assignedSession,'assignment'=>$assignment,'required_capabilities'=>$work['required_capabilities'],
        ];
        return $plan+['fingerprint'=>self::fingerprint($plan)];
    }

    private static function matchesWork(array $selection,array $work): void
    {
        $selected=$selection['selected'];$expected=[
            'key'=>$work['work_item_id'],'source_ref'=>$work['source_ref'],'priority'=>$work['priority'],
            'generation'=>$work['generation'],'required_capabilities'=>$work['required_capabilities'],
        ];
        foreach($expected as $field=>$value)
            if(($selected[$field]??null)!==$value) throw new InvalidArgumentException('Validated selection WorkItem mismatch.');
        if(!is_string($selected['account_id']??null)||$selected['account_id']==='') throw new InvalidArgumentException('Validated selection account invalid.');
    }

    private static function workTarget(string $sourceRef): array
    {
        $pos=strrpos($sourceRef,'#');$issue=$pos===false?'':substr($sourceRef,$pos+1);
        if($pos===false||$issue===''||preg_match('/^[1-9][0-9]*$/D',$issue)!==1) throw new InvalidArgumentException('source_ref invalid.');
        return [substr($sourceRef,0,$pos),(int)$issue];
    }

    private static function fingerprint(array $value): string
    {return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
}

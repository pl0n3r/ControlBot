<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

use ControlBot\Runtime\AgentRuntime;
use InvalidArgumentException;
use LogicException;

final class PreparedSchedulerAssignmentPlan
{
    private function __construct(private readonly array $value) {}

    public static function fromSelection(
        mixed $selection,array $work,array $session,array $agent,
        string $reservationId,string $assignmentId,int $now,int $staleAfter=90,int $offlineAfter=300
    ): self {
        return new self(SchedulerAssignmentPlan::plan(
            $selection,$work,$session,$agent,$reservationId,$assignmentId,$now,$staleAfter,$offlineAfter
        ));
    }

    public function value(): array { return $this->value; }
    public function __clone(): void { throw new LogicException('PreparedSchedulerAssignmentPlan cannot be cloned.'); }
    public function __serialize(): array { throw new LogicException('PreparedSchedulerAssignmentPlan cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new LogicException('PreparedSchedulerAssignmentPlan cannot be unserialized.'); }
}

final class SchedulerAssignmentCommit
{
    private const POLICY='factory-dispatcher-v2';

    public static function commit(
        mixed $prepared,array $workRaw,array $sessionRaw,array $agentRaw,
        int $now,int $staleAfter=90,int $offlineAfter=300
    ): array {
        if(!$prepared instanceof PreparedSchedulerAssignmentPlan)
            throw new InvalidArgumentException('Assignment plan provenance invalid.');
        $plan=self::plan($prepared->value());
        $work=SchedulerCore::workItem($workRaw);
        $session=AgentRuntime::session($sessionRaw);
        $agent=AgentRuntime::agent($agentRaw);

        if(!in_array($work['state'],['queued','eligible'],true)||$work['reservation_id']!==null||$work['assigned_session_id']!==null)
            throw new InvalidArgumentException('WorkItem changed ownership before commit.');
        if($session['status']!=='idle'||$session['assignment_id']!==null)
            throw new InvalidArgumentException('Session changed ownership before commit.');
        if($session['agent_id']!==$agent['agent_id']) throw new InvalidArgumentException('Session agent drift.');
        $health=AgentRuntime::sessionHealth($session,$now,$staleAfter,$offlineAfter);
        if(!$health['eligible']||$health['health']!=='healthy') throw new InvalidArgumentException('Session health drift.');
        foreach($work['required_capabilities'] as $capability)
            if(!in_array($capability,$agent['capabilities'],true)) throw new InvalidArgumentException('Agent capability drift.');

        $expected=self::preconditions($work,$session,$agent);
        if($expected!==$plan['preconditions']) throw new InvalidArgumentException('Assignment CAS drift.');

        $reservation=[
            'reservation_id'=>$plan['reservation']['reservation_id'],
            'owner_session_id'=>$session['session_id'],'generation'=>$work['generation'],'active'=>true,
        ];
        $assignedWork=SchedulerCore::workItem(array_replace($work,[
            'state'=>'assigned','reservation_id'=>$reservation['reservation_id'],'assigned_session_id'=>$session['session_id'],
        ]));
        $assignment=AgentRuntime::assignment([
            'version'=>1,'assignment_id'=>$plan['assignment']['assignment_id'],'session_id'=>$session['session_id'],
            'project_id'=>$work['project_id'],'source_ref'=>$work['source_ref'],'status'=>'assigned',
        ]);
        [$repository,$issue]=self::workTarget($work['source_ref']);
        $assignedSession=AgentRuntime::session(array_replace($session,[
            'status'=>'assigned','assignment_id'=>$assignment['assignment_id'],'repository'=>$repository,'issue_number'=>$issue,
        ]));

        if($plan['selection_ref']['selected_key']!==$work['work_item_id']
            ||$plan['required_capabilities']!==$work['required_capabilities']
            ||$plan['reservation']!==$reservation||$plan['work_item']!==$assignedWork
            ||$plan['session']!==$assignedSession||$plan['assignment']!==$assignment)
            throw new InvalidArgumentException('Assignment plan projection drift.');

        $commit=[
            'version'=>1,'policy_ref'=>self::POLICY,'expected'=>$expected,
            'writes'=>[
                'reservation'=>$reservation,'work_item'=>$assignedWork,
                'session'=>$assignedSession,'assignment'=>$assignment,
            ],
            'plan_fingerprint'=>$plan['fingerprint'],
        ];
        return $commit+['commit_fingerprint'=>self::fingerprint($commit)];
    }

    private static function plan(array $raw): array
    {
        self::fields($raw,[
            'version','policy_ref','selection_ref','preconditions','reservation','work_item',
            'session','assignment','required_capabilities','fingerprint',
        ],'AssignmentPlan');
        if(($raw['version']??null)!==1||($raw['policy_ref']??null)!==self::POLICY)
            throw new InvalidArgumentException('AssignmentPlan policy invalid.');
        self::fields($raw['selection_ref']??null,['request_fingerprint','selected_key'],'SelectionRef');
        $selection=[
            'request_fingerprint'=>self::sha($raw['selection_ref']['request_fingerprint'],'request_fingerprint'),
            'selected_key'=>self::id($raw['selection_ref']['selected_key'],'selected_key'),
        ];
        $pre=self::planPreconditions($raw['preconditions']??null);
        $reservation=self::reservation($raw['reservation']??null);
        $work=SchedulerCore::workItem($raw['work_item']??[]);
        $session=AgentRuntime::session($raw['session']??[]);
        $assignment=AgentRuntime::assignment($raw['assignment']??[]);
        if(($raw['required_capabilities']??null)!==$work['required_capabilities'])
            throw new InvalidArgumentException('AssignmentPlan capabilities invalid.');
        $canonical=[
            'version'=>1,'policy_ref'=>self::POLICY,'selection_ref'=>$selection,'preconditions'=>$pre,
            'reservation'=>$reservation,'work_item'=>$work,'session'=>$session,'assignment'=>$assignment,
            'required_capabilities'=>$work['required_capabilities'],
        ];
        $fingerprint=self::sha($raw['fingerprint'],'fingerprint');
        if(!hash_equals(self::fingerprint($canonical),$fingerprint))
            throw new InvalidArgumentException('AssignmentPlan fingerprint invalid.');
        return $canonical+['fingerprint'=>$fingerprint];
    }

    private static function preconditions(array $work,array $session,array $agent): array
    {
        $pre=[
            'work_item_sha256'=>self::fingerprint($work),
            'session_sha256'=>self::fingerprint($session),
            'agent_sha256'=>self::fingerprint($agent),
        ];
        return $pre+['cas_sha256'=>self::fingerprint($pre)];
    }

    private static function planPreconditions(mixed $raw): array
    {
        self::fields($raw,['work_item_sha256','session_sha256','agent_sha256','cas_sha256'],'AssignmentPreconditions');
        $pre=[
            'work_item_sha256'=>self::sha($raw['work_item_sha256'],'work_item_sha256'),
            'session_sha256'=>self::sha($raw['session_sha256'],'session_sha256'),
            'agent_sha256'=>self::sha($raw['agent_sha256'],'agent_sha256'),
        ];
        $cas=self::sha($raw['cas_sha256'],'cas_sha256');
        if(!hash_equals(self::fingerprint($pre),$cas)) throw new InvalidArgumentException('Assignment CAS fingerprint invalid.');
        return $pre+['cas_sha256'=>$cas];
    }

    private static function reservation(mixed $raw): array
    {
        self::fields($raw,['reservation_id','owner_session_id','generation','active'],'Reservation');
        if(!is_string($raw['reservation_id'])||!is_string($raw['owner_session_id'])
            ||!is_int($raw['generation'])||$raw['generation']<1||$raw['active']!==true)
            throw new InvalidArgumentException('Reservation invalid.');
        return $raw;
    }

    private static function workTarget(string $sourceRef): array
    {
        $pos=strrpos($sourceRef,'#');$issue=$pos===false?'':substr($sourceRef,$pos+1);
        if($pos===false||preg_match('/^[1-9][0-9]*$/D',$issue)!==1) throw new InvalidArgumentException('source_ref invalid.');
        return [substr($sourceRef,0,$pos),(int)$issue];
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
    private static function sha(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[0-9a-f]{64}$/D',$value)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
    private static function id(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$value)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}

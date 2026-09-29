<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

use ControlBot\Runtime\AgentRuntime;
use ControlBot\Runtime\PresenceAdapter;
use InvalidArgumentException;

final class SchedulerRequeuePlan
{
    private const POLICY='factory-dispatcher-v2';
    private const ACTIVE_STATES=['assigned','running','review'];

    public static function plan(
        array $beforePresence,
        array $afterPresence,
        array $workRaw,
        array $assignmentRaw,
        array $reservationRaw,
        array $handoffRaw,
        string $sessionId,
    ): array {
        $work=SchedulerCore::workItem($workRaw);
        $assignment=AgentRuntime::assignment($assignmentRaw);
        $reservation=self::reservation($reservationRaw);
        $handoff=AgentRuntime::handoff($handoffRaw);
        $session=self::ref($sessionId,'session_id');

        if(!in_array($work['state'],self::ACTIVE_STATES,true))
            throw new InvalidArgumentException('WorkItem not active for requeue.');
        if($work['generation']===PHP_INT_MAX||$work['attempt']===PHP_INT_MAX)
            throw new InvalidArgumentException('WorkItem requeue counter overflow.');

        $event=PresenceAdapter::transitionEvent($beforePresence,$afterPresence,$session);
        if($event===null||($event['type']??null)!=='stale'||($event['policy_ref']??null)!==self::POLICY)
            throw new InvalidArgumentException('Requeue requires stale presence transition.');

        $guard=PresenceAdapter::replanGuard($afterPresence,$session,$work['generation'],'reassign');
        if(($guard['allowed']??false)!==true
            ||($guard['generation']??null)!==$work['generation']
            ||($guard['assignment_id']??null)!==$assignment['assignment_id'])
            throw new InvalidArgumentException('Presence reassign guard rejected.');

        self::assertOwnership($work,$assignment,$reservation,$handoff,$session);

        $releasedReservation=$reservation;
        $releasedReservation['active']=false;

        $nextWork=SchedulerCore::workItem(array_replace($work,[
            'state'=>'queued',
            'generation'=>$work['generation']+1,
            'attempt'=>$work['attempt']+1,
            'reservation_id'=>null,
            'assigned_session_id'=>null,
        ]));

        $fence=[
            'previous_session_id'=>$session,
            'previous_generation'=>$work['generation'],
            'next_generation'=>$nextWork['generation'],
            'previous_attempt'=>$work['attempt'],
            'next_attempt'=>$nextWork['attempt'],
        ];

        $basis=[
            'version'=>1,
            'policy_ref'=>self::POLICY,
            'stale_event_fingerprint'=>self::sha($event['fingerprint']??null,'stale_event_fingerprint'),
            'release_reservation'=>$releasedReservation,
            'work_item'=>$nextWork,
            'handoff'=>$handoff,
            'fence'=>$fence,
        ];
        return $basis+['fingerprint'=>self::fingerprint($basis)];
    }

    public static function ownerEventGuard(array $workRaw,string $sessionId,int $generation): array
    {
        $work=SchedulerCore::workItem($workRaw);
        $session=self::ref($sessionId,'session_id');
        if($generation<1) throw new InvalidArgumentException('generation invalid.');

        $reasons=[];
        if($work['assigned_session_id']===null) $reasons[]='work_unowned';
        elseif(!hash_equals($work['assigned_session_id'],$session)) $reasons[]='stale_owner';
        if($work['generation']!==$generation) $reasons[]='stale_generation';
        if(!in_array($work['state'],self::ACTIVE_STATES,true)) $reasons[]='work_not_active';
        sort($reasons,SORT_STRING);

        return [
            'version'=>1,
            'policy_ref'=>self::POLICY,
            'session_id'=>$session,
            'event_generation'=>$generation,
            'current_generation'=>$work['generation'],
            'allowed'=>$reasons===[],
            'reasons'=>$reasons,
        ];
    }

    private static function assertOwnership(
        array $work,array $assignment,array $reservation,array $handoff,string $session
    ): void {
        if($work['reservation_id']!==$reservation['reservation_id']
            ||$work['assigned_session_id']!==$session
            ||$reservation['owner_session_id']!==$session
            ||$reservation['generation']!==$work['generation']
            ||$reservation['active']!==true)
            throw new InvalidArgumentException('Reservation ownership mismatch.');

        if($assignment['session_id']!==$session
            ||$assignment['project_id']!==$work['project_id']
            ||$assignment['source_ref']!==$work['source_ref']
            ||!in_array($assignment['status'],['assigned','running','review','waiting','blocked'],true))
            throw new InvalidArgumentException('Assignment ownership mismatch.');

        if($handoff['assignment_id']!==$assignment['assignment_id']
            ||$handoff['from_session_id']!==$session
            ||$handoff['to_session_id']!==null
            ||$handoff['issue_ref']!==$work['source_ref'])
            throw new InvalidArgumentException('Handoff ownership mismatch.');
    }

    private static function reservation(mixed $raw): array
    {
        self::fields($raw,['reservation_id','owner_session_id','generation','active'],'Reservation');
        if(!is_bool($raw['active'])||!is_int($raw['generation'])||$raw['generation']<1)
            throw new InvalidArgumentException('Reservation invalid.');
        return [
            'reservation_id'=>self::ref($raw['reservation_id'],'reservation_id'),
            'owner_session_id'=>self::ref($raw['owner_session_id'],'owner_session_id'),
            'generation'=>$raw['generation'],
            'active'=>$raw['active'],
        ];
    }

    private static function fields(mixed $raw,array $expected,string $label): void
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<1||strlen($value)>180||str_contains($value,'@')
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]*$/D',$value)!==1
            ||preg_match('/(?:token|secret|password|cookie|authorization|dsn):/i',$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
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
}

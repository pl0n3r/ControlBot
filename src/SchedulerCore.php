<?php
declare(strict_types=1);

namespace ControlBot\Scheduler;

use InvalidArgumentException;

final class SchedulerCore
{
    private const STATES=['queued','eligible','reserved','assigned','running','review','verified','done','waiting_dependency','waiting_human','blocked','paused','failed','cancelled'];
    private const PRIORITIES=['critical','high','medium','low'];
    private const APPROVAL=['not_required','approved','pending','rejected','unknown'];
    private const FREEZE=['clear','active','unknown'];
    private const DEPENDENCY=['satisfied','open','unknown'];

    public static function workItem(array $raw): array
    {
        self::fields($raw,[
            'version','work_item_id','project_id','source_ref','type','priority','state',
            'dependency_ids','required_capabilities','generation','attempt','reservation_id','assigned_session_id',
        ],'WorkItem');
        if($raw['version']!==1) throw new InvalidArgumentException('WorkItem version invalid.');
        $reservation=self::nullableRef($raw['reservation_id'],'reservation_id');
        $assigned=self::nullableRef($raw['assigned_session_id'],'assigned_session_id');
        if($assigned!==null && $reservation===null) throw new InvalidArgumentException('Assigned WorkItem requires reservation.');
        if($raw['state']==='reserved' && $reservation===null) throw new InvalidArgumentException('Reserved WorkItem requires reservation.');
        if(in_array($raw['state'],['assigned','running','review'],true) && $assigned===null)
            throw new InvalidArgumentException('Active WorkItem requires assigned session.');
        return [
            'version'=>1,
            'work_item_id'=>self::id($raw['work_item_id'],'work_item_id'),
            'project_id'=>self::id($raw['project_id'],'project_id'),
            'source_ref'=>self::workRef($raw['source_ref'],'source_ref'),
            'type'=>self::slug($raw['type'],'type'),
            'priority'=>self::enum($raw['priority'],self::PRIORITIES,'priority'),
            'state'=>self::enum($raw['state'],self::STATES,'state'),
            'dependency_ids'=>self::unique($raw['dependency_ids'],'dependency_ids','id'),
            'required_capabilities'=>self::unique($raw['required_capabilities'],'required_capabilities','slug'),
            'generation'=>self::positiveInt($raw['generation'],'generation'),
            'attempt'=>self::positiveInt($raw['attempt'],'attempt'),
            'reservation_id'=>$reservation,
            'assigned_session_id'=>$assigned,
        ];
    }

    public static function readiness(array $workRaw,array $context): array
    {
        $work=self::workItem($workRaw);
        self::fields($context,[
            'dependency_states','approval_state','freeze_state','reservations',
            'capacity','expected_generation','expected_owner_session_id',
        ],'SchedulerContext');
        $deps=self::dependencyStates($context['dependency_states'],$work['dependency_ids']);
        $approval=self::enum($context['approval_state'],self::APPROVAL,'approval_state');
        $freeze=self::enum($context['freeze_state'],self::FREEZE,'freeze_state');
        $reservations=self::reservations($context['reservations']);
        $capacity=self::capacity($context['capacity']);
        $expectedGeneration=self::positiveInt($context['expected_generation'],'expected_generation');
        $expectedOwner=self::nullableRef($context['expected_owner_session_id'],'expected_owner_session_id');

        $reasons=[];
        if(!in_array($work['state'],['queued','eligible','reserved'],true)) $reasons[]='workitem_not_assignable';
        if($work['priority']==='low') $reasons[]='factory_priority_unsupported';
        $open=array_keys(array_filter($deps,static fn(string $v):bool=>$v==='open'));
        $unknown=array_keys(array_filter($deps,static fn(string $v):bool=>$v==='unknown'));
        if($open!==[]) $reasons[]='open_dependencies';
        if($unknown!==[]) $reasons[]='unknown_dependencies';
        if($approval==='pending') $reasons[]='pending_human_gate';
        elseif($approval==='unknown') $reasons[]='approval_unknown';
        elseif($approval==='rejected') $reasons[]='approval_rejected';
        if($freeze==='active') $reasons[]='freeze_active';
        elseif($freeze==='unknown') $reasons[]='freeze_unknown';
        if($expectedGeneration!==$work['generation']) $reasons[]='stale_generation';

        $active=array_values(array_filter($reservations,static fn(array $r):bool=>$r['active']));
        if(count($active)>1) throw new InvalidArgumentException('Multiple active reservation owners invalid.');
        $current=$active[0]??null;
        if($current===null && ($work['reservation_id']!==null || $work['assigned_session_id']!==null)) {
            $reasons[]='missing_active_reservation';
        } elseif($current!==null) {
            if($work['reservation_id']===null || $current['reservation_id']!==$work['reservation_id']) $reasons[]='incompatible_reservation';
            if($current['generation']!==$work['generation']) $reasons[]='stale_reservation_generation';
            if(($work['assigned_session_id']!==null && $current['owner_session_id']!==$work['assigned_session_id'])
                || ($expectedOwner!==null && $current['owner_session_id']!==$expectedOwner)) $reasons[]='stale_owner';
        }
        if(!$capacity['eligible'] || $capacity['free_capacity']<1) $reasons[]='account_capacity_unavailable';

        $reasons=array_values(array_unique($reasons)); sort($reasons); sort($open); sort($unknown);
        return [
            'work_item_id'=>$work['work_item_id'],'generation'=>$work['generation'],'ready'=>$reasons===[],
            'reasons'=>$reasons,'open_dependencies'=>$open,'unknown_dependencies'=>$unknown,
            'reservation_owner'=>$current['owner_session_id']??null,'account_id'=>$capacity['account_id'],
        ];
    }

    public static function dispatchableCapacity(array $presenceRaw,array $workRows,array $constraints): array
    {
        $presence=self::presenceCapacity($presenceRaw);
        if(!array_is_list($workRows)||count($workRows)>64) throw new InvalidArgumentException('Dispatch work rows invalid.');
        self::fields($constraints,['active_claims','project_concurrency'],'DispatchConstraints');
        $activeClaims=self::activeClaims($constraints['active_claims']);

        $work=[]; $projects=[];
        foreach($workRows as $raw){
            self::fields($raw,['work_item','dependency_states','claims'],'DispatchWork');
            if(!is_array($raw['work_item'])) throw new InvalidArgumentException('Dispatch WorkItem invalid.');
            $item=self::workItem($raw['work_item']);
            if(isset($work[$item['work_item_id']])) throw new InvalidArgumentException('Dispatch WorkItem duplicated.');
            $deps=self::dependencyStates($raw['dependency_states'],$item['dependency_ids']);
            $claims=self::resourceClaims($raw['claims']);
            $projects[$item['project_id']]=true;
            $work[$item['work_item_id']]=['item'=>$item,'deps'=>$deps,'claims'=>$claims,'reasons'=>[]];
        }
        $concurrency=self::projectConcurrency($constraints['project_concurrency'],array_keys($projects));

        foreach($work as $id=>&$row){
            $item=$row['item']; $reasons=[];
            if(!in_array($item['state'],['queued','eligible'],true)) $reasons[]='workitem_not_dispatchable';
            foreach($row['deps'] as $state){
                if($state==='open') $reasons[]='pending_dependencies';
                elseif($state==='unknown') $reasons[]='critical_constraint_unknown';
            }
            foreach($row['claims'] as $claim){
                if(isset($activeClaims[$claim]) && $activeClaims[$claim]!==$id) $reasons[]='claim_conflict';
            }
            $project=$concurrency[$item['project_id']];
            if($project['state']==='unknown') $reasons[]='critical_constraint_unknown';
            elseif($project['active'] >= $project['limit']) $reasons[]='project_concurrency_exhausted';
            $row['reasons']=array_values(array_unique($reasons)); sort($row['reasons']);
        }
        unset($row);

        $eligible=array_filter($work,static fn(array $row):bool=>$row['reasons']===[]);
        $readyCount=count($eligible);
        $claimBound=self::claimLaneBound($eligible);
        $projectCounts=[];
        foreach($eligible as $row){
            $project=$row['item']['project_id'];
            $projectCounts[$project]=($projectCounts[$project]??0)+1;
        }
        $concurrencyBound=0;
        foreach($projectCounts as $project=>$count){
            $rule=$concurrency[$project];
            $remaining=max(0,$rule['limit']-$rule['active']);
            $concurrencyBound+=min($count,$remaining);
        }

        $jointBound=self::jointLaneProjectBound($eligible,$concurrency);
        $idle=$presence['idle_capacity'];
        $dispatchable=min($idle,$readyCount,$jointBound);
        $reasons=[];
        if($idle===0) $reasons[]='authoritative_capacity_unavailable';
        if($readyCount<count($work)) $reasons[]='work_constraints';
        if($claimBound<$readyCount) $reasons[]='claim_contention';
        if($concurrencyBound<$readyCount) $reasons[]='project_concurrency';
        foreach($work as $row) if(in_array('critical_constraint_unknown',$row['reasons'],true)) {$reasons[]='critical_constraint_unknown';break;}
        $reasons=array_values(array_unique($reasons)); sort($reasons);

        $views=[];
        foreach($work as $id=>$row){
            $views[]=['work_item_id'=>$id,'eligible'=>$row['reasons']===[],'reasons'=>$row['reasons']];
        }
        usort($views,static fn(array $a,array $b):int=>$a['work_item_id']<=>$b['work_item_id']);

        return [
            'version'=>1,'policy_ref'=>'factory-dispatcher-v2',
            'authoritative_idle_capacity'=>$idle,'dispatchable_capacity'=>$dispatchable,
            'ready_work_items'=>$readyCount,'claim_lanes'=>$claimBound,'concurrency_slots'=>$concurrencyBound,
            'joint_constraint_slots'=>$jointBound,
            'reasons'=>$reasons,'work_items'=>$views,
        ];
    }

    public static function candidate(array $workRaw,array $context): array
    {
        $work=self::workItem($workRaw);
        $ready=self::readiness($work,$context);
        return [
            'version'=>1,'policy_ref'=>'factory-dispatcher-v2','key'=>$work['work_item_id'],
            'source_ref'=>$work['source_ref'],'priority'=>$work['priority'],'generation'=>$work['generation'],
            'required_capabilities'=>$work['required_capabilities'],'account_id'=>$ready['account_id'],
            'readiness'=>[
                'ready'=>$ready['ready'],'reasons'=>$ready['reasons'],
                'open_dependencies'=>$ready['open_dependencies'],'unknown_dependencies'=>$ready['unknown_dependencies'],
            ],
        ];
    }

    private static function dependencyStates(mixed $raw,array $ids): array
    {
        if(!is_array($raw)||($raw!==[] && array_is_list($raw))) throw new InvalidArgumentException('dependency_states invalid.');
        $keys=array_keys($raw); sort($keys);
        if($keys!==$ids) throw new InvalidArgumentException('dependency_states mismatch.');
        $out=[];
        foreach($ids as $id) $out[$id]=self::enum($raw[$id],self::DEPENDENCY,'dependency_state');
        return $out;
    }

    private static function reservations(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>8) throw new InvalidArgumentException('reservations invalid.');
        $out=[]; $seen=[];
        foreach($rows as $row){
            self::fields($row,['reservation_id','owner_session_id','generation','active'],'Reservation');
            if(!is_bool($row['active'])) throw new InvalidArgumentException('reservation.active invalid.');
            $id=self::ref($row['reservation_id'],'reservation_id');
            if(isset($seen[$id])) throw new InvalidArgumentException('Reservation duplicated.');
            $seen[$id]=true;
            $out[]=[
                'reservation_id'=>$id,'owner_session_id'=>self::ref($row['owner_session_id'],'owner_session_id'),
                'generation'=>self::positiveInt($row['generation'],'reservation.generation'),'active'=>$row['active'],
            ];
        }
        return $out;
    }

    private static function capacity(mixed $raw): array
    {
        self::fields($raw,['account_id','eligible','free_capacity','session_ids'],'Capacity');
        if(!is_bool($raw['eligible'])||!is_int($raw['free_capacity'])||$raw['free_capacity']<0
            || $raw['eligible']!==($raw['free_capacity']>0)) throw new InvalidArgumentException('Capacity invalid.');
        return [
            'account_id'=>self::ref($raw['account_id'],'account_id'),'eligible'=>$raw['eligible'],
            'free_capacity'=>$raw['free_capacity'],'session_ids'=>self::unique($raw['session_ids'],'capacity.session_ids','ref'),
        ];
    }

    private static function presenceCapacity(array $raw): array
    {
        self::fields($raw,[
            'version','policy_ref','observed_at','presence_state','capacity_state',
            'healthy_sessions','idle_capacity','sessions','accounts',
        ],'PresenceSnapshot');
        if($raw['version']!==1||$raw['policy_ref']!=='factory-dispatcher-v2'
            ||!is_int($raw['idle_capacity'])||$raw['idle_capacity']<0
            ||!in_array($raw['capacity_state'],['idle_capacity','saturated','degraded','unknown'],true)
            ||!is_array($raw['sessions'])||!array_is_list($raw['sessions'])
            ||!is_array($raw['accounts'])||!array_is_list($raw['accounts'])) {
            throw new InvalidArgumentException('Presence capacity invalid.');
        }
        if(($raw['capacity_state']==='idle_capacity')!==($raw['idle_capacity']>0))
            throw new InvalidArgumentException('Presence capacity state mismatch.');
        return ['idle_capacity'=>$raw['idle_capacity']];
    }

    private static function activeClaims(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>128) throw new InvalidArgumentException('active_claims invalid.');
        $out=[];
        foreach($rows as $row){
            self::fields($row,['claim','owner_work_item_id'],'ActiveClaim');
            $claim=self::resourceClaim($row['claim']);
            $owner=self::id($row['owner_work_item_id'],'owner_work_item_id');
            if(isset($out[$claim])) throw new InvalidArgumentException('Active claim duplicated.');
            $out[$claim]=$owner;
        }
        return $out;
    }

    private static function projectConcurrency(mixed $raw,array $projectIds): array
    {
        if(!is_array($raw)||($raw!==[]&&array_is_list($raw))) throw new InvalidArgumentException('project_concurrency invalid.');
        sort($projectIds); $keys=array_keys($raw); sort($keys);
        if($keys!==$projectIds) throw new InvalidArgumentException('project_concurrency mismatch.');
        $out=[];
        foreach($projectIds as $project){
            $row=$raw[$project]??null;
            self::fields($row,['state','limit','active'],'ProjectConcurrency');
            if(!is_string($row['state'])||!in_array($row['state'],['known','unknown'],true))
                throw new InvalidArgumentException('Project concurrency state invalid.');
            if($row['state']==='unknown'){
                if($row['limit']!==null||$row['active']!==null) throw new InvalidArgumentException('Unknown concurrency must not assert limits.');
                $out[$project]=['state'=>'unknown','limit'=>0,'active'=>0];
                continue;
            }
            if(!is_int($row['limit'])||$row['limit']<0||!is_int($row['active'])||$row['active']<0||$row['active']>$row['limit'])
                throw new InvalidArgumentException('Project concurrency invalid.');
            $out[$project]=['state'=>'known','limit'=>$row['limit'],'active'=>$row['active']];
        }
        return $out;
    }

    private static function resourceClaims(mixed $values): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>64) throw new InvalidArgumentException('claims invalid.');
        $out=[];
        foreach($values as $value){
            $claim=self::resourceClaim($value);
            if(in_array($claim,$out,true)) throw new InvalidArgumentException('claim duplicated.');
            $out[]=$claim;
        }
        sort($out); return $out;
    }

    private static function resourceClaim(mixed $value): string
    {
        if(!is_string($value)||strlen($value)<1||strlen($value)>200||str_starts_with($value,'/')
            ||preg_match('/^[A-Za-z0-9._\/-]+$/D',$value)!==1
            ||preg_match('/(?:^|\/)\.\.(?:\/|$)/',$value)===1
            ||preg_match('/(?:^|\/)(?:\.env|secrets?|tokens?|credentials?)(?:\.|\/|$)/i',$value)===1)
            throw new InvalidArgumentException('claim invalid.');
        return $value;
    }

    private static function claimLaneBound(array $eligible): int
    {
        return count(self::claimLanes($eligible));
    }

    private static function claimLanes(array $eligible): array
    {
        if($eligible===[]) return [];
        $parent=[]; $claimOwner=[];
        foreach($eligible as $id=>$row){
            $parent[$id]=$id;
            foreach($row['claims'] as $claim){
                if(isset($claimOwner[$claim])) self::union($parent,$id,$claimOwner[$claim]);
                else $claimOwner[$claim]=$id;
            }
        }

        $lanes=[];
        foreach($eligible as $id=>$row){
            $root=self::root($parent,$id);
            $project=$row['item']['project_id'];
            $lanes[$root][$project]=true;
        }
        ksort($lanes,SORT_STRING);
        $out=[];
        foreach($lanes as $projects){
            $ids=array_keys($projects);
            sort($ids,SORT_STRING);
            $out[]=$ids;
        }
        return $out;
    }

    private static function jointLaneProjectBound(array $eligible,array $concurrency): int
    {
        $lanes=self::claimLanes($eligible);
        if($lanes===[]) return 0;

        $slots=[];
        foreach($concurrency as $project=>$rule){
            $remaining=max(0,$rule['limit']-$rule['active']);
            $remaining=min($remaining,count($eligible));
            for($i=0;$i<$remaining;$i++) $slots[$project.'#'.$i]=$project;
        }
        ksort($slots,SORT_STRING);

        $owners=[]; $matched=0;
        foreach(array_keys($lanes) as $lane){
            $seen=[];
            if(self::matchLane($lane,$lanes,$slots,$owners,$seen)) $matched++;
        }
        return $matched;
    }

    private static function matchLane(int $lane,array $lanes,array $slots,array &$owners,array &$seen): bool
    {
        foreach($slots as $slot=>$project){
            if(!in_array($project,$lanes[$lane],true)||isset($seen[$slot])) continue;
            $seen[$slot]=true;
            if(!isset($owners[$slot])||self::matchLane($owners[$slot],$lanes,$slots,$owners,$seen)){
                $owners[$slot]=$lane;
                return true;
            }
        }
        return false;
    }

    private static function union(array &$parent,string $a,string $b): void
    {
        $ra=self::root($parent,$a); $rb=self::root($parent,$b);
        if($ra!==$rb) $parent[$rb]=$ra;
    }

    private static function root(array &$parent,string $id): string
    {
        while($parent[$id]!==$id){
            $parent[$id]=$parent[$parent[$id]];
            $id=$parent[$id];
        }
        return $id;
    }

    private static function unique(mixed $values,string $label,string $kind): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>64) throw new InvalidArgumentException($label.' invalid.');
        $out=[];
        foreach($values as $value){
            $normalized=match($kind){'id'=>self::id($value,$label),'slug'=>self::slug($value,$label),'ref'=>self::ref($value,$label)};
            if(in_array($normalized,$out,true)) throw new InvalidArgumentException($label.' duplicated.');
            $out[]=$normalized;
        }
        sort($out); return $out;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $keys=array_keys($row); sort($keys); sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function workRef(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)>180
            || preg_match('/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+#[1-9][0-9]*$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\\/#-]{0,179}$/D',$value)!==1
            || preg_match('/(?:(?:github_pat_|ghp_|gho_)[A-Za-z0-9_]{20,}|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,}|(?:token|secret|password|cookie|authorization|dsn):)/i',$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function id(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableRef(mixed $value,string $label): ?string { return $value===null?null:self::ref($value,$label); }
    private static function slug(mixed $value,string $label): string { return self::id($value,$label); }
    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
    private static function positiveInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

use InvalidArgumentException;

final class PresenceAdapter
{
    private const POLICY='factory-dispatcher-v2';
    private const OPERATIONS=['continue','recover','reassign','preempt'];

    public static function snapshot(
        array $accountsRaw,
        array $rows,
        int $now,
        int $staleAfter=90,
        int $offlineAfter=300,
        array $capacityObservations=[],
    ): array {
        if(!array_is_list($accountsRaw)||$accountsRaw===[]||count($accountsRaw)>16||!array_is_list($rows)||count($rows)>64||$now<0)
            throw new InvalidArgumentException('Presence input invalid.');

        $accounts=[]; $sessionsByAccount=[]; $unsafeByAccount=[];
        foreach($accountsRaw as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('Account invalid.');
            $account=AgentRuntime::account($raw); $id=self::opaque($account['account_id'],'account_id');
            if(isset($accounts[$id])) throw new InvalidArgumentException('Account duplicated.');
            $accounts[$id]=$account; $sessionsByAccount[$id]=[]; $unsafeByAccount[$id]=$rows===[];
        }
        foreach($capacityObservations as $accountId=>$observation){
            if(!is_string($accountId)||!isset($accounts[$accountId])||!is_array($observation))
                throw new InvalidArgumentException('Capacity observation invalid.');
        }

        $sessions=[]; $seen=[]; $presenceUnknown=$rows===[]; $capacityUnknown=$rows===[]; $degraded=false; $healthy=0; $idleByAccount=[];
        foreach($rows as $raw){
            self::fields($raw,['session','agent','assignment','claims','generation','attempt','safe_point','non_preemptible'],'PresenceRow');
            if(!is_array($raw['session'])||!is_array($raw['agent'])) throw new InvalidArgumentException('Presence row invalid.');
            $session=AgentRuntime::session($raw['session']); $agent=AgentRuntime::agent($raw['agent']);
            $sid=self::opaque($session['session_id'],'session_id'); $aid=self::opaque($session['account_id'],'account_id');
            if(isset($seen[$sid])||!isset($accounts[$aid])||$agent['agent_id']!==$session['agent_id'])
                throw new InvalidArgumentException('Presence identity mismatch.');
            $seen[$sid]=true; $sessionsByAccount[$aid][]=$session;

            $assignment=null;
            if($raw['assignment']!==null){
                if(!is_array($raw['assignment'])) throw new InvalidArgumentException('Assignment invalid.');
                $assignment=AgentRuntime::assignment($raw['assignment']);
                if($assignment['assignment_id']!==$session['assignment_id']||$assignment['session_id']!==$sid)
                    throw new InvalidArgumentException('Assignment mismatch.');
                if($session['repository']===null||$session['issue_number']===null
                    ||$assignment['source_ref']!==$session['repository'].'#'.$session['issue_number'])
                    throw new InvalidArgumentException('Assignment attribution mismatch.');
            } elseif($session['assignment_id']!==null) {
                throw new InvalidArgumentException('Assignment evidence missing.');
            }

            $health=AgentRuntime::sessionHealth($session,$now,$staleAfter,$offlineAfter);
            $heartbeat=$session['last_heartbeat_at'];
            $freshness=($heartbeat===null||$heartbeat>$now)?'unknown':$health['health'];
            if($freshness==='unknown'){
                $presenceUnknown=true; $capacityUnknown=true; $unsafeByAccount[$aid]=true;
            } elseif($freshness!=='healthy') {
                $degraded=true; $unsafeByAccount[$aid]=true;
            } else {
                $healthy++;
                if($session['status']==='idle') $idleByAccount[$aid]=($idleByAccount[$aid]??0)+1;
            }

            if(!is_bool($raw['safe_point'])||!is_bool($raw['non_preemptible']))
                throw new InvalidArgumentException('Preemption flags invalid.');
            $generation=self::positive($raw['generation'],'generation');
            $attempt=self::positive($raw['attempt'],'attempt');
            $sessions[]=[
                'session_id'=>$sid,'agent_id'=>self::opaque($agent['agent_id'],'agent_id'),
                'account_id'=>$aid,'project_id'=>$assignment===null?null:self::opaque($assignment['project_id'],'project_id'),
                'repository'=>$session['repository'],
                'work_item'=>$session['repository']===null?null:$session['repository'].'#'.$session['issue_number'],
                'issue_number'=>$session['issue_number'],'state'=>$session['status'],
                'assignment_id'=>$assignment===null?null:self::opaque($assignment['assignment_id'],'assignment_id'),
                'claims'=>self::claims($raw['claims']),'capabilities'=>$agent['capabilities'],
                'heartbeat_at'=>$heartbeat,'freshness'=>$freshness,'generation'=>$generation,'attempt'=>$attempt,
                'safe_point'=>$raw['safe_point'],'non_preemptible'=>$raw['non_preemptible'],
            ];
        }

        $accountViews=[]; $trustedFree=0; $trustedIdle=0;
        foreach($accounts as $id=>$account){
            $observation=$capacityObservations[$id]??[
                'version'=>1,'state'=>'unknown','total_capacity'=>0,'occupied_capacity'=>0,'observed_at'=>$now,
            ];
            $observedAt=$observation['observed_at']??null;
            if(!is_int($observedAt)||$observedAt<0||$observedAt>$now)
                throw new InvalidArgumentException('Capacity observation timestamp invalid.');
            if($now-$observedAt>$staleAfter) $observation=array_replace($observation,['state'=>'unknown']);

            $view=AgentRuntime::observedCapacitySnapshot($account,$sessionsByAccount[$id],$observation);
            $state=$view['observed_state'];
            if($state==='unknown') $capacityUnknown=true;
            elseif(in_array($state,['rate_limited','requires_login','offline'],true)) $degraded=true;
            if($account['status']!=='active') $degraded=true;

            $usable=$account['status']==='active'
                && !$unsafeByAccount[$id]
                && in_array($state,['healthy','saturated'],true);
            $free=$usable&&$view['eligible']?$view['free_capacity']:0;
            $idle=$usable?($idleByAccount[$id]??0):0;
            $trustedFree+=$free; $trustedIdle+=$idle;
            $accountViews[]=[
                'account_id'=>$id,'provider_id'=>$account['provider_id'],
                'eligible'=>($free+$idle)>0,'free_capacity'=>$free,'idle_sessions'=>$idle,
                'observed_state'=>$state,'observed_at'=>$view['observed_at'],
            ];
        }

        usort($sessions,static fn(array $a,array $b):int=>$a['session_id']<=>$b['session_id']);
        usort($accountViews,static fn(array $a,array $b):int=>$a['account_id']<=>$b['account_id']);
        $presence=$presenceUnknown||$healthy===0?'unknown':($healthy===1?'solo':'multi');
        $capacity=$capacityUnknown?'unknown':($degraded?'degraded':(($trustedIdle+$trustedFree)>0?'idle_capacity':'saturated'));

        return [
            'version'=>1,'policy_ref'=>self::POLICY,'observed_at'=>$now,
            'presence_state'=>$presence,'capacity_state'=>$capacity,
            'healthy_sessions'=>$healthy,'idle_capacity'=>$trustedIdle+$trustedFree,
            'sessions'=>$sessions,'accounts'=>$accountViews,
        ];
    }

    public static function transitionEvent(array $before,array $after,string $sessionId): ?array
    {
        $sid=self::opaque($sessionId,'session_id');
        $old=self::findSession($before,$sid); $new=self::findSession($after,$sid);
        if($old===null&&$new===null) return null;
        $type=null;
        if($old===null) $type='join';
        elseif($new===null) $type='leave';
        elseif($old['freshness']==='healthy'&&$new['freshness']!=='healthy') $type='stale';
        elseif($old['freshness']!=='healthy'&&$new['freshness']==='healthy') $type='recovery';
        elseif(self::material($old)!==self::material($new)) $type='change';
        if($type===null) return null;
        $basis=[
            'type'=>$type,'session_id'=>$sid,
            'before'=>$old===null?null:self::material($old),
            'after'=>$new===null?null:self::material($new),
        ];
        return [
            'version'=>1,'type'=>$type,'session_id'=>$sid,'policy_ref'=>self::POLICY,
            'fingerprint'=>hash('sha256',json_encode($basis,JSON_THROW_ON_ERROR)),'recompute'=>true,
        ];
    }

    public static function replanGuard(array $snapshot,string $sessionId,int $expectedGeneration,string $operation): array
    {
        if(!in_array($operation,self::OPERATIONS,true)) throw new InvalidArgumentException('Operation invalid.');
        $expected=self::positive($expectedGeneration,'expected_generation');
        $row=self::findSession($snapshot,self::opaque($sessionId,'session_id')); $reasons=[];
        if($row===null) $reasons[]='session_unknown';
        else {
            if($row['generation']!==$expected) $reasons[]='stale_generation';
            if($operation==='recover'&&$row['freshness']!=='healthy') $reasons[]='session_not_healthy';
            if(in_array($operation,['reassign','preempt'],true)&&$row['non_preemptible']&&!$row['safe_point'])
                $reasons[]='non_preemptible_outside_safe_point';
        }
        sort($reasons);
        return [
            'version'=>1,'policy_ref'=>self::POLICY,'operation'=>$operation,'recompute'=>true,
            'allowed'=>$reasons===[],'reasons'=>$reasons,
            'assignment_id'=>$row['assignment_id']??null,'generation'=>$row['generation']??null,
        ];
    }

    private static function material(array $row): array
    {
        return [
            'freshness'=>$row['freshness'],'state'=>$row['state'],'assignment_id'=>$row['assignment_id'],
            'claims'=>$row['claims'],'capabilities'=>$row['capabilities'],'generation'=>$row['generation'],
            'attempt'=>$row['attempt'],'safe_point'=>$row['safe_point'],'non_preemptible'=>$row['non_preemptible'],
        ];
    }

    private static function findSession(array $snapshot,string $sid): ?array
    {
        self::fields($snapshot,['version','policy_ref','observed_at','presence_state','capacity_state','healthy_sessions','idle_capacity','sessions','accounts'],'PresenceSnapshot');
        if($snapshot['version']!==1||$snapshot['policy_ref']!==self::POLICY||!is_array($snapshot['sessions'])||!array_is_list($snapshot['sessions']))
            throw new InvalidArgumentException('Presence snapshot invalid.');
        foreach($snapshot['sessions'] as $row){
            if(!is_array($row)||!isset($row['session_id'])||!is_string($row['session_id'])) throw new InvalidArgumentException('Presence session invalid.');
            if(hash_equals($row['session_id'],$sid)) return $row;
        }
        return null;
    }

    private static function claims(mixed $values): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>64) throw new InvalidArgumentException('claims invalid.');
        $out=[];
        foreach($values as $value){
            if(!is_string($value)||strlen($value)<1||strlen($value)>200||str_starts_with($value,'/')
                ||preg_match('/^[A-Za-z0-9._\/-]+$/D',$value)!==1||preg_match('/(?:^|\/)\.\.(?:\/|$)/',$value)===1
                ||preg_match('/(?:^|\/)(?:\.env|secrets?|tokens?|credentials?)(?:\.|\/|$)/i',$value)===1)
                throw new InvalidArgumentException('claim invalid.');
            if(in_array($value,$out,true)) throw new InvalidArgumentException('claim duplicated.');
            $out[]=$value;
        }
        sort($out); return $out;
    }

    private static function opaque(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<1||strlen($value)>180||str_contains($value,'@')
            ||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/#-]*$/D',$value)!==1
            ||preg_match('/(?:token|secret|password|cookie|authorization|dsn):/i',$value)===1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function positive(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $keys=array_keys($row); sort($keys); sort($expected);
        if($keys!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Observability;

use InvalidArgumentException;

final class ExternalMonitorCore
{
    private const FRESHNESS=['fresh','stale','unknown'];
    private const PROBE_OUTCOMES=['response','timeout','network_error','unknown'];
    private const WORKFLOW_CONCLUSIONS=['success','failure','startup_failure','cancelled','skipped','unknown'];
    private const BUDGET_STATES=['normal','warning','critical','exhausted','blocked','unknown'];
    private const SENSITIVE='/(?:bearer\\s+|password|passwd|token\\s*[:=]|secret\\s*[:=]|cookie\\s*[:=]|authorization\\s*[:=]|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function probe(array $raw): array
    {
        self::fields($raw,['version','endpoint_kind','observed_at','outcome','http_status','latency_ms','reported_version','reported_sha','freshness','source_ref'],'ProbeObservation');
        if($raw['version']!==1) throw new InvalidArgumentException('ProbeObservation version invalid.');
        $outcome=self::choice($raw['outcome'],self::PROBE_OUTCOMES,'outcome');
        $status=self::nullableInt($raw['http_status'],'http_status',100,599);
        $latency=self::nullableInt($raw['latency_ms'],'latency_ms',0,120000);
        if($outcome==='response' && $status===null) throw new InvalidArgumentException('Response requires http_status.');
        if($outcome!=='response' && $status!==null) throw new InvalidArgumentException('Non-response cannot assert http_status.');
        if($outcome==='unknown' && $latency!==null) throw new InvalidArgumentException('Unknown probe cannot assert latency.');
        return [
            'version'=>1,'endpoint_kind'=>self::choice($raw['endpoint_kind'],['health','home'],'endpoint_kind'),
            'observed_at'=>self::positiveInt($raw['observed_at'],'observed_at'),'outcome'=>$outcome,'http_status'=>$status,
            'latency_ms'=>$latency,'reported_version'=>self::nullableVersion($raw['reported_version']),
            'reported_sha'=>self::nullableSha($raw['reported_sha']),'freshness'=>self::choice($raw['freshness'],self::FRESHNESS,'freshness'),
            'source_ref'=>self::ref($raw['source_ref'],'source_ref'),
        ];
    }

    public static function workflow(array $raw): array
    {
        self::fields($raw,['version','visibility','observed_at','conclusion','runner_assigned','steps_present','sha','source_ref'],'WorkflowObservation');
        if($raw['version']!==1) throw new InvalidArgumentException('WorkflowObservation version invalid.');
        $conclusion=self::choice($raw['conclusion'],self::WORKFLOW_CONCLUSIONS,'conclusion');
        $runner=self::nullableBool($raw['runner_assigned'],'runner_assigned');
        $steps=self::nullableBool($raw['steps_present'],'steps_present');
        if(in_array($conclusion,['success','failure'],true) && ($runner!==true || $steps!==true))
            throw new InvalidArgumentException('Completed workflow requires runner and steps.');
        if($conclusion==='startup_failure' && ($runner!==false || !in_array($steps,[false,null],true)))
            throw new InvalidArgumentException('startup_failure evidence invalid.');
        if($conclusion==='unknown' && ($runner!==null || $steps!==null))
            throw new InvalidArgumentException('Unknown workflow cannot assert runner or steps.');
        return [
            'version'=>1,'visibility'=>self::choice($raw['visibility'],['private','public'],'visibility'),
            'observed_at'=>self::positiveInt($raw['observed_at'],'observed_at'),'conclusion'=>$conclusion,
            'runner_assigned'=>$runner,'steps_present'=>$steps,'sha'=>self::sha($raw['sha'],'sha'),
            'source_ref'=>self::ref($raw['source_ref'],'source_ref'),
        ];
    }

    public static function assess(?array $probeRaw,array $workflowRows,?string $budgetState,int $now,int $probeTtl): array
    {
        if($now<0 || $probeTtl<1 || $probeTtl>86400) throw new InvalidArgumentException('Monitor clock or TTL invalid.');
        $probe=$probeRaw===null?null:self::probe($probeRaw);
        if($probe!==null && $probe['observed_at']>$now) throw new InvalidArgumentException('Probe timestamp is in the future.');
        if(!array_is_list($workflowRows)||count($workflowRows)>32) throw new InvalidArgumentException('Workflow observations invalid.');
        $workflows=[];$seen=[];
        foreach($workflowRows as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('Workflow observation invalid.');
            $row=self::workflow($raw);
            if($row['observed_at']>$now) throw new InvalidArgumentException('Workflow timestamp is in the future.');
            $id=$row['visibility'].'|'.$row['observed_at'].'|'.$row['sha'].'|'.$row['source_ref'];
            if(isset($seen[$id])) throw new InvalidArgumentException('Workflow observation duplicated.');
            $seen[$id]=true;$workflows[]=$row;
        }
        usort($workflows,static fn(array $a,array $b):int=>
            [$a['observed_at'],$a['visibility'],$a['sha'],$a['conclusion'],$a['source_ref']]
            <=>
            [$b['observed_at'],$b['visibility'],$b['sha'],$b['conclusion'],$b['source_ref']]
        );
        if($budgetState!==null) self::choice($budgetState,self::BUDGET_STATES,'budget_state');

        [$application,$applicationReason]=self::applicationState($probe,$now,$probeTtl);
        $private=self::latest($workflows,'private');$public=self::latest($workflows,'public');
        $workflowState=self::workflowState($private);
        $capacity=match($budgetState){'normal'=>'available','warning','critical'=>'degraded','exhausted','blocked'=>'exhausted',default=>'unknown'};
        $reasons=[$applicationReason];
        if($private!==null){
            if($private['conclusion']==='startup_failure') $reasons[]='private_startup_failure_without_runner';
            elseif($private['conclusion']==='failure') $reasons[]='private_workflow_step_failure';
        }
        if($public!==null && $public['conclusion']==='success') $reasons[]='public_runner_available';
        if($budgetState==='critical') $reasons[]='owner_capacity_critical';
        if($capacity==='exhausted') $reasons[]='owner_capacity_exhausted';
        if($workflowState==='blocked' && $capacity==='exhausted' && self::earlierPrivateSuccess($workflows,$private)
            && $public!==null && $public['conclusion']==='success') $reasons[]='capacity_evidence_converges';
        $reasons[]='billing_mechanism_unknown';
        $reasons=array_values(array_unique(array_filter($reasons)));sort($reasons,SORT_STRING);

        $refs=[];foreach([$probe,$private,$public] as $row) if(is_array($row)) $refs[]=$row['source_ref'];
        $refs=array_values(array_unique($refs));sort($refs,SORT_STRING);
        $alert=self::alertIntent($application,$capacity,$budgetState,$refs);
        $out=[
            'version'=>1,'application_state'=>$application,'private_workflow_state'=>$workflowState,
            'owner_capacity_state'=>$capacity,'billing_mechanism_state'=>'unknown','reasons'=>$reasons,
            'alert_intent'=>$alert,'probe'=>$probe,'latest_private_workflow'=>$private,'latest_public_workflow'=>$public,
        ];
        self::secretFree($out);return $out;
    }

    private static function applicationState(?array $probe,int $now,int $ttl): array
    {
        if($probe===null) return ['unknown','external_probe_missing'];
        if($probe['freshness']==='unknown') return ['unknown','external_probe_freshness_unknown'];
        if($probe['freshness']==='stale'||$now-$probe['observed_at']>$ttl) return ['degraded','external_probe_stale'];
        if($probe['outcome']==='unknown') return ['unknown','external_probe_unknown'];
        if(in_array($probe['outcome'],['timeout','network_error'],true)) return ['down','external_probe_unreachable'];
        $status=$probe['http_status'];
        if($status>=200 && $status<300) return ['healthy','external_probe_healthy'];
        if($status>=500) return ['down','external_probe_server_error'];
        return ['degraded','external_probe_non_2xx'];
    }

    private static function workflowState(?array $row): string
    {
        if($row===null || $row['conclusion']==='unknown') return 'unknown';
        return match($row['conclusion']){'success'=>'healthy','startup_failure'=>'blocked',default=>'degraded'};
    }

    private static function latest(array $rows,string $visibility): ?array
    {
        $latest=null;foreach($rows as $row) if($row['visibility']===$visibility) $latest=$row;return $latest;
    }

    private static function earlierPrivateSuccess(array $rows,?array $latest): bool
    {
        if($latest===null) return false;
        foreach($rows as $row) if($row['visibility']==='private' && $row['conclusion']==='success'
            && $row['sha']===$latest['sha'] && $row['observed_at']<$latest['observed_at']) return true;
        return false;
    }

    private static function alertIntent(string $application,string $capacity,?string $budgetState,array $refs): array
    {
        $required=false;$severity='info';$code='none';
        if($application==='down'){$required=true;$severity='critical';$code='application_down';}
        elseif($capacity==='exhausted'){$required=true;$severity='critical';$code='private_actions_capacity_exhausted';}
        elseif($budgetState==='critical'){$required=true;$severity='critical';$code='private_actions_capacity_critical';}
        elseif($application==='degraded'){$required=true;$severity='warning';$code='application_degraded';}
        return ['required'=>$required,'severity'=>$severity,'code'=>$code,'external_channel_required'=>$required,'evidence_refs'=>$refs];
    }

    private static function fields(array $raw,array $expected,string $label): void
    {
        if(array_is_list($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
    private static function choice(mixed $value,array $allowed,string $label): string
    {if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function positiveInt(mixed $value,string $label): int
    {if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function nullableInt(mixed $value,string $label,int $min,int $max): ?int
    {if($value===null)return null;if(!is_int($value)||$value<$min||$value>$max)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function nullableBool(mixed $value,string $label): ?bool
    {if($value===null)return null;if(!is_bool($value))throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function sha(mixed $value,string $label): string
    {if(!is_string($value)||preg_match('/^[0-9a-f]{40}$/D',$value)!==1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function nullableSha(mixed $value): ?string{return $value===null?null:self::sha($value,'reported_sha');}
    private static function nullableVersion(mixed $value): ?string
    {if($value===null)return null;if(!is_string($value)||preg_match('/^\\d+\\.\\d+\\.\\d+(?:-[0-9A-Za-z.-]+)?$/D',$value)!==1)throw new InvalidArgumentException('reported_version invalid.');return $value;}
    private static function ref(mixed $value,string $label): string
    {if(!is_string($value)||strlen($value)<1||strlen($value)>180||str_contains($value,'@')||preg_match(self::SENSITIVE,$value)===1||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\\/#-]{0,179}$/D',$value)!==1)throw new InvalidArgumentException($label.' invalid.');return $value;}
    private static function secretFree(mixed $value): void
    {if(is_array($value)){foreach($value as $item)self::secretFree($item);return;}if(is_string($value)&&(str_contains($value,'@')||preg_match(self::SENSITIVE,$value)===1))throw new InvalidArgumentException('Monitor output contains sensitive material.');}
}

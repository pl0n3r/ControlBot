<?php
declare(strict_types=1);

namespace ControlBot\Vendors;

use InvalidArgumentException;

final class VendorExceptionSignal
{
    private const MAX_HORIZON_SECONDS=31536000;

    public static function project(array $vendorRaw,int $now,int $horizonSeconds): array
    {
        if($now<1) throw new InvalidArgumentException('now invalid.');
        if($horizonSeconds<0||$horizonSeconds>self::MAX_HORIZON_SECONDS)
            throw new InvalidArgumentException('horizon_seconds invalid.');

        $vendor=VendorRegistry::normalize($vendorRaw);
        $signals=[];
        $horizonEnd=$now+$horizonSeconds;
        if($horizonEnd<$now) throw new InvalidArgumentException('horizon overflow.');

        if($vendor['renewal_at']!==null && $vendor['renewal_at']<$horizonEnd)
            $signals[]=self::signal('renewal_due',$vendor,$vendor['renewal_at']);

        if($vendor['expiry_at']!==null && $vendor['expiry_at']<$horizonEnd)
            $signals[]=self::signal('expiry_due',$vendor,$vendor['expiry_at']);

        if($vendor['health']==='degraded' || $vendor['sla_state']==='degraded')
            $signals[]=self::signal('health_degraded',$vendor,null);

        if($vendor['criticality']==='critical' && (
            $vendor['health']==='unknown'
            ||$vendor['sla_state']==='unknown'
            ||$vendor['freshness']['state']!=='fresh'
        )) $signals[]=self::signal('critical_unknown',$vendor,null);

        if($vendor['security_review']['state']==='rejected')
            $signals[]=self::signal('security_review_rejected',$vendor,null);

        if($vendor['legal_review']['state']==='rejected')
            $signals[]=self::signal('legal_review_rejected',$vendor,null);

        if(self::exitMaterial($vendor) && $vendor['exit_plan_ref']===null)
            $signals[]=self::signal('missing_exit_plan',$vendor,null);

        if(self::exitMaterial($vendor) && $vendor['export_ref']===null)
            $signals[]=self::signal('missing_export_capability',$vendor,null);

        $unique=[];
        foreach($signals as $signal){
            $key=$signal['type'].'|'.$signal['vendor_id'];
            $unique[$key]=$signal;
        }
        $signals=array_values($unique);
        usort($signals,static fn(array $a,array $b): int =>
            [$a['type'],$a['vendor_id']] <=> [$b['type'],$b['vendor_id']]
        );
        return $signals;
    }

    private static function exitMaterial(array $vendor): bool
    {
        return in_array($vendor['criticality'],['high','critical'],true)
            && in_array($vendor['lifecycle'],['active','changing','offboarding'],true);
    }

    private static function signal(string $type,array $vendor,?int $deadlineAt): array
    {
        $fresh=$vendor['freshness'];
        return [
            'version'=>1,
            'signal_ref'=>'vendor-exception:'.hash('sha256',$vendor['vendor_id'].'|'.$type),
            'type'=>$type,
            'vendor_id'=>$vendor['vendor_id'],
            'venture_id'=>$vendor['venture_id'],
            'criticality'=>$vendor['criticality'],
            'lifecycle'=>$vendor['lifecycle'],
            'health'=>$vendor['health'],
            'sla_state'=>$vendor['sla_state'],
            'freshness'=>$fresh['state'],
            'observed_at'=>$fresh['observed_at'],
            'source_ref'=>$fresh['source_ref'],
            'renewal_at'=>$vendor['renewal_at'],
            'expiry_at'=>$vendor['expiry_at'],
            'deadline_at'=>$deadlineAt,
            'evidence_refs'=>self::evidence($vendor),
        ];
    }

    private static function evidence(array $vendor): array
    {
        $refs=[
            $vendor['vendor_id'],
            $vendor['service_ref'],
            $vendor['cost']['capital_ref'],
            $vendor['contract_ref'],
            $vendor['data_ref'],
            $vendor['security_review']['aegis_review_ref'],
            $vendor['legal_review']['lex_review_ref'],
            $vendor['exit_plan_ref'],
            $vendor['export_ref'],
            $vendor['freshness']['source_ref'],
        ];
        $refs=array_values(array_unique(array_filter(
            $refs,
            static fn(mixed $ref): bool=>is_string($ref)&&$ref!==''
        )));
        sort($refs,SORT_STRING);
        return $refs;
    }
}

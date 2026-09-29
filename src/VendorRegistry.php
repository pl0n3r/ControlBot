<?php
declare(strict_types=1);

namespace ControlBot\Vendors;

use InvalidArgumentException;

final class VendorRegistry
{
    private const LIFECYCLE=['evaluating','approved','active','changing','offboarding','exited'];
    private const HEALTH=['healthy','degraded','unknown'];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const REVIEW=['approved','pending','rejected','unknown'];
    private const CADENCE=['monthly','quarterly','annual','usage','one_time'];
    private const CRITICALITY=['low','medium','high','critical'];

    public static function normalize(array $raw): array
    {
        self::fields($raw,[
            'version','vendor_id','venture_id','category','service_ref','owner_ref','lifecycle',
            'cost','contract_ref','data_ref','subprocessor_refs','credentials_ref','criticality',
            'exit_plan_ref','export_ref','sla_state','security_review','legal_review',
            'renewal_at','expiry_at','health','freshness','offboarding',
        ],'VendorRecord');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('VendorRecord version invalid.');

        $venture=self::id($raw['venture_id'],'venture_id');
        $renewal=self::nullableTimestamp($raw['renewal_at'],'renewal_at');
        $expiry=self::nullableTimestamp($raw['expiry_at'],'expiry_at');
        if($renewal!==null&&$expiry!==null&&$renewal>$expiry)
            throw new InvalidArgumentException('Vendor dates invalid.');

        $health=self::enumValue($raw['health'],self::HEALTH,'health');
        $fresh=self::freshness($raw['freshness']);
        if($fresh['state']!=='fresh'&&$health==='healthy')
            throw new InvalidArgumentException('Stale or unknown vendor cannot be healthy.');

        return [
            'version'=>1,
            'vendor_id'=>self::opaque($raw['vendor_id'],'vendor_id','vendor'),
            'venture_id'=>$venture,
            'category'=>self::slug($raw['category'],'category'),
            'service_ref'=>self::opaque($raw['service_ref'],'service_ref','service'),
            'owner_ref'=>self::opaque($raw['owner_ref'],'owner_ref','identity'),
            'lifecycle'=>self::enumValue($raw['lifecycle'],self::LIFECYCLE,'lifecycle'),
            'cost'=>self::cost($raw['cost'],$venture),
            'contract_ref'=>self::nullableOpaque($raw['contract_ref'],'contract_ref','contract'),
            'data_ref'=>self::nullableOpaque($raw['data_ref'],'data_ref','data'),
            'subprocessor_refs'=>self::opaqueList($raw['subprocessor_refs'],'subprocessor_refs','subprocessor',100),
            'credentials_ref'=>self::nullableOpaque($raw['credentials_ref'],'credentials_ref','credential'),
            'criticality'=>self::enumValue($raw['criticality'],self::CRITICALITY,'criticality'),
            'exit_plan_ref'=>self::nullableOpaque($raw['exit_plan_ref'],'exit_plan_ref','exit'),
            'export_ref'=>self::nullableOpaque($raw['export_ref'],'export_ref','export'),
            'sla_state'=>self::enumValue($raw['sla_state'],self::HEALTH,'sla_state'),
            'security_review'=>self::review($raw['security_review'],$venture,'aegis_review_ref','aegis'),
            'legal_review'=>self::review($raw['legal_review'],$venture,'lex_review_ref','lex'),
            'renewal_at'=>$renewal,
            'expiry_at'=>$expiry,
            'health'=>$health,
            'freshness'=>$fresh,
            'offboarding'=>self::offboarding($raw['offboarding']),
        ];
    }

    private static function cost(mixed $raw,string $venture): array
    {
        self::fields($raw,['venture_id','amount','currency','billing_cadence','capital_ref'],'VendorCost');
        if(self::id($raw['venture_id'],'cost.venture_id')!==$venture)
            throw new InvalidArgumentException('Vendor cost scope mismatch.');
        if((!is_int($raw['amount'])&&!is_float($raw['amount']))||!is_finite((float)$raw['amount'])||$raw['amount']<0)
            throw new InvalidArgumentException('Vendor cost amount invalid.');
        if(!is_string($raw['currency'])||preg_match('/^[A-Z]{3}$/D',$raw['currency'])!==1)
            throw new InvalidArgumentException('Vendor currency invalid.');
        return [
            'venture_id'=>$venture,'amount'=>(float)$raw['amount'],'currency'=>$raw['currency'],
            'billing_cadence'=>self::enumValue($raw['billing_cadence'],self::CADENCE,'billing_cadence'),
            'capital_ref'=>self::opaque($raw['capital_ref'],'capital_ref','capital'),
        ];
    }

    private static function review(mixed $raw,string $venture,string $refField,string $namespace): array
    {
        self::fields($raw,['venture_id','state',$refField],'VendorReview');
        if(self::id($raw['venture_id'],'review.venture_id')!==$venture)
            throw new InvalidArgumentException('Vendor review scope mismatch.');
        $state=self::enumValue($raw['state'],self::REVIEW,'review.state');
        $ref=self::nullableOpaque($raw[$refField],$refField,$namespace);
        if($state!=='unknown'&&$ref===null) throw new InvalidArgumentException('Vendor review evidence required.');
        return ['venture_id'=>$venture,'state'=>$state,$refField=>$ref];
    }

    private static function freshness(mixed $raw): array
    {
        self::fields($raw,['state','observed_at','source_ref'],'VendorFreshness');
        $state=self::enumValue($raw['state'],self::FRESHNESS,'freshness.state');
        if($state==='unknown'){
            if($raw['observed_at']!==null||$raw['source_ref']!==null)
                throw new InvalidArgumentException('Unknown freshness must not invent provenance.');
            return ['state'=>'unknown','observed_at'=>null,'source_ref'=>null];
        }
        return [
            'state'=>$state,
            'observed_at'=>self::timestamp($raw['observed_at'],'freshness.observed_at'),
            'source_ref'=>self::opaque($raw['source_ref'],'freshness.source_ref','evidence'),
        ];
    }

    private static function offboarding(mixed $raw): array
    {
        self::fields($raw,['export_ref','revoke_ref','retention_ref','continuity_ref','evidence_ref'],'Offboarding');
        $out=[];
        foreach([
            'export_ref'=>'export','revoke_ref'=>'revoke','retention_ref'=>'retention',
            'continuity_ref'=>'continuity','evidence_ref'=>'evidence',
        ] as $field=>$namespace) $out[$field]=self::nullableOpaque($raw[$field],$field,$namespace);
        return $out;
    }

    private static function opaqueList(mixed $values,string $label,string $namespace,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||count($values)>$max)
            throw new InvalidArgumentException($label.' invalid.');
        $set=[];
        foreach($values as $value){
            $ref=self::opaque($value,$label,$namespace);
            if(isset($set[$ref])) throw new InvalidArgumentException($label.' duplicated.');
            $set[$ref]=true;
        }
        $out=array_keys($set); sort($out,SORT_STRING); return $out;
    }

    private static function opaque(mixed $value,string $label,string $namespace): string
    {
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableOpaque(mixed $value,string $label,string $namespace): ?string
    {
        return $value===null?null:self::opaque($value,$label,$namespace);
    }

    private static function id(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{0,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function enumValue(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function timestamp(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableTimestamp(mixed $value,string $label): ?int
    {
        return $value===null?null:self::timestamp($value,$label);
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_fill_keys(array_keys($row),true);
        $wanted=array_fill_keys($expected,true);
        if(count($actual)!==count($wanted)||$actual!=$wanted)
            throw new InvalidArgumentException($label.' fields invalid.');
    }
}

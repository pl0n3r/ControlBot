<?php
declare(strict_types=1);

namespace ControlBot\Vendors;

use InvalidArgumentException;

final class VendorWorkOrigin
{
    private const REQUIRED=[
        'version','work_id','group_id','work_type','requested_capabilities','required_roles',
        'authority_level','priority_class','depends_on','claims','policy_ref',
    ];
    private const OPTIONAL=['project_id','repository_ref','budget_ref','approval_ref'];
    private const WORK_TYPES=[
        'engineering','security','infrastructure','operations','data_analytics','product',
        'content','marketing_growth','sales_support','finance_analysis','compliance_review',
        'knowledge_documentation',
    ];

    public static function fromRisk(array $intent,array $vendorRaw): array
    {
        return self::emit('risk', $intent, $vendorRaw);
    }

    public static function fromLifecycle(array $intent,array $vendorRaw): array
    {
        return self::emit('lifecycle', $intent, $vendorRaw);
    }

    private static function emit(string $kind,array $intent,array $vendorRaw): array
    {
        $vendor=VendorRegistry::normalize($vendorRaw);
        if($kind==='lifecycle' && !in_array($vendor['lifecycle'],['changing','offboarding','exited'],true))
            throw new InvalidArgumentException('Vendor lifecycle does not require lifecycle work.');

        $intent=self::normalizeIntent($intent);
        $fresh=$vendor['freshness'];
        if($fresh['observed_at']===null || $fresh['source_ref']===null)
            throw new InvalidArgumentException('Vendor work origin requires observed freshness.');

        $item=[
            'work_id'=>$intent['work_id'],'origin_mode'=>'automatic','origin_system'=>'controlbot',
            'group_id'=>$intent['group_id'],'venture_id'=>$vendor['venture_id'],
            'work_type'=>$intent['work_type'],'requested_capabilities'=>$intent['requested_capabilities'],
            'required_roles'=>$intent['required_roles'],'authority_level'=>$intent['authority_level'],
            'producer_ref'=>'controlbot:vendor-governance','priority_class'=>$intent['priority_class'],
            'depends_on'=>$intent['depends_on'],'claims'=>$intent['claims'],'policy_ref'=>$intent['policy_ref'],
            'evidence_refs'=>self::evidence($vendor,$kind),
            'idempotency_key'=>'vendor-work:'.hash('sha256',$vendor['vendor_id'].'|'.$kind.'|'.$intent['work_type']),
            'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$fresh['observed_at']),
        ];
        foreach(self::OPTIONAL as $field){
            if(($intent[$field]??null)!==null) $item[$field]=$intent[$field];
        }
        return $item;
    }

    private static function normalizeIntent(array $raw): array
    {
        $keys=array_keys($raw);
        $allowed=[...self::REQUIRED,...self::OPTIONAL];
        if(array_diff(self::REQUIRED,$keys)!==[] || array_diff($keys,$allowed)!==[] || ($raw['version']??null)!==1)
            throw new InvalidArgumentException('Vendor work origin intent fields invalid.');

        $out=['version'=>1];
        foreach($raw as $field=>$value){
            if($field==='version') continue;
            $out[$field]=match($field){
                'requested_capabilities','required_roles'=>self::listOf($value,$field,true,false),
                'depends_on','claims'=>self::listOf($value,$field,false,true),
                'work_type'=>self::catalog($value,$field,self::WORK_TYPES),
                'priority_class'=>self::catalog($value,$field,['critical','high','medium']),
                'authority_level'=>self::slug($value,$field),
                'repository_ref'=>self::repository($value),
                default=>self::text($value,$field),
            };
        }
        return $out;
    }

    private static function evidence(array $vendor,string $kind): array
    {
        $refs=[
            $vendor['vendor_id'],$vendor['service_ref'],$vendor['freshness']['source_ref'],
            $vendor['cost']['capital_ref'],$vendor['security_review']['aegis_review_ref'],
            $vendor['legal_review']['lex_review_ref'],
        ];
        if($kind==='lifecycle'){
            $refs=[...$refs,$vendor['exit_plan_ref'],$vendor['export_ref'],...array_values($vendor['offboarding'])];
        }else{
            $refs=[...$refs,$vendor['contract_ref'],$vendor['data_ref']];
        }
        $refs=array_filter($refs,static fn($ref):bool=>is_string($ref)&&$ref!=='');
        $refs=array_values(array_unique($refs));
        sort($refs,SORT_STRING);
        return $refs;
    }

    private static function listOf(mixed $value,string $field,bool $slugged,bool $allowEmpty): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>50||(!$allowEmpty && $value===[]))
            throw new InvalidArgumentException($field.' invalid.');
        $normalize=$slugged ? self::slug(...) : self::text(...);
        $items=[];
        foreach($value as $entry) $items[]=$normalize($entry,$field.'[]');
        $items=array_values(array_unique($items));
        sort($items,SORT_STRING);
        return $items;
    }

    private static function catalog(mixed $value,string $field,array $allowed): string
    {
        $value=self::slug($value,$field);
        if(!in_array($value,$allowed,true)) throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function repository(mixed $value): ?string
    {
        if($value===null) return null;
        $value=self::text($value,'repository_ref');
        if(preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$value)!==1)
            throw new InvalidArgumentException('repository_ref invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $field): string
    {
        $value=self::text($value,$field);
        if(preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)!==1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $field): string
    {
        if(!is_string($value)||$value===''||strlen($value)>240||strpbrk($value,"\r\n")!==false)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }
}

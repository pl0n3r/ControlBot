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
    private const PRIORITIES=['critical','high','medium'];
    private const WORK_TYPES=[
        'engineering','security','infrastructure','operations','data_analytics','product',
        'content','marketing_growth','sales_support','finance_analysis','compliance_review',
        'knowledge_documentation',
    ];
    private const LIFECYCLE_ORIGINS=['changing','offboarding','exited'];

    public static function fromRisk(array $intent,array $vendorRaw): array
    {
        return self::build($intent,VendorRegistry::normalize($vendorRaw),'vendor_risk_exception');
    }

    public static function fromLifecycle(array $intent,array $vendorRaw): array
    {
        $vendor=VendorRegistry::normalize($vendorRaw);
        if(!in_array($vendor['lifecycle'],self::LIFECYCLE_ORIGINS,true))
            throw new InvalidArgumentException('Vendor lifecycle does not require lifecycle work.');
        return self::build($intent,$vendor,'vendor_lifecycle_task');
    }

    private static function build(array $intent,array $vendor,string $originKind): array
    {
        self::intent($intent);
        $freshness=$vendor['freshness'];
        if($freshness['observed_at']===null || $freshness['source_ref']===null)
            throw new InvalidArgumentException('Vendor work origin requires observed freshness.');

        $workType=self::workType($intent['work_type']);
        $result=[
            'work_id'=>self::text($intent['work_id'],'work_id'),
            'origin_mode'=>'automatic',
            'origin_system'=>'controlbot',
            'group_id'=>self::text($intent['group_id'],'group_id'),
            'venture_id'=>$vendor['venture_id'],
            'work_type'=>$workType,
            'requested_capabilities'=>self::items($intent['requested_capabilities'],'requested_capabilities',false,true),
            'required_roles'=>self::items($intent['required_roles'],'required_roles',false,true),
            'authority_level'=>self::slug($intent['authority_level'],'authority_level'),
            'producer_ref'=>'controlbot:vendor-governance',
            'priority_class'=>self::priority($intent['priority_class']),
            'depends_on'=>self::items($intent['depends_on'],'depends_on',true,false),
            'claims'=>self::items($intent['claims'],'claims',true,false),
            'policy_ref'=>self::text($intent['policy_ref'],'policy_ref'),
            'evidence_refs'=>self::evidence($vendor,$originKind),
            'idempotency_key'=>'vendor-work:'.hash('sha256',$vendor['vendor_id'].'|'.$originKind.'|'.$workType),
            'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$freshness['observed_at']),
        ];
        foreach(['project_id','budget_ref','approval_ref'] as $field){
            if(array_key_exists($field,$intent) && $intent[$field]!==null)
                $result[$field]=self::text($intent[$field],$field);
        }
        if(array_key_exists('repository_ref',$intent) && $intent['repository_ref']!==null){
            $repo=self::text($intent['repository_ref'],'repository_ref');
            if(preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$repo)!==1)
                throw new InvalidArgumentException('repository_ref invalid.');
            $result['repository_ref']=$repo;
        }
        return $result;
    }

    private static function intent(array $intent): void
    {
        $keys=array_keys($intent);
        $allowed=array_merge(self::REQUIRED,self::OPTIONAL);
        if(array_diff(self::REQUIRED,$keys)!==[] || array_diff($keys,$allowed)!==[] || ($intent['version']??null)!==1)
            throw new InvalidArgumentException('Vendor work origin intent fields invalid.');
    }

    private static function evidence(array $vendor,string $originKind): array
    {
        $refs=[
            $vendor['vendor_id'],$vendor['service_ref'],$vendor['freshness']['source_ref'],
            $vendor['cost']['capital_ref'],$vendor['security_review']['aegis_review_ref'],
            $vendor['legal_review']['lex_review_ref'],
        ];
        if($originKind==='vendor_lifecycle_task'){
            $refs[]=$vendor['exit_plan_ref'];
            $refs[]=$vendor['export_ref'];
            foreach($vendor['offboarding'] as $ref) $refs[]=$ref;
        } else {
            $refs[]=$vendor['contract_ref'];
            $refs[]=$vendor['data_ref'];
        }
        $refs=array_values(array_unique(array_filter($refs,static fn($value):bool=>is_string($value)&&$value!=='')));
        sort($refs,SORT_STRING);
        return $refs;
    }

    private static function items(mixed $value,string $field,bool $allowEmpty,bool $slugItems): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>50||(!$allowEmpty && $value===[]))
            throw new InvalidArgumentException($field.' invalid.');
        $out=[];
        foreach($value as $item){
            $item=$slugItems?self::slug($item,$field.'[]'):self::text($item,$field.'[]');
            if(!in_array($item,$out,true)) $out[]=$item;
        }
        sort($out,SORT_STRING);
        return $out;
    }

    private static function workType(mixed $value): string
    {
        $value=self::slug($value,'work_type');
        if(!in_array($value,self::WORK_TYPES,true)) throw new InvalidArgumentException('work_type invalid.');
        return $value;
    }

    private static function priority(mixed $value): string
    {
        $value=self::slug($value,'priority_class');
        if(!in_array($value,self::PRIORITIES,true)) throw new InvalidArgumentException('priority_class invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $field): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)!==1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $field): string
    {
        if(!is_string($value)||$value===''||strlen($value)>240||str_contains($value,"\n")||str_contains($value,"\r"))
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }
}

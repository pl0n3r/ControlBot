<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class ExternalApiDirectedWorkOrigin
{
    private const WORK_TYPES=[
        'engineering','security','infrastructure','operations','data_analytics','product',
        'content','marketing_growth','sales_support','finance_analysis','compliance_review',
        'knowledge_documentation',
    ];
    private const PRIORITIES=['critical','high','medium'];
    private const SEVERITIES=['critical','high','medium','low','info'];
    private const AUTHORITY_MAP=[
        'L0_AI_AUTONOMOUS'=>'l0_ai_autonomous',
        'L1_OPERATOR'=>'l1_operator',
        'L2_VENTURE_ADMIN'=>'l2_venture_admin',
        'L3_GROUP_INSTITUTION'=>'l3_group_institution',
        'L4_OWNER'=>'l4_owner',
    ];
    private const REQUIRED_INTENT=[
        'version','group_id','work_type','requested_capabilities','required_roles',
        'priority_class','depends_on','claims','policy_ref',
    ];
    private const OPTIONAL_INTENT=[
        'venture_id','project_id','repository_ref','severity','budget_ref',
    ];
    private const SENSITIVE='/(?:-----BEGIN [^-]*PRIVATE KEY-----|\bBearer\s+[A-Za-z0-9._~+\/=\-]{10,}|github_pat_[A-Za-z0-9_]{10,}|gh[pousr]_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9]{20,}|(?:password|passwd|secret|token|api[_-]?key|private[_-]?key)\s*[:=])/i';

    public static function process(
        VerifiedAccessContext $access,
        VerifiedExternalSessionContext $authentication,
        array $clientMutation,
        string $expectedScope,
        string $decisionRef,
        ?array $serverIntent,
        array $requestIds,
        int $occurredAt,
    ): array {
        if($occurredAt<1) throw new InvalidArgumentException('occurred_at invalid.');
        $mutation=ExternalApiContract::decisionMutation($clientMutation);
        $decisionRef=self::decisionRef($decisionRef);

        $authorization=ExternalApiRequestGate::authorize(
            $access,
            'POST',
            '/api/v1/owner-decisions/{decision_id}/decision',
            $expectedScope,
            $authentication,
            $occurredAt,
        );
        if(($authorization['decision']??null)!=='allow'
            ||($authorization['mutation']??null)!==true
            ||($authorization['operation_id']??null)!=='owner_decision.decide')
            throw new InvalidArgumentException('Owner decision mutation not authorized.');

        $summary=$access->safeSummary();
        self::verifiedSummary($summary,$expectedScope);
        $outcome=$mutation['outcome'];

        if($outcome==='reject'){
            if($serverIntent!==null) throw new InvalidArgumentException('Rejected decision must not carry work intent.');
            $audit=ExternalApiMobileAudit::event(
                $access,$authentication,
                'POST','/api/v1/owner-decisions/{decision_id}/decision',
                $expectedScope,$requestIds,$occurredAt,'mutation_rejected',
                self::auditRefs($decisionRef,null,null),
            );
            return [
                'version'=>1,
                'decision'=>['decision_ref'=>$decisionRef,'outcome'=>'reject','approval_ref'=>null],
                'work_item'=>null,
                'work_item_ref'=>null,
                'audit'=>['mutation'=>$audit,'factory_handoff'=>null],
            ];
        }

        if(!is_array($serverIntent)) throw new InvalidArgumentException('Approved decision requires server work intent.');
        $approvalRef=self::approvalRef(
            $decisionRef,$mutation['idempotency_key'],self::text($summary['identity_id']??null,'identity_id',80)
        );
        $mutationAudit=ExternalApiMobileAudit::event(
            $access,$authentication,
            'POST','/api/v1/owner-decisions/{decision_id}/decision',
            $expectedScope,$requestIds,$occurredAt,'mutation_accepted',
            self::auditRefs($decisionRef,$approvalRef,null),
        );
        $workItem=self::workItem(
            $serverIntent,$summary,$decisionRef,$approvalRef,$mutationAudit['audit_ref'],
            $mutation['idempotency_key'],$occurredAt
        );
        $workRef='controlbot:work/'.substr(hash('sha256',self::canonical($workItem)),0,40);
        $handoffAudit=ExternalApiMobileAudit::event(
            $access,$authentication,
            'POST','/api/v1/owner-decisions/{decision_id}/decision',
            $expectedScope,$requestIds,$occurredAt,'factory_handoff',
            self::auditRefs($decisionRef,$approvalRef,$workRef),
        );

        self::secretFree([$workItem,$mutationAudit,$handoffAudit]);

        return [
            'version'=>1,
            'decision'=>['decision_ref'=>$decisionRef,'outcome'=>'approve','approval_ref'=>$approvalRef],
            'work_item'=>$workItem,
            'work_item_ref'=>$workRef,
            'audit'=>['mutation'=>$mutationAudit,'factory_handoff'=>$handoffAudit],
        ];
    }

    private static function workItem(
        array $intent,array $summary,string $decisionRef,string $approvalRef,string $auditRef,
        string $clientIdempotency,int $occurredAt
    ): array {
        self::intentFields($intent);
        $policy=self::text($intent['policy_ref'],'policy_ref');
        $policyRefs=$summary['policy_refs']??null;
        if(!is_array($policyRefs)||!array_is_list($policyRefs)||!in_array($policy,$policyRefs,true))
            throw new InvalidArgumentException('policy_ref is not verified.');

        $authority=$summary['authority_level']??null;
        if(!is_string($authority)||!isset(self::AUTHORITY_MAP[$authority]))
            throw new InvalidArgumentException('authority_level invalid.');
        $identity=self::text($summary['identity_id']??null,'identity_id',80);

        $item=[
            'work_id'=>'mobile-directed:'.substr(hash('sha256',$decisionRef.'|'.$clientIdempotency),0,40),
            'origin_mode'=>'directed',
            'origin_system'=>'human',
            'group_id'=>self::text($intent['group_id'],'group_id'),
            'work_type'=>self::catalog($intent['work_type'],self::WORK_TYPES,'work_type'),
            'requested_capabilities'=>self::items($intent['requested_capabilities'],'requested_capabilities',false,true),
            'required_roles'=>self::items($intent['required_roles'],'required_roles',false,true),
            'authority_level'=>self::AUTHORITY_MAP[$authority],
            'producer_ref'=>'controlbot:identity/'.$identity,
            'priority_class'=>self::catalog($intent['priority_class'],self::PRIORITIES,'priority_class'),
            'depends_on'=>self::items($intent['depends_on'],'depends_on',true,false),
            'claims'=>self::items($intent['claims'],'claims',true,false),
            'policy_ref'=>$policy,
            'approval_ref'=>$approvalRef,
            'evidence_refs'=>self::items([$decisionRef,$auditRef],'evidence_refs',false,false),
            'idempotency_key'=>'mobile-directed:'.hash('sha256',$decisionRef.'|'.$clientIdempotency),
            'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$occurredAt),
        ];

        foreach(['venture_id','project_id','budget_ref'] as $field){
            if(array_key_exists($field,$intent)&&$intent[$field]!==null)
                $item[$field]=self::text($intent[$field],$field);
        }
        if(array_key_exists('repository_ref',$intent)&&$intent['repository_ref']!==null){
            $repo=self::text($intent['repository_ref'],'repository_ref');
            if(preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$repo)!==1)
                throw new InvalidArgumentException('repository_ref invalid.');
            $item['repository_ref']=$repo;
        }
        if(array_key_exists('severity',$intent)&&$intent['severity']!==null)
            $item['severity']=self::catalog($intent['severity'],self::SEVERITIES,'severity');

        return $item;
    }

    private static function verifiedSummary(array $summary,string $expectedScope): void
    {
        $identity=$summary['identity_id']??null;
        $scope=$summary['scope']??null;
        if(!is_string($identity)||!is_string($scope)||!hash_equals($scope,$expectedScope))
            throw new InvalidArgumentException('verified access summary mismatch.');
        if(($summary['capability']??null)!=='owner.decision.write')
            throw new InvalidArgumentException('verified capability invalid.');
    }

    private static function intentFields(array $intent): void
    {
        if(array_is_list($intent)) throw new InvalidArgumentException('Server work intent invalid.');
        $keys=array_keys($intent);
        $allowed=array_merge(self::REQUIRED_INTENT,self::OPTIONAL_INTENT);
        if(array_diff(self::REQUIRED_INTENT,$keys)!==[]
            ||array_diff($keys,$allowed)!==[]
            ||($intent['version']??null)!==1)
            throw new InvalidArgumentException('Server work intent fields invalid.');
    }

    private static function auditRefs(string $decisionRef,?string $approvalRef,?string $workRef): array
    {
        return [
            'decision_ref'=>$decisionRef,
            'approval_ref'=>$approvalRef,
            'work_item_ref'=>$workRef,
            'result_ref'=>null,
        ];
    }

    private static function approvalRef(string $decisionRef,string $idempotency,string $identity): string
    {
        return 'controlbot:approval/mobile/'.substr(hash('sha256',$decisionRef.'|'.$idempotency.'|'.$identity),0,40);
    }

    private static function decisionRef(mixed $value): string
    {
        if(!is_string($value)
            ||preg_match('#^controlbot:decision/[a-z][a-z0-9._/-]{1,119}$#D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('decision_ref invalid.');
        return $value;
    }

    private static function items(mixed $value,string $field,bool $allowEmpty,bool $slug): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>50||(!$allowEmpty&&$value===[]))
            throw new InvalidArgumentException($field.' invalid.');
        $out=[];
        foreach($value as $item){
            $normalized=$slug?self::slug($item,$field.'[]'):self::text($item,$field.'[]');
            $out[$normalized]=true;
        }
        $out=array_keys($out);sort($out,SORT_STRING);return $out;
    }

    private static function catalog(mixed $value,array $allowed,string $field): string
    {
        $value=self::slug($value,$field);
        if(!in_array($value,$allowed,true)) throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $field): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$value)!==1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $field,int $max=240): string
    {
        if(!is_string($value)) throw new InvalidArgumentException($field.' invalid.');
        $value=trim($value);
        if($value===''||strlen($value)>$max||strpbrk($value,"\n\r\0")!==false||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

    private static function canonical(array $value): string
    {
        self::ksortRecursive($value);
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    private static function ksortRecursive(array &$value): void
    {
        if(!array_is_list($value)) ksort($value,SORT_STRING);
        foreach($value as &$item) if(is_array($item)) self::ksortRecursive($item);
        unset($item);
    }

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $key=>$item){
            if(is_string($key)&&preg_match('/(?:^|[_-])(password|passwd|secret|token|api[_-]?key|private[_-]?key)(?:$|[_-])/i',$key)===1)
                throw new InvalidArgumentException('output contains sensitive field.');
            self::secretFree($item);
        } return;}
        if(is_string($value)&&preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('output contains sensitive material.');
    }
}

<?php
declare(strict_types=1);
namespace ControlBot\ExternalApi;

use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class ExternalApiDirectedWorkOrigin
{
    private const TYPES=['engineering','security','infrastructure','operations','data_analytics','product','content','marketing_growth','sales_support','finance_analysis','compliance_review','knowledge_documentation'];
    private const PRIORITIES=['critical','high','medium'];
    private const SEVERITIES=['critical','high','medium','low','info'];
    private const AUTHORITY=['L0_AI_AUTONOMOUS'=>'l0_ai_autonomous','L1_OPERATOR'=>'l1_operator','L2_VENTURE_ADMIN'=>'l2_venture_admin','L3_GROUP_INSTITUTION'=>'l3_group_institution','L4_OWNER'=>'l4_owner'];
    private const REQUIRED=['version','group_id','work_type','requested_capabilities','required_roles','priority_class','depends_on','claims','policy_ref'];
    private const OPTIONAL=['venture_id','project_id','repository_ref','severity','budget_ref'];
    private const SENSITIVE='/(?:-----BEGIN [^-]*PRIVATE KEY-----|\bBearer\s+[A-Za-z0-9._~+\/=\-]{10,}|github_pat_[A-Za-z0-9_]{10,}|gh[pousr]_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9]{20,}|(?:password|passwd|secret|token|api[_-]?key|private[_-]?key)\s*[:=])/i';
    private const METHOD='POST';
    private const PATH='/api/v1/owner-decisions/{decision_id}/decision';

    public static function process(
        VerifiedAccessContext $access, VerifiedExternalSessionContext $auth, array $client,
        string $scope, string $decisionRef, ?array $intent, array $requestIds, int $now
    ): array {
        if($now<1) throw new InvalidArgumentException('occurred_at invalid.');
        $mutation=ExternalApiContract::decisionMutation($client);
        $decisionRef=self::decisionRef($decisionRef);
        $authorization=ExternalApiRequestGate::authorize($access,self::METHOD,self::PATH,$scope,$auth,$now);
        if(($authorization['decision']??null)!=='allow'||($authorization['mutation']??null)!==true
            ||($authorization['operation_id']??null)!=='owner_decision.decide')
            throw new InvalidArgumentException('Owner decision mutation not authorized.');

        $summary=$access->safeSummary();
        if(($summary['scope']??null)!==$scope||($summary['capability']??null)!=='owner.decision.write')
            throw new InvalidArgumentException('verified access summary mismatch.');

        if($mutation['outcome']==='reject'){
            if($intent!==null) throw new InvalidArgumentException('Rejected decision must not carry work intent.');
            return [
                'version'=>1,
                'decision'=>['decision_ref'=>$decisionRef,'outcome'=>'reject','approval_ref'=>null],
                'work_item'=>null,'work_item_ref'=>null,
                'audit'=>['mutation'=>self::audit($access,$auth,$scope,$requestIds,$now,'mutation_rejected',$decisionRef,null,null),'factory_handoff'=>null],
            ];
        }

        if(!is_array($intent)) throw new InvalidArgumentException('Approved decision requires server work intent.');
        $identity=self::text($summary['identity_id']??null,'identity_id',80);
        $approval='controlbot:approval/mobile/'.substr(hash('sha256',$decisionRef.'|'.$mutation['idempotency_key'].'|'.$identity),0,40);
        $mutationAudit=self::audit($access,$auth,$scope,$requestIds,$now,'mutation_accepted',$decisionRef,$approval,null);
        $work=self::workItem($intent,$summary,$decisionRef,$approval,$mutationAudit['audit_ref'],$mutation['idempotency_key'],$now);
        $workRef='controlbot:work/'.substr(hash('sha256',$work['work_id'].'|'.$work['idempotency_key']),0,40);
        $handoff=self::audit($access,$auth,$scope,$requestIds,$now,'factory_handoff',$decisionRef,$approval,$workRef);
        return [
            'version'=>1,
            'decision'=>['decision_ref'=>$decisionRef,'outcome'=>'approve','approval_ref'=>$approval],
            'work_item'=>$work,'work_item_ref'=>$workRef,
            'audit'=>['mutation'=>$mutationAudit,'factory_handoff'=>$handoff],
        ];
    }

    private static function audit(
        VerifiedAccessContext $access, VerifiedExternalSessionContext $auth, string $scope,
        array $ids, int $now, string $outcome, string $decision, ?string $approval, ?string $work
    ): array {
        return ExternalApiMobileAudit::event(
            $access,$auth,self::METHOD,self::PATH,$scope,$ids,$now,$outcome,
            ['decision_ref'=>$decision,'approval_ref'=>$approval,'work_item_ref'=>$work,'result_ref'=>null]
        );
    }

    private static function workItem(
        array $intent, array $summary, string $decision, string $approval,
        string $auditRef, string $clientKey, int $now
    ): array {
        self::intent($intent);
        $policy=self::text($intent['policy_ref'],'policy_ref');
        $policies=$summary['policy_refs']??null;
        if(!is_array($policies)||!array_is_list($policies)||!in_array($policy,$policies,true))
            throw new InvalidArgumentException('policy_ref is not verified.');
        $authority=$summary['authority_level']??null;
        if(!is_string($authority)||!isset(self::AUTHORITY[$authority]))
            throw new InvalidArgumentException('authority_level invalid.');

        $item=[
            'work_id'=>'mobile-directed:'.substr(hash('sha256',$decision.'|'.$clientKey),0,40),
            'origin_mode'=>'directed','origin_system'=>'human',
            'group_id'=>self::text($intent['group_id'],'group_id'),
            'work_type'=>self::catalog($intent['work_type'],self::TYPES,'work_type'),
            'requested_capabilities'=>self::items($intent['requested_capabilities'],'requested_capabilities',false,true),
            'required_roles'=>self::items($intent['required_roles'],'required_roles',false,true),
            'authority_level'=>self::AUTHORITY[$authority],
            'producer_ref'=>'controlbot:identity/'.self::text($summary['identity_id']??null,'identity_id',80),
            'priority_class'=>self::catalog($intent['priority_class'],self::PRIORITIES,'priority_class'),
            'depends_on'=>self::items($intent['depends_on'],'depends_on',true,false),
            'claims'=>self::items($intent['claims'],'claims',true,false),
            'policy_ref'=>$policy,'approval_ref'=>$approval,
            'evidence_refs'=>self::items([$decision,$auditRef],'evidence_refs',false,false),
            'idempotency_key'=>'mobile-directed:'.hash('sha256',$decision.'|'.$clientKey),
            'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),
        ];
        foreach(['venture_id','project_id','budget_ref'] as $field)
            if(($intent[$field]??null)!==null) $item[$field]=self::text($intent[$field],$field);
        if(($intent['repository_ref']??null)!==null){
            $repo=self::text($intent['repository_ref'],'repository_ref');
            if(preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$repo)!==1)
                throw new InvalidArgumentException('repository_ref invalid.');
            $item['repository_ref']=$repo;
        }
        if(($intent['severity']??null)!==null)
            $item['severity']=self::catalog($intent['severity'],self::SEVERITIES,'severity');
        return $item;
    }

    private static function intent(array $intent): void
    {
        if(array_is_list($intent)||($intent['version']??null)!==1
            ||array_diff(self::REQUIRED,array_keys($intent))!==[]
            ||array_diff(array_keys($intent),array_merge(self::REQUIRED,self::OPTIONAL))!==[])
            throw new InvalidArgumentException('Server work intent fields invalid.');
    }

    private static function decisionRef(mixed $value): string
    {
        if(!is_string($value)||preg_match('#^controlbot:decision/[a-z][a-z0-9._/-]{1,119}$#D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1) throw new InvalidArgumentException('decision_ref invalid.');
        return $value;
    }

    private static function items(mixed $value,string $field,bool $empty,bool $slug): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>50||(!$empty&&$value===[]))
            throw new InvalidArgumentException($field.' invalid.');
        $out=[];
        foreach($value as $item) $out[$slug?self::slug($item,$field.'[]'):self::text($item,$field.'[]')]=true;
        $out=array_keys($out); sort($out,SORT_STRING); return $out;
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
        $value=is_string($value)?trim($value):'';
        if($value===''||strlen($value)>$max||strpbrk($value,"\n\r\0")!==false||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException($field.' invalid.');
        return $value;
    }

}

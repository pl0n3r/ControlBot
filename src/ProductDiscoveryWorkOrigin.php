<?php
declare(strict_types=1);
namespace ControlBot\Business;
use InvalidArgumentException;

final class ProductDiscoveryWorkOrigin
{
    private const TYPES='engineering|security|infrastructure|operations|data_analytics|product|content|marketing_growth|sales_support|finance_analysis|compliance_review|knowledge_documentation';
    private const PRIORITY='critical|high|medium';
    private const FRESH='fresh|stale|unknown';

    public static function materialize(array $raw,array $decisionRaw,array $assessmentRaw,array $experimentRaw,array $initiativeRaw,array $hypothesisRaw,array $outcomeRaw,array $baselineRaw,array $variantRaw,string $venture,string $product): array
    {
        try {
            self::shape($raw,['version','work_id','group_id','project_id','repository_ref','work_type','requested_capabilities','required_roles','authority_level','priority_class','policy_ref','depends_on','claims','execution']);
            if($raw['version']!==1||$raw['execution']!==false) throw new InvalidArgumentException('version/execution invalid.');
            $work=self::text($raw['work_id'],'work_id'); $group=self::text($raw['group_id'],'group_id');
            $project=$raw['project_id']===null?null:self::text($raw['project_id'],'project_id');
            $repo=$raw['repository_ref']===null?null:self::repo($raw['repository_ref']);
            $type=self::member($raw['work_type'],self::TYPES,'work_type');
            $caps=self::listOf($raw['requested_capabilities'],'requested_capabilities',false,true);
            $roles=self::listOf($raw['required_roles'],'required_roles',false,true);
            $authority=self::id($raw['authority_level'],'authority_level');
            $priority=self::member($raw['priority_class'],self::PRIORITY,'priority_class');
            $policy=self::text($raw['policy_ref'],'policy_ref');
            $depends=self::listOf($raw['depends_on'],'depends_on',true,false);
            $claims=self::listOf($raw['claims'],'claims',true,false);
            $d=ProductDiscoveryDecision::decide($decisionRaw,$assessmentRaw,$experimentRaw,$initiativeRaw,$hypothesisRaw,$outcomeRaw,$baselineRaw,$variantRaw,$venture,$product);
            $fresh=self::member($d['freshness']??null,self::FRESH,'freshness');
            $observed=self::time($d['evaluation_window']??null); $prov=self::provenance($d,$observed);
            $why=[];
            if(($d['decision']??null)!=='BUILD') $why[]='decision_not_build';
            if(($d['classification']??null)!=='VALIDATED') $why[]='discovery_not_validated';
            if($fresh!=='fresh') $why[]='discovery_evidence_'.$fresh;
            if($why!==[]) return self::blocked($why,$fresh,$prov);
            $item=['work_id'=>$work,'origin_mode'=>'automatic','origin_system'=>'controlbot','group_id'=>$group,'venture_id'=>$d['venture_id'],'work_type'=>$type,'requested_capabilities'=>$caps,'required_roles'=>$roles,'authority_level'=>$authority,'producer_ref'=>'controlbot:product-discovery','priority_class'=>$priority,'depends_on'=>$depends,'claims'=>$claims,'policy_ref'=>$policy,'evidence_refs'=>self::evidence($prov),'observed_at'=>$observed,'idempotency_key'=>self::key($d,$group,$project,$type)];
            if($project!==null) $item['project_id']=$project;
            if($repo!==null) $item['repository_ref']=$repo;
            $out=['version'=>1,'status'=>'materialized','reasons'=>['factory_work_item_materialized'],'freshness'=>$fresh,'work_item'=>$item,'provenance'=>$prov,'gate_ref'=>null,'execution'=>false];
            self::safe($out); return $out;
        } catch(InvalidArgumentException) { return self::blocked(['invalid_input'],'unknown',null); }
    }

    private static function provenance(array $d,string $observed): array
    {
        $p=[]; foreach(['initiative_id','hypothesis_ref','experiment_ref','assessment_ref','decision_ref','decision_reason_ref','assessment_rule_ref','source_ref','evidence_ref'] as $k) $p[$k]=self::text($d[$k]??null,$k);
        foreach(['decision_evidence_refs','assessment_evidence_refs'] as $k) $p[$k]=self::listOf($d[$k]??null,$k,false,false,false);
        $p['freshness']=self::member($d['freshness']??null,self::FRESH,'freshness'); $p['observed_at']=$observed; return $p;
    }
    private static function evidence(array $p): array
    {
        $set=[]; foreach($p as $k=>$v){if($k==='freshness'||$k==='observed_at')continue;foreach(is_array($v)?$v:[$v] as $ref)$set[$ref]=true;}
        $refs=array_keys($set);sort($refs,SORT_STRING);return $refs;
    }
    private static function key(array $d,string $group,?string $project,string $type): string
    {
        return 'product-discovery:'.substr(hash('sha256',implode('|',[$group,$project??'',$d['venture_id'],$d['product_id'],$type,$d['initiative_id'],$d['hypothesis_ref'],$d['experiment_ref'],$d['assessment_ref'],$d['decision_ref']])),0,40);
    }
    private static function time(mixed $w): string
    {
        self::shape($w,['start_at','end_at']);$a=$w['start_at'];$b=$w['end_at'];
        if(!is_int($a)||!is_int($b)||$a<0||$b<=$a||$b>253402300799)throw new InvalidArgumentException('evaluation_window invalid.');
        return gmdate('Y-m-d\TH:i:s\Z',$b);
    }
    private static function blocked(array $why,string $fresh,?array $prov): array
    {
        $why=array_values(array_unique($why));sort($why,SORT_STRING);$seed=$prov['decision_ref']??'unknown';
        $out=['version'=>1,'status'=>'blocked','reasons'=>$why,'freshness'=>$fresh,'work_item'=>null,'provenance'=>$prov,'gate_ref'=>'product-discovery:gate/'.substr(hash('sha256',$seed.'|'.implode('|',$why)),0,40),'execution'=>false];
        self::safe($out);return $out;
    }
    private static function shape(mixed $v,array $wanted): void
    {
        if(!is_array($v)||array_is_list($v))throw new InvalidArgumentException('object invalid.');
        $got=array_keys($v);sort($got,SORT_STRING);sort($wanted,SORT_STRING);if($got!==$wanted)throw new InvalidArgumentException('object fields invalid.');
    }
    private static function listOf(mixed $v,string $field,bool $empty,bool $ids,bool $reject=true): array
    {
        if(!is_array($v)||!array_is_list($v)||count($v)>50||(!$empty&&$v===[]))throw new InvalidArgumentException($field.' invalid.');
        $seen=[];foreach($v as $x){$x=$ids?self::id($x,$field):self::text($x,$field);if($reject&&isset($seen[$x]))throw new InvalidArgumentException($field.' duplicated.');$seen[$x]=true;}
        $out=array_keys($seen);sort($out,SORT_STRING);return $out;
    }
    private static function member(mixed $v,string $catalog,string $field): string
    {
        if(!is_string($v)||!in_array($v,explode('|',$catalog),true))throw new InvalidArgumentException($field.' invalid.');return $v;
    }
    private static function id(mixed $v,string $field): string
    {
        if(!is_string($v)||preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$v)!==1)throw new InvalidArgumentException($field.' invalid.');return $v;
    }
    private static function repo(mixed $v): string
    {
        if(!is_string($v)||preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$v)!==1)throw new InvalidArgumentException('repository_ref invalid.');return $v;
    }
    private static function text(mixed $v,string $field): string
    {
        if(!is_string($v))throw new InvalidArgumentException($field.' invalid.');$v=trim($v);$l=strtolower($v);
        $bad=$v===''||strlen($v)>240||strpbrk($v,"\n\r\0")!==false||preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',$v)===1||preg_match('/(?<![A-Za-z0-9])\+?[0-9][0-9(). -]{7,}[0-9](?![A-Za-z0-9])/',$v)===1;
        foreach(['bearer ','password','passwd','secret','token','api_key','api-key','private_key','private-key','cookie','authorization','dsn','github_pat_','ghp_','gho_','ghu_','ghs_','ghr_','sk-'] as $needle)if(str_contains($l,$needle))$bad=true;
        if($bad)throw new InvalidArgumentException($field.' invalid.');return $v;
    }
    private static function safe(mixed $v): void
    {
        if(is_array($v)){foreach($v as $x)self::safe($x);return;}if(!is_string($v))return;
        if(preg_match('/^(?:\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z|product-discovery:(?:gate\/)?[a-f0-9]{40})$/D',$v)===1)return;
        self::text($v,'output');
    }
}

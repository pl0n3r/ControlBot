<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class MarketWorkOrigin
{
    private const DOMAINS=['aegis','capital','infrastructure','lex','localization','momentum','observability','ownership','payments','pricing','privacy','product','support'];
    private const STATUSES=['ready','blocked','unknown','not_applicable'];
    private const FRESHNESS=['fresh','stale','unknown'];
    private const WORK_TYPES=['engineering','security','infrastructure','operations','data_analytics','product','content','marketing_growth','sales_support','finance_analysis','compliance_review','knowledge_documentation'];
    private const PRIORITIES=['critical','high','medium'];
    private const ROLES=['arquitectura','contenido','datos-analitica','dba','diseno-visual','frontend','infraestructura','ingenieria-software','legal-privacidad','marketing','producto','qa','seguridad','seo','sre','ux'];

    public static function materialize(array $readiness,array $context): array
    {
        try {
            self::fields($readiness,['version','venture_id','market_id','country','market_status','launch_state','missing_domains','gates','execution'],'MarketReadiness');
            self::fields($context,['version','venture_id','market_id','country','group_id','project_id','repository_ref','authority_level','policy_ref','approval_ref','budget_ref','priority_class','depends_on','evidence_refs','profiles','execution'],'MarketWorkOrigin');
            if($readiness['version']!==1||$readiness['execution']!==false||$context['version']!==1||$context['execution']!==false) throw new InvalidArgumentException('version/execution invalid.');
            self::choice($readiness['market_status'],['researching','validating','preparing','launch_ready','live','paused'],'market_status');
            $venture=self::ref($readiness['venture_id'],'venture_id'); $market=self::ref($readiness['market_id'],'market_id'); $country=self::country($readiness['country']);
            if($context['venture_id']!==$venture||$context['market_id']!==$market||$context['country']!==$country) throw new InvalidArgumentException('Market scope mismatch.');
            $gates=self::gates($readiness['gates']); $missing=[];$blocked=[];$unresolved=[];
            foreach($gates as $gate){
                if(in_array($gate['effective_status'],['blocked','unknown'],true)) $missing[]=$gate['domain'];
                if($gate['effective_status']==='blocked') $blocked[]=$gate; elseif($gate['effective_status']==='unknown') $unresolved[]=$gate['domain'];
            }
            $launch=$blocked!==[]?'blocked':($unresolved!==[]?'unknown':'ready');
            if($readiness['launch_state']!==$launch||$readiness['missing_domains']!==$missing) throw new InvalidArgumentException('MarketReadiness projection invalid.');
            if($launch==='ready') return self::result('no_work',['market_ready'],[],[],$venture,$market,$country);
            $profiles=self::profiles($context['profiles']); $base=self::context($context); $items=[];
            foreach($blocked as $gate){
                if($gate['freshness']!=='fresh'||$gate['evidence_refs']===[]||!is_int($gate['observed_at'])||$gate['observed_at']<1) throw new InvalidArgumentException('Blocked gate evidence invalid.');
                $domain=$gate['domain']; if(!isset($profiles[$domain])) throw new InvalidArgumentException('Profile missing.');
                $items[]=self::item($venture,$market,$country,$gate,$profiles[$domain],$base);
            }
            return self::result($items===[]?'blocked':'materialized',$items===[]?['unresolved_readiness']:['factory_handoff_ready'],$items,$unresolved,$venture,$market,$country);
        } catch(InvalidArgumentException) {
            return ['version'=>1,'status'=>'blocked','reasons'=>['invalid_input'],'venture_id'=>null,'market_id'=>null,'country'=>null,'work_items'=>[],'unresolved_domains'=>[],'execution'=>false];
        }
    }

    private static function gates(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)!==count(self::DOMAINS)) throw new InvalidArgumentException('gates invalid.'); $out=[];
        foreach($rows as $gate){
            self::fields($gate,['domain','status','effective_status','evidence_refs','observed_at','freshness'],'gate');
            $domain=self::choice($gate['domain'],self::DOMAINS,'domain'); $status=self::choice($gate['status'],self::STATUSES,'status'); $fresh=self::choice($gate['freshness'],self::FRESHNESS,'freshness');
            if(isset($out[$domain])||$gate['effective_status']!==($fresh==='fresh'?$status:'unknown')) throw new InvalidArgumentException('gate invalid.');
            $evidence=self::items($gate['evidence_refs'],'gate evidence',true,16);
            if($fresh!=='unknown'&&$evidence===[]) throw new InvalidArgumentException('gate evidence invalid.');
            if(($fresh==='unknown'&&$gate['observed_at']!==null)||($fresh!=='unknown'&&(!is_int($gate['observed_at'])||$gate['observed_at']<1))) throw new InvalidArgumentException('observed_at invalid.');
            $out[$domain]=['domain'=>$domain,'status'=>$status,'effective_status'=>$gate['effective_status'],'evidence_refs'=>$evidence,'observed_at'=>$gate['observed_at'],'freshness'=>$fresh];
        }
        ksort($out,SORT_STRING); if(array_keys($out)!==self::DOMAINS) throw new InvalidArgumentException('domains invalid.'); return array_values($out);
    }

    private static function profiles(mixed $raw): array
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException('profiles invalid.'); $out=[];
        foreach($raw as $domain=>$profile){
            $domain=self::choice($domain,self::DOMAINS,'profile domain'); self::fields($profile,['work_type','requested_capabilities','required_roles'],'profile');
            $out[$domain]=['work_type'=>self::choice($profile['work_type'],self::WORK_TYPES,'work_type'),'requested_capabilities'=>self::slugs($profile['requested_capabilities'],'requested_capabilities'),'required_roles'=>self::slugs($profile['required_roles'],'required_roles',self::ROLES)];
        }
        ksort($out,SORT_STRING); return $out;
    }

    private static function context(array $raw): array
    {
        return ['group_id'=>self::ref($raw['group_id'],'group_id'),'authority_level'=>self::slug($raw['authority_level'],'authority_level'),'policy_ref'=>self::ref($raw['policy_ref'],'policy_ref'),'priority_class'=>self::choice($raw['priority_class'],self::PRIORITIES,'priority_class'),'depends_on'=>self::items($raw['depends_on'],'depends_on',true),'evidence_refs'=>self::evidenceRefs($raw['evidence_refs']),'project_id'=>$raw['project_id']===null?null:self::ref($raw['project_id'],'project_id'),'repository_ref'=>$raw['repository_ref']===null?null:self::repository($raw['repository_ref']),'approval_ref'=>$raw['approval_ref']===null?null:self::ref($raw['approval_ref'],'approval_ref'),'budget_ref'=>$raw['budget_ref']===null?null:self::ref($raw['budget_ref'],'budget_ref')];
    }

    private static function item(string $venture,string $market,string $country,array $gate,array $profile,array $base): array
    {
        $domain=$gate['domain']; $hash=hash('sha256',$venture.'|'.$market.'|'.$country.'|'.$domain.'|'.$base['group_id'].'|'.$profile['work_type']);
        $evidence=array_values(array_unique(array_merge($gate['evidence_refs'],$base['evidence_refs']))); sort($evidence,SORT_STRING); if(count($evidence)>50) throw new InvalidArgumentException('evidence_refs invalid.');
        $claims=['controlbot:market/'.$market,'controlbot:market-gap/'.$market.'/'.$country.'/'.$domain]; sort($claims,SORT_STRING);
        $item=['work_id'=>'controlbot:market-gap/'.substr($hash,0,40),'origin_mode'=>'automatic','origin_system'=>'controlbot','producer_ref'=>'controlbot:market-work-origin','group_id'=>$base['group_id'],'venture_id'=>$venture,'work_type'=>$profile['work_type'],'requested_capabilities'=>$profile['requested_capabilities'],'required_roles'=>$profile['required_roles'],'authority_level'=>$base['authority_level'],'priority_class'=>$base['priority_class'],'depends_on'=>$base['depends_on'],'claims'=>$claims,'policy_ref'=>$base['policy_ref'],'evidence_refs'=>$evidence,'observed_at'=>gmdate('Y-m-d\TH:i:s\Z',$gate['observed_at']),'idempotency_key'=>'controlbot:market-gap:'.$hash];
        foreach(['project_id','repository_ref','approval_ref','budget_ref'] as $key) if($base[$key]!==null) $item[$key]=$base[$key]; return $item;
    }

    private static function result(string $status,array $reasons,array $items,array $unresolved,string $venture,string $market,string $country): array
    {sort($unresolved,SORT_STRING);return ['version'=>1,'status'=>$status,'reasons'=>$reasons,'venture_id'=>$venture,'market_id'=>$market,'country'=>$country,'work_items'=>$items,'unresolved_domains'=>$unresolved,'execution'=>false];}

    private static function evidenceRefs(mixed $refs):array
    {
        if(!is_array($refs)||!array_is_list($refs)||count($refs)>50){
            throw new InvalidArgumentException('evidence_refs invalid.');
        }
        $valid=array_filter(
            $refs,
            static fn($ref):bool=>is_string($ref)
                &&preg_match('#^controlbot:[a-z][a-z0-9-]{1,31}/[a-f0-9]{32}$#D',$ref)===1
        );
        if(count($valid)!==count($refs)||count(array_unique($refs))!==count($refs)){
            throw new InvalidArgumentException('evidence_refs invalid.');
        }
        sort($refs,SORT_STRING);
        return $refs;
    }

    private static function items(mixed $v,string $label,bool $empty,int $max=50):array
    {if(!is_array($v)||!array_is_list($v)||count($v)>$max||(!$empty&&$v===[]))throw new InvalidArgumentException($label.' invalid.');$o=[];foreach($v as $x)$o[self::ref($x,$label)]=true;$o=array_keys($o);sort($o,SORT_STRING);return $o;}
    private static function slugs(mixed $v,string $label,?array $catalog=null):array
    {if(!is_array($v)||!array_is_list($v)||$v===[]||count($v)>50)throw new InvalidArgumentException($label.' invalid.');$o=[];foreach($v as $x){$x=self::slug($x,$label);if($catalog!==null&&!in_array($x,$catalog,true))throw new InvalidArgumentException($label.' outside catalog.');$o[$x]=true;}$o=array_keys($o);sort($o,SORT_STRING);return $o;}
    private static function country(mixed $v):string {if(is_string($v)&&preg_match('/^[A-Z]{2}$/D',$v)===1)return $v;throw new InvalidArgumentException('country invalid.');}
    private static function repository(mixed $v):string {if(is_string($v)&&preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D',$v)===1)return $v;throw new InvalidArgumentException('repository_ref invalid.');}
    private static function ref(mixed $v,string $label):string {$v=is_string($v)?trim($v):'';if($v===''||strlen($v)>240||str_contains($v,'@')||strpbrk($v,"\n\r\0")!==false||preg_match('/(?:bearer\s+|password|passwd|secret|token|api[_-]?key|private[_-]?key|github_pat_|gh[pousr]_|sk-)/i',$v)===1)throw new InvalidArgumentException($label.' invalid.');return $v;}
    private static function slug(mixed $v,string $label):string {if(is_string($v)&&preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D',$v)===1)return $v;throw new InvalidArgumentException($label.' invalid.');}
    private static function choice(mixed $v,array $allowed,string $label):string {if(is_string($v)&&in_array($v,$allowed,true))return $v;throw new InvalidArgumentException($label.' invalid.');}
    private static function fields(mixed $row,array $expected,string $label):void {if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');$a=array_keys($row);sort($a);sort($expected);if($a!==$expected)throw new InvalidArgumentException($label.' fields invalid.');}
}

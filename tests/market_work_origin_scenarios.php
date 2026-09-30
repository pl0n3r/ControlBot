<?php
declare(strict_types=1);
require __DIR__.'/../src/MarketScope.php';
require __DIR__.'/../src/MarketReadiness.php';
require __DIR__.'/../src/MarketWorkOrigin.php';
use ControlBot\Business\MarketScope;
use ControlBot\Business\MarketReadiness;
use ControlBot\Business\MarketWorkOrigin;

const MW_DOMAINS=['aegis','capital','infrastructure','lex','localization','momentum','observability','ownership','payments','pricing','privacy','product','support'];
function market444(array $overrides=[]):array{return array_replace([
 'version'=>1,'market_id'=>'market-condor-co','venture_id'=>'venture-condor','geography'=>['kind'=>'country','code'=>'CO'],
 'status'=>'preparing','priority'=>1,'currencies'=>['COP'],'locales'=>['es-CO'],'source_ref'=>'controlbot:market/condor/co','observed_at'=>2000,'freshness'=>'fresh',
],$overrides);}
function gate444(string $domain,array $overrides=[]):array{return array_replace([
 'domain'=>$domain,'status'=>'ready','evidence_refs'=>['controlbot:evidence/'.substr(hash('sha256',$domain),0,32)],'observed_at'=>1900,'freshness'=>'fresh',
],$overrides);}
function gates444():array{return array_map(fn(string $d):array=>gate444($d),MW_DOMAINS);}
function replace444(array $rows,string $domain,array $overrides):array{return array_map(fn(array $g):array=>$g['domain']===$domain?array_replace($g,$overrides):$g,$rows);}
function ready444(array $rows):array{return MarketReadiness::forCountry(market444(),'venture-condor','CO',$rows);}
function evidence444(string $hex='a'):string{return 'controlbot:market-evidence/'.str_repeat($hex,32);}
function context444(array $overrides=[]):array{return array_replace([
 'version'=>1,'venture_id'=>'venture-condor','market_id'=>'market-condor-co','country'=>'CO','group_id'=>'group-condor',
 'project_id'=>'project-controlbot','repository_ref'=>'pl0n3r/ControlBot','authority_level'=>'owner_required','policy_ref'=>'controlbot:policy/business-os-v1',
 'approval_ref'=>'controlbot:approval/market-co','budget_ref'=>'controlbot:budget/market-co','priority_class'=>'high',
 'depends_on'=>['factory:work/prerequisite'],'evidence_refs'=>[evidence444()],
 'profiles'=>[
   'payments'=>['work_type'=>'operations','requested_capabilities'=>['market-remediation'],'required_roles'=>['ingenieria-software','producto']],
   'support'=>['work_type'=>'operations','requested_capabilities'=>['market-remediation'],'required_roles'=>['producto']],
 ],'execution'=>false,
],$overrides);}
function origin444(array $rows,array $context=[]):array{return MarketWorkOrigin::materialize(ready444($rows),$context?:context444());}
function invalid444(array $result):bool{return $result['status']==='blocked'&&$result['reasons']===['invalid_input']&&$result['work_items']===[]&&$result['execution']===false;}

$case=$argv[1]??'';
if($case==='fresh'){
 $out=origin444(replace444(gates444(),'payments',['status'=>'blocked']));
}elseif($case==='closed'){
 $unknown=origin444(replace444(gates444(),'support',['status'=>'unknown']));
 $stale=origin444(replace444(gates444(),'payments',['status'=>'blocked','freshness'=>'stale','observed_at'=>1800]));
 $scope=context444(['country'=>'MX']); $profile=context444(); $profile['profiles']['payments']['required_roles']=['not-a-role'];
 $extra=context444(); $extra['unexpected']=true;
 $out=['unknown'=>$unknown,'stale'=>$stale,'scope'=>invalid444(origin444(replace444(gates444(),'payments',['status'=>'blocked']),$scope)),
  'profile'=>invalid444(origin444(replace444(gates444(),'payments',['status'=>'blocked']),$profile)),
  'extra'=>invalid444(origin444(replace444(gates444(),'payments',['status'=>'blocked']),$extra))];
}elseif($case==='context_evidence'){
 $rows=replace444(gates444(),'payments',['status'=>'blocked']); $a=evidence444('a'); $b=evidence444('b');
 $invalid=['phone'=>['3001234567'],'email'=>['owner@example.com'],'free_text'=>['Customer Name'],
  'secret'=>['token=market-secret'],'malformed'=>['controlbot:evidence/nothex'],'duplicate'=>[$a,$a]];
 $out=['valid'=>origin444($rows,context444(['evidence_refs'=>[$b,$a]]))];
 foreach($invalid as $key=>$refs)$out[$key]=invalid444(origin444($rows,context444(['evidence_refs'=>$refs])));
}elseif($case==='governance'){
 $out=origin444(replace444(gates444(),'payments',['status'=>'blocked']));
}elseif($case==='deterministic'){
 $rows=replace444(gates444(),'payments',['status'=>'blocked']); $rows=replace444($rows,'support',['status'=>'blocked']);
 $out=['first'=>origin444($rows),'second'=>origin444(array_reverse($rows))];
}elseif($case==='e2e'){
 $normalized=MarketScope::market(market444(),'venture-condor');
 $mixed=replace444(gates444(),'payments',['status'=>'blocked']); $mixed=replace444($mixed,'support',['status'=>'unknown']);
 $out=['market'=>$normalized,'mixed'=>origin444($mixed),'ready'=>origin444(gates444())];
}elseif($case==='pure'){
 $r=new ReflectionClass(MarketWorkOrigin::class); $out=['methods'=>array_map(fn(ReflectionMethod $m):string=>$m->getName(),$r->getMethods(ReflectionMethod::IS_PUBLIC))];
}else{fwrite(STDERR,"Unknown market work origin scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

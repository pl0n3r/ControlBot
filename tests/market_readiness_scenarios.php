<?php
declare(strict_types=1);
require __DIR__.'/../src/MarketScope.php';
require __DIR__.'/../src/MarketReadiness.php';
use ControlBot\Business\MarketReadiness;

const DOMAINS=['aegis','capital','infrastructure','lex','localization','momentum','observability','ownership','payments','pricing','privacy','product','support'];
function blocked(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function market(array $overrides=[]): array { return array_replace([
    'version'=>1,'market_id'=>'market-condor-co','venture_id'=>'venture-condor',
    'geography'=>['kind'=>'country','code'=>'CO'],'status'=>'preparing','priority'=>1,
    'currencies'=>['COP'],'locales'=>['es-CO'],'source_ref'=>'controlbot:market/condor/co',
    'observed_at'=>2000,'freshness'=>'fresh',
],$overrides); }
function gate(string $domain,array $overrides=[]): array { return array_replace([
    'domain'=>$domain,'status'=>'ready',
    'evidence_refs'=>['controlbot:evidence/'.substr(hash('sha256',$domain),0,32)],
    'observed_at'=>1900,'freshness'=>'fresh',
],$overrides); }
function gates(): array { return array_map(fn(string $d): array=>gate($d),DOMAINS); }
function replaceGate(array $rows,string $domain,array $overrides): array {
    return array_map(fn(array $g): array=>$g['domain']===$domain?array_replace($g,$overrides):$g,$rows);
}

$case=$argv[1]??'';
if($case==='complete'){
    $rows=array_reverse(gates());
    $a=MarketReadiness::forCountry(market(),'venture-condor','CO',$rows);
    $b=MarketReadiness::forCountry(market(),'venture-condor','CO',$rows);
    $out=['first'=>$a,'second'=>$b];
}elseif($case==='contract'){
    $missing=gates(); array_pop($missing);
    $duplicate=gates(); $duplicate[]=gate('support');
    $extra=gates(); $extra[]=gate('sales');
    $fieldExtra=gates(); $fieldExtra[0]['unexpected']=true;
    $out=[
        'missing'=>blocked(fn()=>MarketReadiness::forCountry(market(),'venture-condor','CO',$missing)),
        'duplicate'=>blocked(fn()=>MarketReadiness::forCountry(market(),'venture-condor','CO',$duplicate)),
        'extra'=>blocked(fn()=>MarketReadiness::forCountry(market(),'venture-condor','CO',$extra)),
        'field_extra'=>blocked(fn()=>MarketReadiness::forCountry(market(),'venture-condor','CO',$fieldExtra)),
        'venture_mismatch'=>blocked(fn()=>MarketReadiness::forCountry(market(),'venture-brvtal','CO',gates())),
        'country_mismatch'=>blocked(fn()=>MarketReadiness::forCountry(market(),'venture-condor','MX',gates())),
    ];
}elseif($case==='freshness'){
    $stale=replaceGate(gates(),'lex',['freshness'=>'stale','observed_at'=>1800]);
    $unknown=replaceGate(gates(),'privacy',['status'=>'ready','freshness'=>'unknown','observed_at'=>null,'evidence_refs'=>[]]);
    $noEvidence=replaceGate(gates(),'product',['evidence_refs'=>[]]);
    $out=[
        'stale'=>MarketReadiness::forCountry(market(),'venture-condor','CO',$stale),
        'unknown_ready'=>MarketReadiness::forCountry(market(),'venture-condor','CO',$unknown),
        'evidenceless_ready_rejected'=>blocked(fn()=>MarketReadiness::forCountry(market(),'venture-condor','CO',$noEvidence)),
    ];
}elseif($case==='missing'){
    $blockedRows=replaceGate(gates(),'payments',['status'=>'blocked']);
    $blockedRows=replaceGate($blockedRows,'support',['status'=>'unknown','freshness'=>'fresh']);
    $unknownRows=replaceGate(gates(),'support',['status'=>'unknown','freshness'=>'fresh']);
    $naRows=replaceGate(gates(),'payments',['status'=>'not_applicable']);
    $out=[
        'blocked'=>MarketReadiness::forCountry(market(),'venture-condor','CO',$blockedRows),
        'unknown'=>MarketReadiness::forCountry(market(),'venture-condor','CO',$unknownRows),
        'ready_with_na'=>MarketReadiness::forCountry(market(),'venture-condor','CO',$naRows),
    ];
}elseif($case==='market'){
    $live=MarketReadiness::forCountry(market(['status'=>'live']),'venture-condor','CO',replaceGate(gates(),'lex',['status'=>'blocked']));
    $global=market(['market_id'=>'market-condor-global','geography'=>['kind'=>'global','code'=>null]]);
    $region=market(['market_id'=>'market-condor-latam','geography'=>['kind'=>'region','code'=>'LATAM']]);
    $stale=market(['freshness'=>'stale']);
    $out=[
        'live'=>$live,
        'global_rejected'=>blocked(fn()=>MarketReadiness::forCountry($global,'venture-condor','CO',gates())),
        'region_rejected'=>blocked(fn()=>MarketReadiness::forCountry($region,'venture-condor','CO',gates())),
        'stale_market_rejected'=>blocked(fn()=>MarketReadiness::forCountry($stale,'venture-condor','CO',gates())),
    ];
}elseif($case==='pure'){
    $reflection=new ReflectionClass(MarketReadiness::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    $out=['methods'=>$methods,'projection'=>MarketReadiness::forCountry(market(),'venture-condor','CO',gates())];
}else{fwrite(STDERR,"Unknown market readiness scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

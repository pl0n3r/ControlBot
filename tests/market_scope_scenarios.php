<?php
declare(strict_types=1);
require __DIR__.'/../src/MarketScope.php';
use ControlBot\Business\MarketScope;

function blocked(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function scope(array $overrides=[]): array { return array_replace([
    'version'=>1,'mode'=>'single_country','primary_country'=>'CO','target_countries'=>['CO'],
    'excluded_countries'=>[],'launch_countries'=>['CO'],'expansion_candidates'=>['MX'],
    'default_currency'=>'COP','default_locale'=>'es-CO',
],$overrides); }
function market(array $overrides=[]): array { return array_replace([
    'version'=>1,'market_id'=>'market-condor-co','venture_id'=>'venture-condor',
    'geography'=>['kind'=>'country','code'=>'CO'],'status'=>'preparing','priority'=>1,
    'currencies'=>['COP'],'locales'=>['es-CO'],'source_ref'=>'controlbot:market/condor/co',
    'observed_at'=>2000,'freshness'=>'fresh',
],$overrides); }

$case=$argv[1]??'';
if($case==='modes'){
    $multi=scope(['mode'=>'multi_country','primary_country'=>'CO','target_countries'=>['MX','CO'],'launch_countries'=>['CO'],'expansion_candidates'=>['CL']]);
    $global=scope(['mode'=>'global','primary_country'=>'CO','target_countries'=>[],'launch_countries'=>['US'],'expansion_candidates'=>['MX']]);
    $out=[
        'single'=>MarketScope::scope(scope()),'multi'=>MarketScope::scope($multi),'global'=>MarketScope::scope($global),
        'single_missing_primary'=>blocked(fn()=>MarketScope::scope(scope(['primary_country'=>null]))),
        'multi_too_small'=>blocked(fn()=>MarketScope::scope(scope(['mode'=>'multi_country','target_countries'=>['CO']]))),
        'launch_outside_target'=>blocked(fn()=>MarketScope::scope(scope(['launch_countries'=>['MX']]))),
    ];
}elseif($case==='closed'){
    $canonical=MarketScope::scope(scope(['mode'=>'multi_country','target_countries'=>['MX','CO'],'launch_countries'=>['MX','CO'],'expansion_candidates'=>['PE','CL']]));
    $extra=scope(); $extra['unexpected']=true;
    $out=[
        'canonical'=>$canonical,
        'duplicate'=>blocked(fn()=>MarketScope::scope(scope(['target_countries'=>['CO','CO']]))),
        'excluded_overlap'=>blocked(fn()=>MarketScope::scope(scope(['excluded_countries'=>['CO']]))),
        'expansion_overlap'=>blocked(fn()=>MarketScope::scope(scope(['expansion_candidates'=>['CO']]))),
        'bad_country'=>blocked(fn()=>MarketScope::scope(scope(['primary_country'=>'ZZ','target_countries'=>['ZZ'],'launch_countries'=>[]]))),
        'lower_country'=>blocked(fn()=>MarketScope::scope(scope(['primary_country'=>'co','target_countries'=>['co'],'launch_countries'=>[]]))),
        'bad_currency'=>blocked(fn()=>MarketScope::scope(scope(['default_currency'=>'ZZZ']))),
        'bad_locale'=>blocked(fn()=>MarketScope::scope(scope(['default_locale'=>'es_CO']))),
        'extra'=>blocked(fn()=>MarketScope::scope($extra)),
    ];
}elseif($case==='venture'){
    $out=[
        'market'=>MarketScope::market(market(),'venture-condor'),
        'cross_venture'=>blocked(fn()=>MarketScope::market(market(),'venture-brvtal')),
    ];
}elseif($case==='state'){
    $live=MarketScope::market(market(['status'=>'live','freshness'=>'unknown']),'venture-condor');
    $out=[
        'live'=>$live,
        'invalid_status'=>blocked(fn()=>MarketScope::market(market(['status'=>'compliant']),'venture-condor')),
        'global'=>MarketScope::market(market(['market_id'=>'market-condor-global','geography'=>['kind'=>'global','code'=>null]]),'venture-condor'),
    ];
}elseif($case==='condor'){
    $co=MarketScope::scope(scope());
    $fr=MarketScope::scope(scope(['primary_country'=>'FR','target_countries'=>['FR'],'launch_countries'=>['FR'],'expansion_candidates'=>['DE'],'default_currency'=>'EUR','default_locale'=>'fr-FR']));
    $out=['condor'=>$co,'other_country'=>$fr];
}elseif($case==='pure'){
    $reflection=new ReflectionClass(MarketScope::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    $out=['methods'=>$methods,'scope'=>MarketScope::scope(scope()),'market'=>MarketScope::market(market(),'venture-condor')];
}else{fwrite(STDERR,"Unknown market scope scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

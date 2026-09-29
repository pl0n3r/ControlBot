<?php
declare(strict_types=1);

require __DIR__.'/../src/MarketScope.php';
require __DIR__.'/../src/MarketScopeLifecycle.php';

use ControlBot\Business\MarketScopeLifecycle;

const ACTOR='controlbot:identity/owner';
const NOW=3000;

function blocked(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function scope(array $overrides=[]): array { return array_replace([
    'version'=>1,'mode'=>'single_country','primary_country'=>'CO','target_countries'=>['CO'],
    'excluded_countries'=>[],'launch_countries'=>['CO'],'expansion_candidates'=>['MX'],
    'default_currency'=>'COP','default_locale'=>'es-CO',
],$overrides); }
function multi(array $overrides=[]): array { return scope(array_replace([
    'mode'=>'multi_country','primary_country'=>'CO','target_countries'=>['MX','CO'],
    'launch_countries'=>['CO'],'expansion_candidates'=>['CL'],
],$overrides)); }

$case=$argv[1]??'';
if($case==='tbd'){
    $a=MarketScopeLifecycle::change(null,scope(),'venture-condor',ACTOR,NOW);
    $b=MarketScopeLifecycle::change(null,scope(),'venture-condor',ACTOR,NOW);
    $out=['first'=>$a,'second'=>$b];
}elseif($case==='delta'){
    $before=multi();
    $after=multi([
        'target_countries'=>['PE','CO'],
        'launch_countries'=>['PE'],
        'expansion_candidates'=>['BR'],
        'default_currency'=>'USD',
    ]);
    $reordered=multi([
        'target_countries'=>['CO','MX'],
        'launch_countries'=>['CO'],
        'expansion_candidates'=>['CL'],
    ]);
    $out=[
        'change'=>MarketScopeLifecycle::change($before,$after,'venture-condor',ACTOR,NOW),
        'reorder_noop'=>blocked(fn()=>MarketScopeLifecycle::change($before,$reordered,'venture-condor',ACTOR,NOW)),
    ];
}elseif($case==='signals'){
    $event=MarketScopeLifecycle::change(
        scope(),
        scope(['default_currency'=>'USD','default_locale'=>'en-US']),
        'venture-condor',ACTOR,NOW
    );
    $out=['event'=>$event];
}elseif($case==='global'){
    $before=scope([
        'mode'=>'global','primary_country'=>null,'target_countries'=>[],
        'launch_countries'=>[],'expansion_candidates'=>['MX'],
        'default_currency'=>'USD','default_locale'=>'en-US',
    ]);
    $after=$before; $after['launch_countries']=['US'];
    $out=['event'=>MarketScopeLifecycle::change($before,$after,'venture-global',ACTOR,NOW)];
}elseif($case==='minimal'){
    $event=MarketScopeLifecycle::change(null,scope(),'venture-condor',ACTOR,NOW);
    $out=[
        'event'=>$event,
        'secret_actor_rejected'=>blocked(fn()=>MarketScopeLifecycle::change(null,scope(),'venture-condor','controlbot:identity/password',NOW)),
        'cross_venture_format_rejected'=>blocked(fn()=>MarketScopeLifecycle::change(null,scope(),'condor',ACTOR,NOW)),
    ];
}elseif($case==='pure'){
    $reflection=new ReflectionClass(MarketScopeLifecycle::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),$reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    $out=['methods'=>$methods,'event'=>MarketScopeLifecycle::change(null,scope(),'venture-condor',ACTOR,NOW)];
}else{fwrite(STDERR,"Unknown market lifecycle scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

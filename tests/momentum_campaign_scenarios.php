<?php
declare(strict_types=1);
require __DIR__.'/../src/MomentumCampaign.php';

use ControlBot\Business\MomentumCampaign;

function bad(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function ref(string $kind,string $hex='11111111111111111111111111111111',string $venture='venture-alpha'): string {
    return "controlbot:venture/$venture/$kind/$hex";
}
function campaign(array $o=[]): array { return array_replace([
    'version'=>1,'campaign_id'=>'campaign:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'venture_ref'=>'controlbot:venture/venture-alpha','objective_ref'=>ref('objective'),
    'audience_ref'=>ref('audience'),'offer_ref'=>ref('offer'),'cta_ref'=>ref('cta'),
    'channels'=>['paid_social','email'],'creative_variant_refs'=>[ref('creative','22222222222222222222222222222222')],
    'status'=>'ready','budget_ref'=>ref('budget'),'authority_ref'=>ref('authority'),
    'source_ref'=>ref('source'),'observed_at'=>1700,'freshness'=>'current',
    'result_refs'=>[ref('result','33333333333333333333333333333333')],'attribution_state'=>'observed',
],$o); }

$case=$argv[1]??'';
if($case==='closed'){
    $out=[
        'valid'=>MomentumCampaign::normalize(campaign()),
        'bad_channel'=>bad(fn()=>MomentumCampaign::normalize(campaign(['channels'=>['meta_ads']]))),
        'bad_status'=>bad(fn()=>MomentumCampaign::normalize(campaign(['status'=>'sending']))),
        'bad_id'=>bad(fn()=>MomentumCampaign::normalize(campaign(['campaign_id'=>'campaign:felipe']))),
    ];
}elseif($case==='authority'){
    $out=['row'=>MomentumCampaign::normalize(campaign()),'without_authority'=>MomentumCampaign::normalize(campaign(['authority_ref'=>null]))];
}elseif($case==='attribution'){
    $unknown=campaign(['freshness'=>'unknown','source_ref'=>null,'observed_at'=>null,'result_refs'=>[],'attribution_state'=>'unknown']);
    $out=[
        'observed'=>MomentumCampaign::normalize(campaign()),
        'inferred'=>MomentumCampaign::normalize(campaign(['attribution_state'=>'inferred'])),
        'unknown'=>MomentumCampaign::normalize($unknown),
        'unknown_with_result'=>bad(fn()=>MomentumCampaign::normalize(array_replace($unknown,['result_refs'=>[ref('result')]]))),
        'unknown_with_source'=>bad(fn()=>MomentumCampaign::normalize(array_replace($unknown,['source_ref'=>ref('source')]))),
        'observed_without_result'=>bad(fn()=>MomentumCampaign::normalize(campaign(['result_refs'=>[]]))),
    ];
}elseif($case==='invalid'){
    $extra=campaign();$extra['email']='person@example.com';
    $out=[
        'cross_venture'=>bad(fn()=>MomentumCampaign::normalize(campaign(['audience_ref'=>ref('audience','11111111111111111111111111111111','venture-beta')]))),
        'duplicate_channel'=>bad(fn()=>MomentumCampaign::normalize(campaign(['channels'=>['email','email']]))),
        'duplicate_creative'=>bad(fn()=>MomentumCampaign::normalize(campaign(['creative_variant_refs'=>[ref('creative'),ref('creative')]]))),
        'extra_pii'=>bad(fn()=>MomentumCampaign::normalize($extra)),
        'external_url'=>bad(fn()=>MomentumCampaign::normalize(campaign(['cta_ref'=>'https://example.invalid/action']))),
        'secret_ref'=>bad(fn()=>MomentumCampaign::normalize(campaign(['venture_ref'=>'controlbot:venture/password']))),
        'credential_ref'=>bad(fn()=>MomentumCampaign::normalize(campaign(['authority_ref'=>'controlbot:venture/venture-alpha/authority/token-value']))),
    ];
}elseif($case==='deterministic'){
    $raw=campaign([
        'channels'=>['paid_social','email'],
        'creative_variant_refs'=>[ref('creative','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),ref('creative','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')],
        'result_refs'=>[ref('result','dddddddddddddddddddddddddddddddd'),ref('result','cccccccccccccccccccccccccccccccc')],
    ]);
    $out=['a'=>MomentumCampaign::normalize($raw),'b'=>MomentumCampaign::normalize($raw)];
}elseif($case==='pure'){
    $r=new ReflectionClass(MomentumCampaign::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===MomentumCampaign::class
    ));
    sort($methods,SORT_STRING);$out=['methods'=>$methods,'source'=>file_get_contents(__DIR__.'/../src/MomentumCampaign.php')];
}else{fwrite(STDERR,"Unknown momentum campaign scenario\n");exit(2);}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

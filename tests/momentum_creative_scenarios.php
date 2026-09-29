<?php
declare(strict_types=1);

require __DIR__.'/../src/MomentumCampaign.php';
require __DIR__.'/../src/MomentumCreative.php';

use ControlBot\Momentum\MomentumCreative;

function brand(): array {
    return ['version'=>1,'brand_context_id'=>'brand:11111111111111111111111111111111','venture_id'=>'venture-condor',
        'tone_ref'=>'tone:22222222222222222222222222222222','constraints'=>['brand-safe'],
        'source_ref'=>'source:33333333333333333333333333333333','observed_at'=>1000];
}
function brief(string $venture='venture-condor'): array {
    return ['version'=>1,'brief_id'=>'brief:44444444444444444444444444444444','venture_id'=>$venture,
        'brand_context_id'=>'brand:11111111111111111111111111111111',
        'campaign_ref'=>'campaign:55555555555555555555555555555555','format'=>'static_image',
        'audience_ref'=>'audience:66666666666666666666666666666666',
        'offer_ref'=>'offer:77777777777777777777777777777777','cta_ref'=>'cta:88888888888888888888888888888888',
        'constraint_refs'=>['constraint:99999999999999999999999999999999']];
}
function variant(string $key='A',string $status='draft'): array {
    $suffix=$key==='A'?'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa':'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    return ['version'=>1,'variant_id'=>'creative:'.$suffix,'brief_id'=>'brief:44444444444444444444444444444444',
        'venture_id'=>'venture-condor','brand_context_id'=>'brand:11111111111111111111111111111111',
        'variant_key'=>$key,'format'=>'static_image','content_ref'=>'content:cccccccccccccccccccccccccccccccc',
        'asset_ref'=>'asset:dddddddddddddddddddddddddddddddd','hypothesis_ref'=>null,
        'metric_ref'=>'metric:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','provenance_refs'=>[],'status'=>$status];
}
function blocked(callable $fn): bool { try{$fn();return false;}catch(Throwable){return true;} }

$case=$argv[1]??'';
if($case==='scope'){
    echo json_encode(['brief'=>MomentumCreative::brief(brief(),brand()),
        'variant'=>MomentumCreative::variant(variant(),brief(),brand()),
        'cross_venture'=>blocked(fn()=>MomentumCreative::brief(brief('venture-brvtal'),brand()))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='neutral'){
    $contentOnly=variant(); unset($contentOnly['asset_ref'],$contentOnly['hypothesis_ref'],$contentOnly['metric_ref']);
    $assetOnly=variant('B'); unset($assetOnly['content_ref'],$assetOnly['hypothesis_ref'],$assetOnly['metric_ref']);
    echo json_encode(['full'=>MomentumCreative::variant(variant(),brief(),brand()),
        'content_only'=>MomentumCreative::variant($contentOnly,brief(),brand()),
        'asset_only'=>MomentumCreative::variant($assetOnly,brief(),brand())],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='lifecycle'){
    $approved=variant('A','approved');
    $approved['provenance_refs']=['source:ffffffffffffffffffffffffffffffff'];
    $missing=variant('B','approved');
    $archived=$approved;$archived['status']='archived';
    echo json_encode(['approved'=>MomentumCreative::variant($approved,brief(),brand()),
        'missing_rejected'=>blocked(fn()=>MomentumCreative::variant($missing,brief(),brand())),
        'archived'=>MomentumCreative::variant($archived,brief(),brand())],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='set'){
    $a=variant('A');$b=variant('B');
    $duplicate=[$a,$a];
    echo json_encode(['ordered'=>MomentumCreative::variantSet([$b,$a],brief(),brand()),
        'empty'=>MomentumCreative::variantSet([],brief(),brand()),
        'duplicate_rejected'=>blocked(fn()=>MomentumCreative::variantSet($duplicate,brief(),brand()))],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown MOMENTUM creative scenario\n");exit(2);

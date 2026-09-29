<?php
declare(strict_types=1);
require __DIR__.'/../src/ProductIntelligence.php';
require __DIR__.'/../src/ProductHealthSnapshot.php';

use ControlBot\Business\ProductHealthSnapshot;

function phReject(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }
function phMetric(string $id,string $category,int|float|null $value,array $o=[]): array {
    return array_replace([
        'version'=>1,'metric_id'=>$id,'venture_id'=>'venture-condor','product_id'=>'product-condor',
        'surface'=>'web_app','category'=>$category,'period'=>['start_at'=>1000,'end_at'=>2000],
        'status'=>$value===null?'unknown':'measured','value'=>$value,'unit'=>'ratio',
        'source_ref'=>'aggregate:analytics/product','evidence_ref'=>'evidence:product/health',
        'sample_size'=>$value===null?0:100,'freshness'=>$value===null?'unknown':'fresh',
        'confidence'=>$value===null?0.0:0.9,'nature'=>'observed',
    ],$o);
}
function ph(array $metrics): array {
    return ProductHealthSnapshot::snapshot($metrics,'venture-condor','product-condor');
}

$case=$argv[1]??'';
if($case==='scope'){
    $base=phMetric('metric-a','activation',0.4);
    $second=phMetric('metric-b','adoption',0.5);
    $out=[
        'good'=>ph([$base,$second]),
        'venture'=>phReject(fn()=>ph([$base,array_replace($second,['venture_id'=>'venture-other'])])),
        'product'=>phReject(fn()=>ph([$base,array_replace($second,['product_id'=>'product-other'])])),
        'surface'=>phReject(fn()=>ph([$base,array_replace($second,['surface'=>'mobile_app'])])),
        'period'=>phReject(fn()=>ph([$base,array_replace($second,['period'=>['start_at'=>1100,'end_at'=>2000]])])),
    ];
}elseif($case==='dimensions'){
    $out=[
        'good'=>ph([
            phMetric('metric-retention','retention',0.7,['source_ref'=>'aggregate:analytics/retention','evidence_ref'=>'evidence:product/retention']),
            phMetric('metric-activation','activation',0.4,['source_ref'=>'aggregate:analytics/activation','evidence_ref'=>'evidence:product/activation']),
        ]),
        'duplicate'=>phReject(fn()=>ph([
            phMetric('metric-a','activation',0.4),phMetric('metric-b','activation',0.5)
        ])),
    ];
}elseif($case==='reasons'){
    $out=ph([
        phMetric('metric-unknown','activation',null),
        phMetric('metric-insufficient','adoption',null,['status'=>'insufficient_data','sample_size'=>4,'freshness'=>'fresh','confidence'=>0.2]),
        phMetric('metric-stale','retention',0.6,['freshness'=>'stale']),
        phMetric('metric-inferred','satisfaction',0.8,['nature'=>'inferred','confidence'=>0.55]),
        phMetric('metric-observed','completion_rate',0.9),
    ]);
}elseif($case==='global'){
    $out=ph([
        phMetric('metric-fresh','activation',0.4),
        phMetric('metric-stale','retention',0.6,['freshness'=>'stale','nature'=>'inferred']),
        phMetric('metric-unknown','adoption',null),
    ]);
}elseif($case==='boundary'){
    $out=ph([phMetric('metric-activation','activation',0.4)]);
}elseif($case==='pure'){
    $r=new ReflectionClass(ProductHealthSnapshot::class);
    $methods=array_values(array_map(static fn(ReflectionMethod $m):string=>$m->getName(),array_filter(
        $r->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $m):bool=>$m->getDeclaringClass()->getName()===ProductHealthSnapshot::class
    )));
    sort($methods,SORT_STRING); $out=['methods'=>$methods];
}else{fwrite(STDERR,"Unknown product health scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

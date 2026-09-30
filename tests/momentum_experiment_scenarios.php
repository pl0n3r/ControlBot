<?php
declare(strict_types=1);

require __DIR__.'/../src/MomentumCampaign.php';
require __DIR__.'/../src/MomentumExperiment.php';

use ControlBot\Momentum\MomentumExperiment;
use InvalidArgumentException;

function brand426(string $venture='venture-condor'): array {
    return ['version'=>1,'brand_context_id'=>'brand:11111111111111111111111111111111',
        'venture_id'=>$venture,'tone_ref'=>'tone:22222222222222222222222222222222',
        'constraints'=>['brand-safe'],'source_ref'=>'source:33333333333333333333333333333333',
        'observed_at'=>1000];
}
function campaign426(string $venture='venture-condor'): array {
    return ['version'=>1,'campaign_id'=>'campaign:44444444444444444444444444444444',
        'venture_id'=>$venture,'brand_context_id'=>'brand:11111111111111111111111111111111',
        'objective'=>'acquisition','audience_ref'=>'audience:55555555555555555555555555555555',
        'offer_ref'=>'offer:66666666666666666666666666666666',
        'cta_ref'=>'cta:77777777777777777777777777777777','channels'=>['web','paid_social'],
        'creative_variant_refs'=>[
            'creative:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'creative:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'creative:cccccccccccccccccccccccccccccccc',
        ],'budget_ref'=>'budget:dddddddddddddddddddddddddddddddd',
        'schedule'=>['start_at'=>1100,'end_at'=>2200],
        'experiment_refs'=>['experiment:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'],
        'status'=>'planned','evidence_refs'=>['evidence:ffffffffffffffffffffffffffffffff'],
        'execution'=>false];
}
function unknownResult426(): array {
    return ['state'=>'unknown','winner_ref'=>null,'effect_bps'=>null,'source_ref'=>null,
        'observed_at'=>null,'freshness'=>'unknown','evidence_refs'=>[]];
}
function experiment426(string $status='ready',string $venture='venture-condor'): array {
    return ['version'=>1,'experiment_id'=>'experiment:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
        'venture_id'=>$venture,'campaign_id'=>'campaign:44444444444444444444444444444444',
        'hypothesis_ref'=>'hypothesis:11111111111111111111111111111111',
        'baseline_ref'=>'creative:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'variant_refs'=>['creative:cccccccccccccccccccccccccccccccc','creative:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
        'metric_ref'=>'metric:22222222222222222222222222222222',
        'window'=>['start_at'=>1200,'end_at'=>2000],'status'=>$status,
        'result'=>unknownResult426(),
        'evidence_refs'=>['evidence:33333333333333333333333333333333'],'execution'=>false];
}
function blocked426(callable $fn): bool {
    try{$fn();return false;}catch(InvalidArgumentException){return true;}
}

$case=$argv[1]??'';
if($case==='scope'){
    echo json_encode([
        'valid'=>MomentumExperiment::experiment(experiment426(),campaign426(),brand426()),
        'cross_venture'=>blocked426(fn()=>MomentumExperiment::experiment(
            experiment426('ready','venture-brvtal'),campaign426(),brand426())),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='readiness'){
    $missingBaseline=experiment426();$missingBaseline['baseline_ref']=null;
    $missingMetric=experiment426('running');$missingMetric['metric_ref']=null;
    $missingWindow=experiment426();$missingWindow['window']=['start_at'=>null,'end_at'=>null];
    echo json_encode([
        'ready'=>MomentumExperiment::experiment(experiment426(),campaign426(),brand426()),
        'running'=>MomentumExperiment::experiment(experiment426('running'),campaign426(),brand426()),
        'missing_baseline'=>blocked426(fn()=>MomentumExperiment::experiment($missingBaseline,campaign426(),brand426())),
        'missing_metric'=>blocked426(fn()=>MomentumExperiment::experiment($missingMetric,campaign426(),brand426())),
        'missing_window'=>blocked426(fn()=>MomentumExperiment::experiment($missingWindow,campaign426(),brand426())),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='result'){
    $observed=experiment426('completed');$observed['result']=[
        'state'=>'observed','winner_ref'=>'creative:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'effect_bps'=>750,'source_ref'=>'source:44444444444444444444444444444444',
        'observed_at'=>2050,'freshness'=>'current',
        'evidence_refs'=>['evidence:55555555555555555555555555555555']];
    $inferred=$observed;$inferred['result']['state']='inferred';$inferred['result']['effect_bps']=500;
    $unknown=experiment426('completed');
    echo json_encode([
        'observed'=>MomentumExperiment::experiment($observed,campaign426(),brand426()),
        'inferred'=>MomentumExperiment::experiment($inferred,campaign426(),brand426()),
        'unknown'=>MomentumExperiment::experiment($unknown,campaign426(),brand426()),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='invalid'){
    $duplicate=experiment426();$duplicate['variant_refs'][]=$duplicate['variant_refs'][0];
    $baseline=experiment426();$baseline['variant_refs'][]=$baseline['baseline_ref'];
    $window=experiment426();$window['window']=['start_at'=>2000,'end_at'=>1200];
    $extra=experiment426();$extra['provider']='meta';
    $sensitive=experiment426();$sensitive['metric_ref']='metric:token-supersecret';
    $foreign=experiment426();$foreign['variant_refs']=['creative:99999999999999999999999999999999'];
    echo json_encode([
        'duplicate'=>blocked426(fn()=>MomentumExperiment::experiment($duplicate,campaign426(),brand426())),
        'baseline_duplicate'=>blocked426(fn()=>MomentumExperiment::experiment($baseline,campaign426(),brand426())),
        'window'=>blocked426(fn()=>MomentumExperiment::experiment($window,campaign426(),brand426())),
        'extra'=>blocked426(fn()=>MomentumExperiment::experiment($extra,campaign426(),brand426())),
        'sensitive'=>blocked426(fn()=>MomentumExperiment::experiment($sensitive,campaign426(),brand426())),
        'foreign'=>blocked426(fn()=>MomentumExperiment::experiment($foreign,campaign426(),brand426())),
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='deterministic'){
    $a=MomentumExperiment::experiment(experiment426(),campaign426(),brand426());
    $raw=experiment426();$raw['variant_refs']=array_reverse($raw['variant_refs']);
    $b=MomentumExperiment::experiment($raw,campaign426(),brand426());
    echo json_encode(['first'=>$a,'second'=>$b],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($case==='pure'){
    $r=new ReflectionClass(MomentumExperiment::class);
    $methods=array_map(static fn(ReflectionMethod $m): string=>$m->getName(),
        $r->getMethods(ReflectionMethod::IS_PUBLIC));
    echo json_encode(['methods'=>$methods],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
fwrite(STDERR,"Unknown MOMENTUM experiment scenario\n");exit(2);

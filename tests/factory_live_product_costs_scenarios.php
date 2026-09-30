<?php
declare(strict_types=1);

require __DIR__.'/../src/FactoryLiveSnapshot.php';
require __DIR__.'/../src/ProductIntelligence.php';
require __DIR__.'/../src/FinanceCostCenter.php';
require __DIR__.'/../src/FactoryLiveCostSnapshot.php';
require __DIR__.'/../src/FactoryLiveUi.php';

use ControlBot\Business\FactoryLiveSnapshot;
use ControlBot\Business\FactoryLiveCostSnapshot;
use ControlBot\Business\FactoryLiveUi;

function baseSnapshot(): array
{
    return FactoryLiveSnapshot::build([
        'tool_usage'=>[
            'id'=>'tool:remote-desktop','authority'=>'tool_usage','state'=>'healthy',
            'source_ref'=>'controlbot:tools/remote-desktop','observed_at'=>2990,
            'freshness'=>'current','data'=>[
                'title'=>'Remote Desktop Commander','usage'=>4,'unit'=>'calls',
            ],
        ],
    ],3000);
}

function productMetric(array $overrides=[]): array
{
    return array_replace([
        'version'=>1,'metric_id'=>'metric-activation-web',
        'venture_id'=>'venture-condor','product_id'=>'product-condor',
        'surface'=>'web_app','category'=>'activation',
        'period'=>['start_at'=>1000,'end_at'=>2000],
        'status'=>'measured','value'=>0.42,'unit'=>'ratio',
        'source_ref'=>'aggregate:analytics/activation',
        'evidence_ref'=>'evidence:product/activation-2026w40',
        'sample_size'=>120,'freshness'=>'fresh',
        'confidence'=>0.96,'nature'=>'observed',
    ],$overrides);
}

function costAttribution(int $amount=1500000,array $overrides=[]): array
{
    return array_replace([
        'version'=>1,'attribution_id'=>'shared-ai-controlbot',
        'target'=>[
            'version'=>1,'kind'=>'institution_cost_center','id'=>'controlbot',
            'scope'=>'institution:controlbot','title'=>'ControlBot',
            'source_ref'=>'controlbot:finance/cost-center-controlbot',
            'observed_at'=>'2026-09-28T15:30:00Z','freshness'=>'fresh',
        ],
        'currency'=>'COP','amount_minor'=>$amount,
        'provenance_ref'=>'controlbot:finance/source-shared-ai',
        'source_ref'=>'controlbot:finance/attribution-shared-ai',
        'observed_at'=>'2026-09-28T15:31:00Z','freshness'=>'fresh',
    ],$overrides);
}

function built(array $products=[],array $costs=[]): array
{
    return FactoryLiveCostSnapshot::build(baseSnapshot(),$products,$costs);
}

$scenario=$argv[1]??'';
if($scenario==='full'){
    echo json_encode(
        built([productMetric()],[costAttribution()]),
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),PHP_EOL;exit;
}
if($scenario==='missing_cost'){
    echo json_encode(
        built([productMetric()],[]),
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),PHP_EOL;exit;
}
if($scenario==='zero_cost'){
    echo json_encode(
        built([productMetric()],[costAttribution(0)]),
        JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ),PHP_EOL;exit;
}
if($scenario==='secret'){
    $productRejected=false;
    try{
        built([productMetric(['source_ref'=>'aggregate:analytics/api_token'])],[]);
    }catch(Throwable){$productRejected=true;}
    $financeRejected=false;
    try{
        built([],[costAttribution(100,['source_ref'=>'controlbot:finance/api-token'])]);
    }catch(Throwable){$financeRejected=true;}
    echo json_encode([
        'product_secret_rejected'=>$productRejected,
        'finance_secret_rejected'=>$financeRejected,
    ],JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
if($scenario==='ui'){
    $snapshot=baseSnapshot();
    $view=FactoryLiveCostSnapshot::build(
        $snapshot,[productMetric()],[costAttribution(0)]
    );
    echo FactoryLiveUi::render($snapshot,null,null,$view),PHP_EOL;exit;
}
fwrite(STDERR,"scenario inválido\n");exit(2);

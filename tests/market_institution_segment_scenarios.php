<?php
declare(strict_types=1);

require __DIR__.'/../src/MarketScope.php';
require __DIR__.'/../src/MarketInstitutionSegment.php';

use ControlBot\Business\MarketInstitutionSegment;

const HEX_A='0123456789abcdef0123456789abcdef';
const HEX_B='11111111111111111111111111111111';
const HEX_C='22222222222222222222222222222222';

function rejected(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException){return true;} }

function segmentMarket(string $marketId='market-colombia',array $geography=['kind'=>'country','code'=>'CO'],array $overrides=[]): array
{
    return array_replace([
        'version'=>1,
        'market_id'=>$marketId,
        'venture_id'=>'venture-condor',
        'geography'=>$geography,
        'status'=>'live',
        'priority'=>1,
        'currencies'=>['COP'],
        'locales'=>['es-CO'],
        'source_ref'=>'source:market',
        'observed_at'=>1000,
        'freshness'=>'fresh',
    ],$overrides);
}

function institutionSignal(
    string $institution,
    string $hex,
    string $freshness='fresh',
    int|null $observedAt=2000,
    array $overrides=[]
): array {
    return array_replace([
        'institution'=>$institution,
        'venture_id'=>'venture-condor',
        'signal_ref'=>'controlbot:'.$institution.'/'.$hex,
        'evidence_refs'=>['controlbot:evidence/'.HEX_A],
        'observed_at'=>$observedAt,
        'freshness'=>$freshness,
    ],$overrides);
}

$case=$argv[1]??'';
if($case==='scoped'){
    $market=segmentMarket();
    $out=[
        'projection'=>MarketInstitutionSegment::project($market,[
            institutionSignal('momentum',HEX_B),
            institutionSignal('capital',HEX_C),
        ]),
        'reordered'=>MarketInstitutionSegment::project($market,[
            institutionSignal('capital',HEX_C),
            institutionSignal('momentum',HEX_B),
        ]),
        'cross_venture'=>rejected(fn()=>MarketInstitutionSegment::project(
            $market,
            [institutionSignal('capital',HEX_C,'fresh',2000,['venture_id'=>'venture-grindflow'])]
        )),
    ];
}elseif($case==='invalid'){
    $good=institutionSignal('momentum',HEX_B);
    $extra=$good; $extra['budget']=100;
    $duplicateEvidence=$good; $duplicateEvidence['evidence_refs']=[
        'controlbot:evidence/'.HEX_A,
        'controlbot:evidence/'.HEX_A,
    ];
    $out=[
        'namespace'=>rejected(fn()=>MarketInstitutionSegment::project(
            segmentMarket(),
            [institutionSignal('momentum',HEX_B,'fresh',2000,['signal_ref'=>'controlbot:capital/'.HEX_B])]
        )),
        'institution'=>rejected(fn()=>MarketInstitutionSegment::project(
            segmentMarket(),
            [institutionSignal('sales',HEX_B)]
        )),
        'duplicate_signal'=>rejected(fn()=>MarketInstitutionSegment::project(
            segmentMarket(),
            [$good,$good]
        )),
        'duplicate_evidence'=>rejected(fn()=>MarketInstitutionSegment::project(
            segmentMarket(),
            [$duplicateEvidence]
        )),
        'extra'=>rejected(fn()=>MarketInstitutionSegment::project(segmentMarket(),[$extra])),
    ];
}elseif($case==='markets'){
    $signal=[institutionSignal('capital',HEX_C)];
    $country=MarketInstitutionSegment::project(segmentMarket(),$signal);
    $mexico=MarketInstitutionSegment::project(
        segmentMarket('market-mexico',['kind'=>'country','code'=>'MX'],[
            'currencies'=>['MXN'],'locales'=>['es-MX']
        ]),
        $signal
    );
    $global=MarketInstitutionSegment::project(
        segmentMarket('market-global',['kind'=>'global','code'=>null],[
            'currencies'=>['USD'],'locales'=>['en-US']
        ]),
        $signal
    );
    $out=['country'=>$country,'mexico'=>$mexico,'global'=>$global];
}elseif($case==='freshness'){
    $out=[
        'projection'=>MarketInstitutionSegment::project(segmentMarket(),[
            institutionSignal('momentum',HEX_B,'stale',1500,[
                'evidence_refs'=>['controlbot:evidence/'.HEX_C,'controlbot:evidence/'.HEX_A]
            ]),
            institutionSignal('capital',HEX_C,'unknown',null,['evidence_refs'=>[]]),
        ]),
        'unknown_with_time'=>rejected(fn()=>MarketInstitutionSegment::project(
            segmentMarket(),
            [institutionSignal('capital',HEX_C,'unknown',1500)]
        )),
        'known_without_time'=>rejected(fn()=>MarketInstitutionSegment::project(
            segmentMarket(),
            [institutionSignal('capital',HEX_C,'fresh',null)]
        )),
    ];
}elseif($case==='authority'){
    $projection=MarketInstitutionSegment::project(segmentMarket(),[institutionSignal('momentum',HEX_B)]);
    $rejectedFields=[];
    foreach(['authority','policy','approval','budget','spend','forecast','revenue','campaign_payload','decision'] as $field){
        $candidate=institutionSignal('momentum',HEX_B);
        $candidate[$field]=$field==='budget'?100:'forbidden';
        $rejectedFields[$field]=rejected(fn()=>MarketInstitutionSegment::project(segmentMarket(),[$candidate]));
    }
    $out=['projection'=>$projection,'rejected'=>$rejectedFields];
}elseif($case==='pure'){
    $r=new ReflectionClass(MarketInstitutionSegment::class);
    $methods=array_values(array_map(
        static fn(ReflectionMethod $m): string=>$m->getName(),
        array_filter(
            $r->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn(ReflectionMethod $m): bool=>$m->getDeclaringClass()->getName()===MarketInstitutionSegment::class
        )
    ));
    sort($methods,SORT_STRING);
    $out=['methods'=>$methods,'projection'=>MarketInstitutionSegment::project(segmentMarket(),[])];
}else{
    fwrite(STDERR,"Unknown market institution segment scenario\n");
    exit(2);
}

echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

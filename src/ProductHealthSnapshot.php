<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class ProductHealthSnapshot
{
    public static function snapshot(array $metricsRaw,string $expectedVentureId,string $expectedProductId): array
    {
        if(!array_is_list($metricsRaw)||$metricsRaw===[]||count($metricsRaw)>32)
            throw new InvalidArgumentException('Product health metrics invalid.');

        $dimensions=[]; $seen=[]; $scope=null; $allReasons=[]; $globalFreshness='fresh';
        foreach($metricsRaw as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('Product health metric invalid.');
            $metric=ProductIntelligence::metric($raw,$expectedVentureId,$expectedProductId);
            $current=[
                'venture_id'=>$metric['venture_id'],'product_id'=>$metric['product_id'],
                'surface'=>$metric['surface'],'period'=>$metric['period'],
            ];
            if($scope===null) $scope=$current;
            elseif($current!==$scope) throw new InvalidArgumentException('Product health scope mismatch.');

            if(isset($seen[$metric['category']])) throw new InvalidArgumentException('Product health category duplicated.');
            $seen[$metric['category']]=true;

            $reasons=self::reasons($metric);
            $allReasons=array_merge($allReasons,$reasons);
            if($metric['freshness']==='unknown') $globalFreshness='unknown';
            elseif($metric['freshness']==='stale' && $globalFreshness!=='unknown') $globalFreshness='stale';

            $dimensions[]=[
                'category'=>$metric['category'],'status'=>$metric['status'],'value'=>$metric['value'],
                'unit'=>$metric['unit'],'sample_size'=>$metric['sample_size'],'freshness'=>$metric['freshness'],
                'confidence'=>$metric['confidence'],'nature'=>$metric['nature'],'source_ref'=>$metric['source_ref'],
                'evidence_ref'=>$metric['evidence_ref'],'reasons'=>$reasons,
            ];
        }

        usort($dimensions,static fn(array $a,array $b): int=>$a['category']<=>$b['category']);
        $allReasons=array_values(array_unique($allReasons)); sort($allReasons,SORT_STRING);

        return [
            'version'=>1,
            'venture_id'=>$scope['venture_id'],'product_id'=>$scope['product_id'],
            'surface'=>$scope['surface'],'period'=>$scope['period'],
            'freshness'=>$globalFreshness,'reasons'=>$allReasons,'dimensions'=>$dimensions,
        ];
    }

    private static function reasons(array $metric): array
    {
        $reasons=[];
        if($metric['status']==='unknown') $reasons[]='unknown';
        elseif($metric['status']==='insufficient_data') $reasons[]='insufficient_data';

        if($metric['freshness']==='unknown') $reasons[]='freshness_unknown';
        elseif($metric['freshness']==='stale') $reasons[]='stale';

        if($metric['nature']==='inferred') $reasons[]='inferred';
        if($metric['status']==='measured' && $metric['freshness']==='fresh' && $metric['nature']==='observed')
            $reasons[]='observed_fresh';

        sort($reasons,SORT_STRING);
        return $reasons;
    }
}

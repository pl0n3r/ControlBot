<?php
declare(strict_types=1);
namespace ControlBot\AutoFactory;
final class AutoFactoryLearningPolicy
{
    private const MIN_SAMPLES=20;
    private const MIN_CONFIDENCE=0.80;
    public static function build(array $state,int $now,bool $enabled=false): array
    {
        $contexts=[];
        foreach(($state['aggregates']??[]) as $context=>$actions){
            $best=null;
            foreach($actions as $action=>$row){
                $samples=(int)($row['samples']??0); if($samples<self::MIN_SAMPLES) continue;
                $rate=((int)($row['successes']??0))/$samples;
                $confidence=min(1.0,$samples/self::MIN_SAMPLES)*$rate;
                if($confidence<self::MIN_CONFIDENCE) continue;
                $candidate=['action'=>$action,'confidence'=>round($confidence,4),'successRate'=>round($rate,4),'samples'=>$samples,'averageDurationMs'=>(int)round(((int)$row['durationMs'])/$samples)];
                if($best===null||$candidate['successRate']>$best['successRate']||($candidate['successRate']===$best['successRate']&&$candidate['averageDurationMs']<$best['averageDurationMs'])) $best=$candidate;
            }
            if($best!==null){unset($best['averageDurationMs']);$contexts[$context]=$best;}
        }
        ksort($contexts);
        return ['schemaVersion'=>1,'policyVersion'=>$contexts===[]?0:$now,'issuedAt'=>$now,'expiresAt'=>$now+86400000,'enabled'=>$enabled,'rollbackVersion'=>0,'contexts'=>$contexts];
    }
}

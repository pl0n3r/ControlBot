<?php
declare(strict_types=1);
namespace ControlBot\AutoFactory;
final class AutoFactoryLearningPolicy
{
    private const MIN_SAMPLES=20;
    private const ACTIONS=['wait','retry','reload','stop_wait','open_replacement_chat'];
    private const MIN_CONFIDENCE=0.80;
    public static function build(array $state,int $now,bool $enabled=false): array
    {
        $contexts=[];
        foreach(($state['aggregates']??[]) as $context=>$actions){
            if(!is_string($context)||!is_array($actions)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$context)!==1) continue;
            $best=null;
            foreach($actions as $action=>$row){
                // Carried-over rows are untrusted; never promote unknown actions
                // or impossible metrics into a cross-installation policy.
                if(!is_string($action)||!in_array($action,self::ACTIONS,true)||!is_array($row)) continue;
                $samples=$row['samples']??null; $successes=$row['successes']??null;
                $duration=$row['durationMs']??null;
                if(!is_int($samples)||$samples<self::MIN_SAMPLES
                    || !is_int($successes)||$successes<0||$successes>$samples
                    || !is_int($duration)||$duration<0) continue;
                $rate=$successes/$samples;
                $confidence=min(1.0,$samples/self::MIN_SAMPLES)*$rate;
                if($confidence<self::MIN_CONFIDENCE) continue;
                $candidate=['action'=>$action,'confidence'=>round($confidence,4),'successRate'=>round($rate,4),'samples'=>$samples,'averageDurationMs'=>(int)round($duration/$samples)];
                if($best===null||$candidate['successRate']>$best['successRate']||($candidate['successRate']===$best['successRate']&&$candidate['averageDurationMs']<$best['averageDurationMs'])) $best=$candidate;
            }
            if($best!==null){unset($best['averageDurationMs']);$contexts[$context]=$best;}
        }
        ksort($contexts);
        return ['schemaVersion'=>1,'policyVersion'=>$contexts===[]?0:$now,'issuedAt'=>$now,'expiresAt'=>$now+86400000,'enabled'=>$enabled,'rollbackVersion'=>0,'contexts'=>(object)$contexts];
    }
}

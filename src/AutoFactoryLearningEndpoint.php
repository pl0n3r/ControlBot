<?php
declare(strict_types=1);
namespace ControlBot\AutoFactory;
use LogicException;
final class AutoFactoryLearningEndpoint
{
    public static function contract(): array
    {
        return ['enabled'=>false,'uploadPath'=>'/api/v1/autofactory/learning-events','policyPath'=>'/api/v1/autofactory/learning-policy','writeScope'=>'autofactory.learning.write','readScope'=>'autofactory.learning.read','maxBytes'=>16384];
    }
    public static function upload(array $batch,array $state,int $now,bool $authenticated,bool $gateEnabled=false): array
    {
        self::authorize($authenticated,$gateEnabled);
        return AutoFactoryLearningEvent::ingest($batch,$state,$now);
    }
    public static function snapshot(array $state,int $now,bool $authenticated,bool $gateEnabled=false,int $afterVersion=0): array
    {
        self::authorize($authenticated,$gateEnabled);
        $policy=AutoFactoryLearningPolicy::build($state,$now);
        if($afterVersion>0&&$policy['policyVersion']<=$afterVersion) return ['notModified'=>true,'policyVersion'=>$policy['policyVersion']];
        return $policy;
    }
    private static function authorize(bool $authenticated,bool $gateEnabled): void
    {
        if(!$authenticated) throw new LogicException('AutoFactory learning profile unauthorized.');
        if(!$gateEnabled) throw new LogicException('AutoFactory learning legal and security gate is closed.');
    }
}

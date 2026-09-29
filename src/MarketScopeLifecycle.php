<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class MarketScopeLifecycle
{
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function change(
        ?array $previousScope,
        array $nextScope,
        string $ventureId,
        string $actorRef,
        int $changedAt,
    ): array {
        $venture=self::venture($ventureId);
        $actor=self::actorRef($actorRef);
        if($changedAt<1) throw new InvalidArgumentException('changed_at invalid.');

        $before=$previousScope===null?null:MarketScope::scope($previousScope);
        $after=MarketScope::scope($nextScope);
        $beforeFingerprint=$before===null?null:self::fingerprint($before);
        $afterFingerprint=self::fingerprint($after);
        if($beforeFingerprint===$afterFingerprint)
            throw new InvalidArgumentException('MarketScope change is a no-op.');

        $eventHash=hash('sha256',json_encode(
            [$venture,$actor,$changedAt,$beforeFingerprint,$afterFingerprint],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES,
        ));

        return [
            'version'=>1,
            'event_ref'=>'controlbot:market-scope-change/'.$eventHash,
            'venture_id'=>$venture,
            'actor_ref'=>$actor,
            'changed_at'=>$changedAt,
            'before'=>[
                'state'=>$before===null?'tbd':'defined',
                'fingerprint'=>$beforeFingerprint,
            ],
            'after'=>[
                'state'=>'defined',
                'fingerprint'=>$afterFingerprint,
            ],
            'delta'=>self::delta($before,$after),
            'signals'=>[
                'lex_reassessment_required'=>true,
                'readiness_reassessment_required'=>true,
            ],
            'execution'=>false,
        ];
    }

    private static function delta(?array $before,array $after): array
    {
        return [
            'mode'=>self::scalarDelta($before['mode']??null,$after['mode']),
            'primary_country'=>self::scalarDelta($before['primary_country']??null,$after['primary_country']),
            'target_countries'=>self::listDelta($before['target_countries']??[],$after['target_countries']),
            'excluded_countries'=>self::listDelta($before['excluded_countries']??[],$after['excluded_countries']),
            'launch_countries'=>self::listDelta($before['launch_countries']??[],$after['launch_countries']),
            'expansion_candidates'=>self::listDelta($before['expansion_candidates']??[],$after['expansion_candidates']),
            'default_currency'=>self::scalarDelta($before['default_currency']??null,$after['default_currency']),
            'default_locale'=>self::scalarDelta($before['default_locale']??null,$after['default_locale']),
        ];
    }

    private static function scalarDelta(mixed $before,mixed $after): array
    {
        return ['from'=>$before,'to'=>$after,'changed'=>$before!==$after];
    }

    private static function listDelta(array $before,array $after): array
    {
        $added=array_values(array_diff($after,$before));
        $removed=array_values(array_diff($before,$after));
        sort($added,SORT_STRING); sort($removed,SORT_STRING);
        return ['added'=>$added,'removed'=>$removed];
    }

    private static function fingerprint(array $scope): string
    {
        return hash('sha256',json_encode($scope,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private static function venture(string $value): string
    {
        if(preg_match('/^venture-[a-z0-9][a-z0-9-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException('venture_id invalid.');
        return $value;
    }

    private static function actorRef(string $value): string
    {
        if(strlen($value)>120
            ||preg_match('#^controlbot:(?:identity|agent)/[a-z][a-z0-9._-]{1,79}$#D',$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1)
            throw new InvalidArgumentException('actor_ref invalid.');
        return $value;
    }
}

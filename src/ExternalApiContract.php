<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use InvalidArgumentException;

final class ExternalApiContract
{
    private const ROUTES = [
        'GET /api/v1/cockpit' => ['cockpit.read','owner.cockpit.read',false],
        'GET /api/v1/owner-inbox' => ['owner_inbox.read','owner.inbox.read',false],
        'GET /api/v1/owner-decisions/{decision_id}' => ['owner_decision.read','owner.decision.read',false],
        'POST /api/v1/owner-decisions/{decision_id}/decision' => ['owner_decision.decide','owner.decision.write',true],
    ];

    private const ERROR_CODES = [
        'invalid_request',
        'unauthenticated',
        'forbidden',
        'not_found',
        'conflict',
        'stale_state',
        'rate_limited',
        'internal_error',
    ];

    public static function catalog(): array
    {
        $out=[];
        foreach(self::ROUTES as $key=>$route) $out[$key]=self::route($route);
        return $out;
    }

    public static function operation(string $method, string $pathTemplate): array
    {
        $route=self::ROUTES[strtoupper($method).' '.$pathTemplate]??null;
        if($route===null) throw new InvalidArgumentException('External API operation invalid.');
        return self::route($route);
    }

    public static function requestIds(array $raw): array
    {
        self::fields($raw, ['request_id', 'correlation_id'], 'RequestIds');
        return [
            'request_id' => self::opaqueId($raw['request_id'], 'request_id'),
            'correlation_id' => self::opaqueId($raw['correlation_id'], 'correlation_id'),
        ];
    }

    public static function decisionMutation(array $raw): array
    {
        self::fields($raw, ['version', 'outcome', 'idempotency_key'], 'DecisionMutation');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('DecisionMutation version invalid.');
        }
        return [
            'version' => 1,
            'outcome' => self::enumValue($raw['outcome'], ['approve', 'reject'], 'outcome'),
            'idempotency_key' => self::idempotencyKey($raw['idempotency_key']),
        ];
    }

    public static function responseMeta(array $raw): array
    {
        self::fields($raw, [
            'version', 'request_id', 'correlation_id', 'generated_at', 'freshness',
        ], 'ResponseMeta');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('ResponseMeta version invalid.');
        }
        $ids=self::requestIds(['request_id'=>$raw['request_id'],'correlation_id'=>$raw['correlation_id']]);
        return ['version'=>1]+$ids+[
            'generated_at'=>self::timestamp($raw['generated_at'],'generated_at'),
            'freshness'=>self::freshness($raw['freshness']),
        ];
    }

    public static function error(array $raw): array
    {
        self::fields($raw, [
            'version', 'code', 'message', 'request_id', 'correlation_id',
        ], 'ApiError');
        if (($raw['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('ApiError version invalid.');
        }
        $ids=self::requestIds(['request_id'=>$raw['request_id'],'correlation_id'=>$raw['correlation_id']]);
        return ['version'=>1,'code'=>self::enumValue($raw['code'],self::ERROR_CODES,'error.code'),
            'message'=>self::text($raw['message'],'error.message',240)]+$ids;
    }

    private static function route(array $route): array
    {
        return ['operation_id'=>$route[0],'auth_scope'=>$route[1],'mutation'=>$route[2],'freshness_required'=>true];
    }

    private static function freshness(mixed $raw): array
    {
        self::fields($raw, ['state', 'observed_at', 'source_ref'], 'Freshness');
        $state = self::enumValue($raw['state'], ['current', 'stale', 'unknown'], 'freshness.state');

        if ($state === 'unknown') {
            if ($raw['observed_at'] !== null || $raw['source_ref'] !== null) {
                throw new InvalidArgumentException('Unknown freshness must not invent provenance.');
            }
            return ['state' => 'unknown', 'observed_at' => null, 'source_ref' => null];
        }

        return [
            'state' => $state,
            'observed_at' => self::timestamp($raw['observed_at'], 'freshness.observed_at'),
            'source_ref' => self::sourceRef($raw['source_ref']),
        ];
    }

    private static function opaqueId(mixed $value,string $label): string
    {
        self::must(is_string($value)&&preg_match('/^[a-f0-9]{32}$/D',$value)===1,$label.' invalid.');
        return $value;
    }

    private static function idempotencyKey(mixed $value): string
    {
        self::must(is_string($value)&&preg_match('/^[a-f0-9]{32,64}$/D',$value)===1,'idempotency_key invalid.');
        return $value;
    }

    private static function sourceRef(mixed $value): string
    {
        self::must(is_string($value)&&preg_match('#^controlbot:[a-z][a-z0-9._/-]{1,119}$#D',$value)===1,'freshness.source_ref invalid.');
        return $value;
    }

    private static function timestamp(mixed $value,string $label): int
    {
        self::must(is_int($value)&&$value>0,$label.' invalid.');
        return $value;
    }

    private static function text(mixed $value,string $label,int $max): string
    {
        $clean=is_string($value)?trim($value):'';
        $count=preg_match_all('/./us',$clean,$characters);
        self::must($clean!==''&&$count!==false&&$count<=$max&&preg_match('/[\x00-\x1f\x7f]/',$clean)!==1,$label.' invalid.');
        return $clean;
    }

    private static function enumValue(mixed $value,array $allowed,string $label): string
    {
        self::must(is_string($value)&&in_array($value,$allowed,true),$label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        self::must(is_array($row)&&!array_is_list($row),$label.' invalid.');
        $actual=array_fill_keys(array_keys($row),true);
        $wanted=array_fill_keys($expected,true);
        self::must(count($actual)===count($wanted)&&$actual==$wanted,$label.' fields invalid.');
    }

    private static function must(bool $condition,string $message): void
    {
        if(!$condition) throw new InvalidArgumentException($message);
    }
}

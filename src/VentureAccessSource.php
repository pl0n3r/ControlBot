<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

interface VentureAccessSource
{
    public function resolve(string $identityId, string $scope, string $capability, int $now): array;
}

final class VentureAccessSourceContract
{
    private const SENSITIVE = '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';

    public static function query(array $raw): array
    {
        self::fields($raw, ['identity_id','scope','capability'], 'VentureAccessQuery');
        return [
            'identity_id'=>self::id($raw['identity_id'], 'identity_id'),
            'scope'=>self::scope($raw['scope']),
            'capability'=>self::capability($raw['capability']),
        ];
    }

    public static function resolved(array $raw, array $query, int $now): array
    {
        if ($now < 1) throw new InvalidArgumentException('now invalid.');
        $query=self::query($query);
        self::fields($raw, ['identity','scope','active_policy_refs','grant'], 'ResolvedVentureAccess');
        if (!is_array($raw['identity']) || !is_array($raw['grant'])) {
            throw new InvalidArgumentException('Resolved venture access invalid.');
        }

        $identity=VentureIdentity::normalizeIdentity($raw['identity']);
        $grant=VentureIdentity::normalizeGrant($raw['grant'],$now);
        $scope=self::scope($raw['scope']);
        $policies=self::policies($raw['active_policy_refs']);

        if ($identity['identity_id']!==$query['identity_id']
            || $scope!==$query['scope']
            || $grant['identity_id']!==$identity['identity_id']
            || $grant['scope']!==$scope
            || $grant['capability']!==$query['capability']
            || !in_array($grant['policy_ref'],$policies,true)) {
            throw new InvalidArgumentException('Resolved venture access mismatch.');
        }

        $out=['identity'=>$identity,'scope'=>$scope,'active_policy_refs'=>$policies,'grant'=>$grant];
        self::secretFree($out);
        return $out;
    }

    private static function policies(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw===[] || count($raw)>50) {
            throw new InvalidArgumentException('active_policy_refs invalid.');
        }
        $set=[];
        foreach($raw as $ref){
            if (!is_string($ref) || preg_match('#^controlbot:policy/[a-z][a-z0-9._/-]{1,119}$#D',$ref)!==1) {
                throw new InvalidArgumentException('active_policy_refs invalid.');
            }
            $set[$ref]=true;
        }
        $out=array_keys($set); sort($out,SORT_STRING); return $out;
    }

    private static function id(mixed $v,string $label): string
    {
        if (!is_string($v) || preg_match('/^[a-z][a-z0-9._:-]{1,79}$/D',$v)!==1) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return $v;
    }

    private static function scope(mixed $v): string
    {
        if (!is_string($v) || preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D',$v)!==1) {
            throw new InvalidArgumentException('scope invalid.');
        }
        return $v;
    }

    private static function capability(mixed $v): string
    {
        if (!is_string($v) || strlen($v)>120
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D',$v)!==1) {
            throw new InvalidArgumentException('capability invalid.');
        }
        return $v;
    }

    private static function secretFree(mixed $v): void
    {
        if (is_array($v)) { foreach($v as $item) self::secretFree($item); return; }
        if (is_string($v) && preg_match(self::SENSITIVE,$v)===1) {
            throw new InvalidArgumentException('Resolved venture access contains sensitive material.');
        }
    }

    private static function fields(array $row,array $expected,string $label): void
    {
        if (array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row); sort($actual); sort($expected);
        if ($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

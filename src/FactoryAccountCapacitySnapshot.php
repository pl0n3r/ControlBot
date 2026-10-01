<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryAccountCapacitySnapshot
{
    private const STATUSES=['FRESH','STALE','UNKNOWN'];
    private const CONFIDENCE=['none','low','medium','high'];
    private const FRESHNESS=['current','stale','unknown'];
    private const ACCOUNT_FIELDS=[
        'account_alias','status','confidence','budget','sent','remaining',
        'limit_events','source_ref','observed_at','freshness',
    ];
    private const BUDGET_FIELDS=['limit','windowMs','minIntervalMs'];
    private const ALIAS='/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';
    private const REF='/^[A-Za-z0-9][A-Za-z0-9._:\/#@-]{0,239}$/D';
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';
    private const MAX_ACCOUNTS=50;

    public static function build(array $raw,int $now): array
    {
        if($now<1||!array_is_list($raw)||count($raw)>self::MAX_ACCOUNTS)
            throw new InvalidArgumentException('Account capacity input invalid.');

        $accounts=[];$seen=[];
        foreach($raw as $row){
            $account=self::account($row,$now);
            $alias=$account['accountAlias'];
            if(isset($seen[$alias]))throw new InvalidArgumentException('Account alias duplicated.');
            $seen[$alias]=true;$accounts[]=$account;
        }
        usort($accounts,static fn(array $a,array $b):int=>$a['accountAlias']<=>$b['accountAlias']);

        $states=array_column($accounts,'status');
        $status=$accounts===[]||in_array('UNKNOWN',$states,true)
            ?'UNKNOWN'
            :(in_array('STALE',$states,true)?'STALE':'FRESH');

        $canonical=[
            'version'=>1,'observed_at'=>$now,'status'=>$status,'accounts'=>$accounts,
        ];
        return $canonical+[
            'fingerprint'=>hash(
                'sha256',
                json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
            ),
        ];
    }

    public static function validate(array $view,int $now): array
    {
        self::fields($view,['version','observed_at','status','accounts','fingerprint'],'view');
        if($now<1||$view['version']!==1||$view['observed_at']!==$now
            ||!is_array($view['accounts'])||!array_is_list($view['accounts'])
            ||count($view['accounts'])>self::MAX_ACCOUNTS
            ||!is_string($view['fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$view['fingerprint'])!==1)
            throw new InvalidArgumentException('Account capacity view invalid.');
        $canonical=$view;unset($canonical['fingerprint']);
        $expected=hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        if(!hash_equals($expected,$view['fingerprint']))
            throw new InvalidArgumentException('Account capacity fingerprint mismatch.');

        $validated=[];$seen=[];
        foreach($view['accounts'] as $row){
            self::fields($row,['accountAlias','status','confidence','budget','sent','remaining','limitEvents','source_ref','observed_at','freshness','age_seconds'],'account view');
            $raw=[
                'account_alias'=>$row['accountAlias'],'status'=>$row['status'],'confidence'=>$row['confidence'],
                'budget'=>$row['budget'],'sent'=>$row['sent'],'remaining'=>$row['remaining'],
                'limit_events'=>$row['limitEvents'],'source_ref'=>$row['source_ref'],
                'observed_at'=>$row['observed_at'],'freshness'=>$row['freshness'],
            ];
            $candidate=self::account($raw,$now);
            if($candidate!==$row)throw new InvalidArgumentException('Account capacity row mismatch.');
            if(isset($seen[$candidate['accountAlias']]))throw new InvalidArgumentException('Account alias duplicated.');
            $seen[$candidate['accountAlias']]=true;$validated[]=$candidate;
        }
        $sorted=$validated;
        usort($sorted,static fn(array $a,array $b):int=>$a['accountAlias']<=>$b['accountAlias']);
        if($validated!==$sorted)throw new InvalidArgumentException('Account capacity order invalid.');
        $states=array_column($validated,'status');
        $status=$validated===[]||in_array('UNKNOWN',$states,true)
            ?'UNKNOWN':(in_array('STALE',$states,true)?'STALE':'FRESH');
        if($view['status']!==$status)throw new InvalidArgumentException('Account capacity aggregate invalid.');
        return $view;
    }

    private static function account(mixed $row,int $now): array
    {
        self::fields($row,self::ACCOUNT_FIELDS,'account');
        $alias=self::alias($row['account_alias']);
        $status=self::choice($row['status'],self::STATUSES,'status');
        $confidence=self::choice($row['confidence'],self::CONFIDENCE,'confidence');
        $freshness=self::choice($row['freshness'],self::FRESHNESS,'freshness');

        if($status==='UNKNOWN'){
            if($freshness!=='unknown'||$confidence!=='none'||$row['budget']!==null
                ||$row['sent']!==null||$row['remaining']!==null||$row['limit_events']!==null
                ||$row['source_ref']!==null||$row['observed_at']!==null)
                throw new InvalidArgumentException('Unknown capacity incoherent.');
            return self::project($alias,$status,$confidence,null,null,null,null,null,null,$freshness,null);
        }

        if(($status==='FRESH'&&$freshness!=='current')||($status==='STALE'&&$freshness!=='stale')
            ||$confidence==='none')
            throw new InvalidArgumentException('Capacity status/freshness incoherent.');

        $source=self::ref($row['source_ref']);
        $observed=self::time($row['observed_at'],$now);
        $budget=self::budget($row['budget']);
        $sent=self::nonnegative($row['sent'],'sent');
        $events=self::nonnegative($row['limit_events'],'limit_events');
        if($sent>$budget['limit'])throw new InvalidArgumentException('sent exceeds budget.');

        if($status==='FRESH'){
            $remaining=self::nonnegative($row['remaining'],'remaining');
            if($remaining!==$budget['limit']-$sent)
                throw new InvalidArgumentException('remaining mismatch.');
        }else{
            if($row['remaining']!==null)
                throw new InvalidArgumentException('Stale capacity cannot claim remaining quota.');
            $remaining=null;
        }

        return self::project(
            $alias,$status,$confidence,$budget,$sent,$remaining,$events,
            $source,$observed,$freshness,$now-$observed
        );
    }

    private static function project(
        string $alias,string $status,string $confidence,?array $budget,?int $sent,
        ?int $remaining,?int $events,?string $source,?int $observed,
        string $freshness,?int $age
    ): array {
        return [
            'accountAlias'=>$alias,'status'=>$status,'confidence'=>$confidence,
            'budget'=>$budget,'sent'=>$sent,'remaining'=>$remaining,
            'limitEvents'=>$events,'source_ref'=>$source,'observed_at'=>$observed,
            'freshness'=>$freshness,'age_seconds'=>$age,
        ];
    }

    private static function budget(mixed $value): array
    {
        self::fields($value,self::BUDGET_FIELDS,'budget');
        $limit=self::nonnegative($value['limit'],'budget.limit');
        $window=self::positive($value['windowMs'],'budget.windowMs');
        $interval=self::positive($value['minIntervalMs'],'budget.minIntervalMs');
        return ['limit'=>$limit,'windowMs'=>$window,'minIntervalMs'=>$interval];
    }

    private static function alias(mixed $value): string
    {
        if(!is_string($value)||preg_match(self::ALIAS,$value)!==1||str_contains($value,'@'))
            throw new InvalidArgumentException('Account alias invalid.');
        return $value;
    }

    private static function ref(mixed $value): string
    {
        if(!is_string($value)||preg_match(self::REF,$value)!==1
            ||preg_match(self::SENSITIVE,$value)===1||preg_match(self::PII,$value)===1)
            throw new InvalidArgumentException('Capacity source invalid.');
        return $value;
    }

    private static function time(mixed $value,int $now): int
    {
        if(!is_int($value)||$value<1||$value>$now)
            throw new InvalidArgumentException('Capacity observed_at invalid.');
        return $value;
    }

    private static function nonnegative(mixed $value, string $label): int
    {
        return self::integerAtLeast($value, 0, $label);
    }

    private static function positive(mixed $value, string $label): int
    {
        return self::integerAtLeast($value, 1, $label);
    }

    private static function integerAtLeast(mixed $value, int $minimum, string $label): int
    {
        if (!is_int($value) || $value < $minimum) {
            throw new InvalidArgumentException($label.' invalid.');
        }

        return $value;
    }

    private static function choice(mixed $value, array $allowed, string $label): string
    {
        $allowedSet = array_fill_keys($allowed, true);
        if (!is_string($value) || !array_key_exists($value, $allowedSet)) {
            throw new InvalidArgumentException($label.' invalid.');
        }

        return $value;
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label.' invalid.');
        }

        $actual = array_keys($row);
        $missing = array_diff($expected, $actual);
        $unexpected = array_diff($actual, $expected);
        if (count($actual) !== count($expected) || $missing !== [] || $unexpected !== []) {
            throw new InvalidArgumentException($label.' fields invalid.');
        }
    }
}

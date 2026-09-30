<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use ControlBot\Business\CapitalPolicy;
use ControlBot\Business\DecisionRights;
use InvalidArgumentException;

final class MomentumPaidMedia
{
    private const PAID_CHANNELS=['paid_social','search_ads'];
    private const FRESHNESS=['current','stale','unknown'];
    private const SENSITIVE='/(?:bearer\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{8,})/i';

    public static function plan(
        array $raw,
        array $brandRaw,
        array $rightsContext,
        array $grant,
        array $capitalInput,
        int $now,
    ): array {
        try {
            self::fields($raw,[
                'version','campaign','channel','capability','required_authority_level','spend',
                'secret_scope_ref','freshness','observed_at','reallocation','execution',
            ],'PaidMediaPlan');
            if($raw['version']!==1||$raw['execution']!==false||$now<1)
                throw new InvalidArgumentException('PaidMediaPlan version or execution invalid.');

            $campaign=MomentumCampaign::campaign($raw['campaign'],$brandRaw);
            $channel=self::choice($raw['channel'],self::PAID_CHANNELS,'channel');
            if(!in_array($channel,$campaign['channels'],true))
                throw new InvalidArgumentException('Paid channel not present in campaign.');

            $ventureScope=self::ventureScope($campaign['venture_id']);
            if(($rightsContext['scope']??null)!==$ventureScope||($capitalInput['scope']??null)!==$ventureScope)
                throw new InvalidArgumentException('Paid media venture scope mismatch.');

            $spend=self::spend($raw['spend']);
            if($spend['budget_ref']!==$campaign['budget_ref'])
                throw new InvalidArgumentException('Campaign budget_ref mismatch.');
            if(($capitalInput['currency']??null)!==$spend['currency']
                ||($capitalInput['proposal']['amount_minor']??null)!==$spend['amount_minor'])
                throw new InvalidArgumentException('CAPITAL proposal mismatch.');

            $freshness=self::choice($raw['freshness'],self::FRESHNESS,'freshness');
            $observedAt=self::natural($raw['observed_at'],'observed_at');
            $secretScope=self::opaque($raw['secret_scope_ref'],'secret_scope_ref','scope');
            $reallocation=self::reallocation($raw['reallocation'],$spend['currency']);

            $capability=self::slug($raw['capability'],'capability');
            $required=self::authority($raw['required_authority_level']);
            $rights=DecisionRights::evaluate($rightsContext,$grant,[
                'capability'=>$capability,
                'required_authority_level'=>$required,
                'budget_amount'=>(float)$spend['amount_minor'],
            ],$now);
            $capital=CapitalPolicy::evaluate($capitalInput,$now);
        } catch(InvalidArgumentException) {
            return self::failed(['invalid_input']);
        }

        if($freshness!=='current') return self::result(
            $campaign,$channel,$spend,$secretScope,$freshness,$observedAt,$reallocation,$rights,$capital,
            'denied',['evidence_'.$freshness]
        );
        if($rights['decision']==='deny') return self::result(
            $campaign,$channel,$spend,$secretScope,$freshness,$observedAt,$reallocation,$rights,$capital,
            'denied',['authority_denied',...$rights['reasons']]
        );
        if($capital['decision']==='deny') return self::result(
            $campaign,$channel,$spend,$secretScope,$freshness,$observedAt,$reallocation,$rights,$capital,
            'denied',['capital_denied',...$capital['reasons']]
        );

        $owner=[];
        if($rights['decision']==='owner_decision_required') $owner[]='authority_owner_gate';
        if($capital['decision']==='owner_decision_required') $owner[]='capital_owner_gate';
        if($reallocation!==null&&$reallocation['proposed_total_minor']>$reallocation['current_total_minor'])
            $owner[]='spend_expansion_requires_owner';
        if($reallocation!==null&&$reallocation['proposed_total_minor']>$reallocation['limit_minor'])
            $owner[]='reallocation_limit_exceeded';

        return self::result(
            $campaign,$channel,$spend,$secretScope,$freshness,$observedAt,$reallocation,$rights,$capital,
            $owner===[]?'planned':'owner_decision_required',
            $owner===[]?['governance_satisfied']:array_values(array_unique($owner))
        );
    }

    private static function result(
        array $campaign,string $channel,array $spend,string $secretScope,string $freshness,int $observedAt,
        ?array $reallocation,array $rights,array $capital,string $status,array $reasons,
    ): array {
        return [
            'version'=>1,
            'campaign_id'=>$campaign['campaign_id'],
            'venture_id'=>$campaign['venture_id'],
            'channel'=>$channel,
            'budget_ref'=>$spend['budget_ref'],
            'spend'=>['amount_minor'=>$spend['amount_minor'],'currency'=>$spend['currency']],
            'secret_scope_ref'=>$secretScope,
            'freshness'=>$freshness,
            'observed_at'=>$observedAt,
            'reallocation'=>$reallocation,
            'authority'=>['decision'=>$rights['decision'],'reasons'=>$rights['reasons']],
            'capital'=>['decision'=>$capital['decision'],'reasons'=>$capital['reasons']],
            'status'=>$status,
            'reasons'=>$reasons,
            'execution'=>false,
        ];
    }

    private static function failed(array $reasons): array
    {
        return ['version'=>1,'status'=>'denied','reasons'=>$reasons,'execution'=>false];
    }

    private static function spend(mixed $raw): array
    {
        self::fields($raw,['amount_minor','currency','budget_ref'],'Spend');
        return [
            'amount_minor'=>self::positive($raw['amount_minor'],'amount_minor'),
            'currency'=>self::currency($raw['currency']),
            'budget_ref'=>self::opaque($raw['budget_ref'],'budget_ref','budget'),
        ];
    }

    private static function reallocation(mixed $raw,string $currency): ?array
    {
        if($raw===null) return null;
        self::fields($raw,['currency','current_total_minor','proposed_total_minor','limit_minor'],'Reallocation');
        if(self::currency($raw['currency'])!==$currency) throw new InvalidArgumentException('Reallocation currency mismatch.');
        return [
            'currency'=>$currency,
            'current_total_minor'=>self::natural($raw['current_total_minor'],'current_total_minor'),
            'proposed_total_minor'=>self::natural($raw['proposed_total_minor'],'proposed_total_minor'),
            'limit_minor'=>self::natural($raw['limit_minor'],'limit_minor'),
        ];
    }

    private static function ventureScope(mixed $venture): string
    {
        if(!is_string($venture)||preg_match('/^venture-([a-z][a-z0-9-]{1,55})$/D',$venture,$m)!==1)
            throw new InvalidArgumentException('venture_id invalid.');
        return 'venture:'.$m[1];
    }

    private static function opaque(mixed $value,string $label,string $namespace): string
    {
        if(!is_string($value)||preg_match(self::SENSITIVE,$value)===1
            ||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match(self::SENSITIVE,$value)===1
            ||preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function authority(mixed $value): string
    {
        $allowed=['L0_AI_AUTONOMOUS','L1_OPERATOR','L2_VENTURE_ADMIN','L3_GROUP_INSTITUTION','L4_OWNER'];
        return self::choice($value,$allowed,'required_authority_level');
    }

    private static function currency(mixed $value): string
    {
        if(!is_string($value)||preg_match('/^[A-Z]{3}$/D',$value)!==1)
            throw new InvalidArgumentException('currency invalid.');
        return $value;
    }

    private static function positive(mixed $value,string $label): int
    {
        $value=self::natural($value,$label);
        if($value<1||$value>1_000_000_000_000_000) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function natural(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row); sort($actual); sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

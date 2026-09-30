<?php
declare(strict_types=1);

namespace ControlBot\Momentum;

use ControlBot\Business\CapitalPolicy;
use ControlBot\Business\DecisionRights;
use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class MomentumPaidMedia
{
    private const CAPABILITY='momentum.paid_media.plan';
    private const POLICY_REF='controlbot:policy/business-os-v1';
    private const MAX_EVIDENCE_AGE=300;
    private const PAID_CHANNELS=['paid_social','search_ads'];
    private const OPERATIONS=[
        'launch'=>'L2_VENTURE_ADMIN',
        'pause'=>'L2_VENTURE_ADMIN',
        'reallocate'=>'L2_VENTURE_ADMIN',
    ];
    private const FRESHNESS=['current','stale','unknown'];
    private const SENSITIVE='/(?:bearer\s+|password|passwd|token|secret|cookie|authorization|private[_ -]?key|api[_ -]?key|dsn|(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{8,})/i';

    public static function plan(
        array $raw,
        array $brandRaw,
        mixed $verifiedContext,
        array $capitalInput,
        int $now,
    ): array {
        if(!$verifiedContext instanceof VerifiedAccessContext || $now<1)
            return self::failed(['verified_access_context_required']);

        try {
            self::fields($raw,[
                'version','campaign','channel','operation','spend','secret_scope_ref',
                'freshness','observed_at','reallocation','execution',
            ],'PaidMediaPlan');
            if($raw['version']!==1||$raw['execution']!==false)
                throw new InvalidArgumentException('PaidMediaPlan version or execution invalid.');

            $campaign=MomentumCampaign::campaign($raw['campaign'],$brandRaw);
            $channel=self::choice($raw['channel'],self::PAID_CHANNELS,'channel');
            if(!in_array($channel,$campaign['channels'],true))
                throw new InvalidArgumentException('Paid channel not present in campaign.');

            $operation=self::choice($raw['operation'],array_keys(self::OPERATIONS),'operation');
            $required=self::OPERATIONS[$operation];
            $context=$verifiedContext->decisionContext();
            $grant=$verifiedContext->grant();
            $ventureScope=self::ventureScope($campaign['venture_id']);
            if(($context['scope']??null)!==$ventureScope||($capitalInput['scope']??null)!==$ventureScope)
                throw new InvalidArgumentException('Paid media venture scope mismatch.');
            if(!in_array(self::POLICY_REF,$context['active_policy_refs']??[],true)
                ||($grant['policy_ref']??null)!==self::POLICY_REF)
                throw new InvalidArgumentException('Paid media policy mismatch.');

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
            if(($operation==='reallocate')!==($reallocation!==null))
                throw new InvalidArgumentException('Paid media reallocation operation mismatch.');

            $rights=DecisionRights::evaluate($context,$grant,[
                'capability'=>self::CAPABILITY,
                'required_authority_level'=>$required,
                'budget_amount'=>(float)$spend['amount_minor'],
            ],$now);
            $capital=CapitalPolicy::evaluate($capitalInput,$now);
        } catch(InvalidArgumentException) {
            return self::failed(['invalid_input']);
        }

        $temporal=self::freshnessReason($freshness,$observedAt,$now);
        if($temporal!==null) return self::result(
            $campaign,$channel,$operation,$required,$spend,$secretScope,$freshness,$observedAt,$reallocation,
            $rights,$capital,'denied',[$temporal]
        );
        if($rights['decision']==='deny') return self::result(
            $campaign,$channel,$operation,$required,$spend,$secretScope,$freshness,$observedAt,$reallocation,
            $rights,$capital,'denied',['authority_denied',...$rights['reasons']]
        );
        if($capital['decision']==='deny') return self::result(
            $campaign,$channel,$operation,$required,$spend,$secretScope,$freshness,$observedAt,$reallocation,
            $rights,$capital,'denied',['capital_denied',...$capital['reasons']]
        );

        $owner=[];
        if($rights['decision']==='owner_decision_required') $owner[]='authority_owner_gate';
        if($capital['decision']==='owner_decision_required') $owner[]='capital_owner_gate';
        if($reallocation!==null&&$reallocation['proposed_total_minor']>$reallocation['current_total_minor'])
            $owner[]='spend_expansion_requires_owner';
        if($reallocation!==null&&$reallocation['proposed_total_minor']>$reallocation['limit_minor'])
            $owner[]='reallocation_limit_exceeded';

        return self::result(
            $campaign,$channel,$operation,$required,$spend,$secretScope,$freshness,$observedAt,$reallocation,
            $rights,$capital,$owner===[]?'planned':'owner_decision_required',
            $owner===[]?['governance_satisfied']:array_values(array_unique($owner))
        );
    }

    private static function result(
        array $campaign,string $channel,string $operation,string $required,array $spend,string $secretScope,
        string $freshness,int $observedAt,?array $reallocation,array $rights,array $capital,string $status,array $reasons,
    ): array {
        return [
            'version'=>1,
            'campaign_id'=>$campaign['campaign_id'],
            'venture_id'=>$campaign['venture_id'],
            'channel'=>$channel,
            'operation'=>$operation,
            'spend'=>$spend,
            'secret_scope_ref'=>$secretScope,
            'freshness'=>$freshness,
            'observed_at'=>$observedAt,
            'reallocation'=>$reallocation,
            'authority'=>[
                'capability'=>self::CAPABILITY,
                'required_authority_level'=>$required,
                'decision'=>$rights['decision'],
                'reasons'=>$rights['reasons'],
            ],
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

    private static function freshnessReason(string $freshness,int $observedAt,int $now): ?string
    {
        if($freshness!=='current') return 'evidence_'.$freshness;
        if($observedAt>$now) return 'evidence_from_future';
        if($now-$observedAt>self::MAX_EVIDENCE_AGE) return 'evidence_expired';
        return null;
    }

    private static function spend(mixed $raw): array
    {
        self::fields($raw,[
            'spend_ref','amount_minor','currency','budget_ref','evidence_refs','blast_radius_ref',
        ],'Spend');
        return [
            'spend_ref'=>self::opaque($raw['spend_ref'],'spend_ref','spend'),
            'amount_minor'=>self::positive($raw['amount_minor'],'amount_minor'),
            'currency'=>self::currency($raw['currency']),
            'budget_ref'=>self::opaque($raw['budget_ref'],'budget_ref','budget'),
            'evidence_refs'=>self::refs($raw['evidence_refs'],'evidence',32),
            'blast_radius_ref'=>self::opaque($raw['blast_radius_ref'],'blast_radius_ref','blast-radius'),
        ];
    }

    private static function reallocation(mixed $raw,string $currency): ?array
    {
        if($raw===null) return null;
        self::fields($raw,['currency','current_total_minor','proposed_total_minor','limit_minor'],'Reallocation');
        if(self::currency($raw['currency'])!==$currency)
            throw new InvalidArgumentException('Reallocation currency mismatch.');
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
        if(!is_string($value)||preg_match('/^'.preg_quote($namespace,'/').':[a-f0-9]{32}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function refs(mixed $values,string $namespace,int $max): array
    {
        if(!is_array($values)||!array_is_list($values)||$values===[]||count($values)>$max)
            throw new InvalidArgumentException($namespace.' refs invalid.');
        $refs=array_map(static fn(mixed $value): string=>self::opaque($value,$namespace.'_ref',$namespace),$values);
        if(count(array_unique($refs,SORT_STRING))!==count($refs))
            throw new InvalidArgumentException($namespace.' refs duplicated.');
        sort($refs,SORT_STRING);
        return $refs;
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
        if($value<1||$value>1_000_000_000_000_000)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function natural(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true))
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function fields(mixed $row,array $expected,string $label): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row); sort($actual); sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

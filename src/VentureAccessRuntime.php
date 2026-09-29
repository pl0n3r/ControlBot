<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class VentureAccessRuntime
{
    private const REQUEST_FIELDS = ['version','command_id','idempotency_key','operation','reason_code','expires_at','payload'];
    private const CONTEXT_FIELDS = ['identity','scope','active_policy_refs','grant','budget_guard','production_authority'];
    private const RESTRICTION_DECISIONS = ['allow','deny','owner_decision_required'];

    public static function verifiedContext(
        VentureAccessSource $source,
        array $query,
        int $now,
    ): VerifiedAccessContext {
        return VerifiedAccessContext::fromServerSource($source, $query, $now);
    }

    public static function execute(array $state, array $request, array $trusted, int $now): array
    {
        self::fields($request,self::REQUEST_FIELDS,'LifecycleRequest');
        self::fields($trusted,self::CONTEXT_FIELDS,'TrustedAccessContext');
        if (!is_array($trusted['identity']) || !is_array($trusted['grant'])) {
            throw new InvalidArgumentException('Trusted access context invalid.');
        }
        $identity=VentureIdentity::normalizeIdentity($trusted['identity']);
        $budget=self::restriction($trusted['budget_guard'],'budget_guard');
        $production=self::restriction($trusted['production_authority'],'production_authority');
        $command=IdentityCenter::normalizeCommand([
            'version'=>$request['version'],'command_id'=>$request['command_id'],
            'idempotency_key'=>$request['idempotency_key'],'operation'=>$request['operation'],
            'actor_identity_id'=>$identity['identity_id'],'reason_code'=>$request['reason_code'],
            'scope'=>$trusted['scope'],'expires_at'=>$request['expires_at'],'payload'=>$request['payload'],
        ],$now);
        $context=['identity'=>$identity,'scope'=>$trusted['scope'],'active_policy_refs'=>$trusted['active_policy_refs']];

        try {
            $core=IdentityCenter::execute($state,$command,$context,$trusted['grant'],$now);
        } catch (InvalidArgumentException) {
            return self::result('deny',$state,$command,'deny','lifecycle_denied',$now,null,'deny');
        }

        if ($core['status'] === 'already_applied') {
            $event=$core['audit_event'];
            return self::result('already_applied',$core['state'],$command,self::kind($command['operation']),
                $event['reason_code'],$event['occurred_at'],null,$event['outcome']);
        }
        if ($core['status'] !== 'applied') {
            $event=$core['audit_event'];
            $reason=$event['reason_code'];
            $gate=$core['status']==='owner_decision_required' ? self::ownerGate($command,$reason) : null;
            return self::result($core['status'],$core['state'],$command,
                $core['status']==='owner_decision_required'?'override':'deny',
                $reason,$event['occurred_at'],$gate,$event['outcome']);
        }

        $restriction=self::mostRestrictive($budget,$production);
        if ($restriction['decision'] !== 'allow') {
            $blocked=self::restrictedState($state,$core['audit_event'],$restriction);
            $gate=$restriction['decision']==='owner_decision_required'
                ? self::ownerGate($command,$restriction['reason_code']) : null;
            return self::result($restriction['decision'],$blocked,$command,
                $restriction['decision']==='owner_decision_required'?'override':'deny',
                $restriction['reason_code'],$now,$gate,$restriction['decision']);
        }

        return self::result('applied',$core['state'],$command,self::kind($command['operation']),'authorized',$now,null,'applied');
    }

    private static function restriction(mixed $raw,string $label): array
    {
        if (!is_array($raw)) throw new InvalidArgumentException($label.' invalid.');
        self::fields($raw,['decision','reason_code'],$label);
        if (!is_string($raw['decision']) || !in_array($raw['decision'],self::RESTRICTION_DECISIONS,true)
            || !is_string($raw['reason_code']) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$raw['reason_code'])!==1) {
            throw new InvalidArgumentException($label.' invalid.');
        }
        return ['decision'=>$raw['decision'],'reason_code'=>$raw['reason_code']];
    }

    private static function mostRestrictive(array $budget,array $production): array
    {
        foreach (['deny','owner_decision_required'] as $decision) {
            foreach ([$production,$budget] as $restriction) {
                if ($restriction['decision']===$decision) return $restriction;
            }
        }
        return ['decision'=>'allow','reason_code'=>'external_guards_allow'];
    }

    private static function ownerGate(array $command,string $reason): string
    {
        $scope=$command['scope']; $id=$command['command_id'];
        $payload=[
            'category'=>'product-direction',
            'context'=>"Venture access command {$id} in {$scope} requires an Owner Decision.",
            'options'=>[
                ['id'=>'A','label'=>'Approve a follow-up owner-authorized command','effect'=>'No current mutation; approval authorizes a separately validated follow-up.','risk'=>'medium','reversible'=>true],
                ['id'=>'B','label'=>'Keep current access unchanged','effect'=>'No access mutation is performed.','risk'=>'low','reversible'=>true],
            ],
            'recommendation'=>'B','safe_default'=>'B',
            'title_simple'=>'Venture access escalation',
            'summary_simple'=>"Command {$id} requires owner authority before access can change.",
            'why_recommended'=>'The safe default preserves current access.',
            'blocks'=>"Venture access command {$id}.",
        ];
        return '<!-- factory-human-gate '.json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).' -->'
            ."\n<!-- venture-access-owner-decision ".json_encode(['command_id'=>$id,'scope'=>$scope,'reason_code'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).' -->';
    }

    private static function restrictedState(array $state,array $event,array $restriction): array
    {
        $event['outcome']=$restriction['decision'];
        $event['reason_code']=$restriction['reason_code'];
        $state['audit'][]=$event;
        return $state;
    }

    private static function result(string $status,array $state,array $command,string $kind,string $reason,int $now,?string $gate,string $auditOutcome): array
    {
        return [
            'status'=>$status,'state'=>$state,'owner_decision_gate'=>$gate,
            'audit'=>[
                'event_id'=>'venture-access-'.$command['command_id'],
                'command_id'=>$command['command_id'],'actor_identity_id'=>$command['actor_identity_id'],
                'scope'=>$command['scope'],'operation'=>$command['operation'],'kind'=>$kind,
                'outcome'=>$auditOutcome,'reason_code'=>$reason,'occurred_at'=>$now,
            ],
        ];
    }

    private static function kind(string $operation): string
    {
        return match($operation) {
            'grant_scope'=>'grant','revoke_scope'=>'revoke',default=>'mutation',
        };
    }

    private static function fields(array $row,array $expected,string $label): void
    {
        if (array_is_list($row)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($row); sort($actual); sort($expected);
        if ($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
    }
}

<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class IdentityCenter
{
    private const OPS = ['invite','create','suspend','reactivate','grant_scope','revoke_scope','change_role','change_capability','request_reauth','request_reset','set_mfa_required'];
    private const REQUESTS = ['invite','reauth','reset'];
    private const MFA = ['unknown','required','satisfied'];
    private const OUTCOMES = ['applied','deny','owner_decision_required'];

    public static function normalizeCommand(array $raw, int $now): array
    {
        self::positive($now, 'now'); self::noSecrets($raw); $raw += ['expires_at'=>null];
        self::fields($raw, ['version','command_id','idempotency_key','operation','actor_identity_id','reason_code','scope','expires_at','payload'], 'IdentityCommand');
        if (($raw['version'] ?? null) !== 1) throw new InvalidArgumentException('IdentityCommand version invalid.');
        $op = self::oneOf($raw['operation'], self::OPS, 'operation');
        $expires = self::nullableTime($raw['expires_at'], 'expires_at');
        if ($expires !== null && $expires <= $now) throw new InvalidArgumentException('expires_at invalid.');
        return [
            'version'=>1, 'command_id'=>self::key($raw['command_id'],'command_id'),
            'idempotency_key'=>self::key($raw['idempotency_key'],'idempotency_key'),
            'operation'=>$op, 'actor_identity_id'=>self::id($raw['actor_identity_id'],'actor_identity_id'),
            'reason_code'=>self::reason($raw['reason_code']), 'scope'=>self::scope($raw['scope']),
            'expires_at'=>$expires, 'payload'=>self::payload($op,$raw['payload'],$now),
        ];
    }

    public static function execute(array $state, array $command, array $context, array $decisionGrant, int $now): array
    {
        $state = self::state($state,$now); $command = self::normalizeCommand($command,$now);
        self::fields($context, ['identity','scope','active_policy_refs'], 'VerifiedContext');
        if (!is_array($context['identity'])) throw new InvalidArgumentException('verified identity invalid.');
        $actor = VentureIdentity::normalizeIdentity($context['identity']);
        if ($actor['identity_id'] !== $command['actor_identity_id'] || $context['scope'] !== $command['scope']) {
            throw new InvalidArgumentException('verified actor/scope mismatch.');
        }
        foreach ($state['audit'] as $event) {
            if ($event['idempotency_key'] !== $command['idempotency_key']) continue;
            if ($event['command_id'] !== $command['command_id']) throw new InvalidArgumentException('idempotency collision.');
            return ['status'=>'already_applied','state'=>$state,'audit_event'=>$event];
        }

        $target = self::target($state,$command);
        $decision = DecisionRights::evaluate($context,$decisionGrant,[
            'capability'=>self::capabilityFor($command['operation']),
            'required_authority_level'=>self::requiredAuthority($command),
            'budget_amount'=>null,
        ],$now);
        if ($decision['decision'] !== 'allow') {
            return self::finish($state,$command,$target,$decision['decision'],$now);
        }
        return self::finish(self::apply($state,$command,$target,$now),$command,$target,'applied',$now);
    }

    private static function apply(array $state, array $command, string $target, int $now): array
    {
        $op=$command['operation']; $payload=$command['payload'];
        if ($op === 'invite') return self::request($state,$command,$target,'invite',$now);
        if ($op === 'create') {
            if ($state['identity'] !== null || $payload['identity']['observed_at'] > $now) throw new InvalidArgumentException('create invalid.');
            $state['identity']=$payload['identity']; return $state;
        }
        $identity=self::identity($state);
        if ($op === 'suspend' || $op === 'reactivate') {
            $identity['state']=$op === 'suspend' ? 'suspended' : 'active';
            $state['identity']=VentureIdentity::normalizeIdentity($identity); return $state;
        }
        if ($op === 'request_reauth' || $op === 'request_reset') {
            return self::request($state,$command,$target,$op === 'request_reauth' ? 'reauth' : 'reset',$now);
        }
        if ($op === 'set_mfa_required') { $state['mfa']=$payload; return $state; }
        if ($op === 'grant_scope') {
            $grant=$payload['grant'];
            if ($grant['identity_id'] !== $identity['identity_id'] || $grant['scope'] !== $command['scope']) throw new InvalidArgumentException('grant attribution mismatch.');
            foreach ($state['grants'] as $row) if ($row['grant_id'] === $grant['grant_id']) throw new InvalidArgumentException('grant duplicated.');
            $state['grants'][]=$grant; usort($state['grants'],self::grantOrder(...)); return $state;
        }

        $found=false; $next=[];
        foreach ($state['grants'] as $grant) {
            if ($grant['grant_id'] !== $payload['grant_id']) { $next[]=$grant; continue; }
            if ($grant['identity_id'] !== $identity['identity_id'] || $grant['scope'] !== $command['scope']) throw new InvalidArgumentException('grant attribution mismatch.');
            $found=true;
            if ($op === 'revoke_scope') continue;
            if ($op === 'change_role') $grant['role']=$payload['role'];
            if ($op === 'change_capability') $grant['capability']=$payload['capability'];
            $next[]=VentureIdentity::normalizeGrant($grant,$now);
        }
        if (!$found) throw new InvalidArgumentException('grant not found.');
        $state['grants']=$next; return $state;
    }

    private static function payload(string $op, mixed $payload, int $now): array
    {
        if (!is_array($payload)) throw new InvalidArgumentException('payload invalid.');
        if ($op === 'invite') {
            self::fields($payload,['identity_id','kind','display_name','source_ref'],'invite payload');
            return ['candidate'=>VentureIdentity::normalizeIdentity([
                'version'=>1,'identity_id'=>$payload['identity_id'],'kind'=>$payload['kind'],'display_name'=>$payload['display_name'],
                'state'=>'active','source_ref'=>$payload['source_ref'],'observed_at'=>$now,
            ])];
        }
        if ($op === 'create') {
            self::fields($payload,['identity'],'create payload');
            if (!is_array($payload['identity'])) throw new InvalidArgumentException('identity invalid.');
            return ['identity'=>VentureIdentity::normalizeIdentity($payload['identity'])];
        }
        if ($op === 'grant_scope') {
            self::fields($payload,['grant'],'grant payload');
            if (!is_array($payload['grant'])) throw new InvalidArgumentException('grant invalid.');
            return ['grant'=>VentureIdentity::normalizeGrant($payload['grant'],$now)];
        }
        if (in_array($op,['revoke_scope','change_role','change_capability'],true)) {
            $fields=$op === 'revoke_scope' ? ['grant_id'] : ['grant_id',$op === 'change_role' ? 'role' : 'capability'];
            self::fields($payload,$fields,$op.' payload'); $out=['grant_id'=>self::id($payload['grant_id'],'grant_id')];
            if ($op === 'change_role') $out['role']=self::key($payload['role'],'role');
            if ($op === 'change_capability') $out['capability']=self::capability($payload['capability']);
            return $out;
        }
        if ($op === 'set_mfa_required') {
            self::fields($payload,['required','status'],'mfa payload');
            if (!is_bool($payload['required'])) throw new InvalidArgumentException('mfa.required invalid.');
            $status=self::oneOf($payload['status'],self::MFA,'mfa.status');
            if (!$payload['required'] && $status === 'required') throw new InvalidArgumentException('mfa metadata invalid.');
            return ['required'=>$payload['required'],'status'=>$status];
        }
        self::fields($payload,[],$op.' payload'); return [];
    }

    private static function state(array $raw, int $now): array
    {
        self::noSecrets($raw); self::fields($raw,['identity','grants','requests','audit','mfa'],'IdentityState');
        $identity=$raw['identity'] === null ? null : (is_array($raw['identity']) ? VentureIdentity::normalizeIdentity($raw['identity']) : throw new InvalidArgumentException('identity invalid.'));
        if (!is_array($raw['grants']) || !array_is_list($raw['grants']) || count($raw['grants'])>200) throw new InvalidArgumentException('grants invalid.');
        $grants=[]; $seen=[];
        foreach ($raw['grants'] as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('grant invalid.');
            $g=VentureIdentity::normalizeGrant($row,$now);
            if (isset($seen[$g['grant_id']])) throw new InvalidArgumentException('grant duplicated.');
            $seen[$g['grant_id']]=true; $grants[]=$g;
        }
        usort($grants,self::grantOrder(...)); self::requests($raw['requests']); self::audits($raw['audit']);
        self::fields($raw['mfa'],['required','status'],'mfa');
        if (!is_bool($raw['mfa']['required'])) throw new InvalidArgumentException('mfa.required invalid.');
        return ['identity'=>$identity,'grants'=>$grants,'requests'=>$raw['requests'],'audit'=>$raw['audit'],
            'mfa'=>['required'=>$raw['mfa']['required'],'status'=>self::oneOf($raw['mfa']['status'],self::MFA,'mfa.status')]];
    }

    private static function request(array $state, array $command, string $target, string $kind, int $now): array
    {
        if ($command['expires_at'] === null) throw new InvalidArgumentException('request expiry required.');
        foreach ($state['requests'] as $row) if ($row['request_id'] === $command['command_id']) throw new InvalidArgumentException('request duplicated.');
        if (count($state['requests'])>=100) throw new InvalidArgumentException('request capacity exceeded.');
        $state['requests'][]=['request_id'=>$command['command_id'],'kind'=>$kind,'identity_id'=>$target,'scope'=>$command['scope'],
            'requested_at'=>$now,'expires_at'=>$command['expires_at']];
        return $state;
    }

    private static function finish(array $state, array $command, string $target, string $outcome, int $now): array
    {
        $event=['event_id'=>$command['command_id'],'command_id'=>$command['command_id'],'idempotency_key'=>$command['idempotency_key'],
            'operation'=>$command['operation'],'actor_identity_id'=>$command['actor_identity_id'],'target_identity_id'=>$target,
            'scope'=>$command['scope'],'outcome'=>$outcome,'reason_code'=>$command['reason_code'],'occurred_at'=>$now,'expires_at'=>$command['expires_at']];
        $state['audit'][]=$event; return ['status'=>$outcome,'state'=>$state,'audit_event'=>$event];
    }

    private static function requests(mixed $rows): void
    {
        if (!is_array($rows)||!array_is_list($rows)||count($rows)>100) throw new InvalidArgumentException('requests invalid.');
        $seen=[];
        foreach ($rows as $r) {
            self::fields($r,['request_id','kind','identity_id','scope','requested_at','expires_at'],'request');
            $id=self::key($r['request_id'],'request_id'); if(isset($seen[$id])) throw new InvalidArgumentException('request duplicated.'); $seen[$id]=true;
            self::oneOf($r['kind'],self::REQUESTS,'request.kind'); self::id($r['identity_id'],'identity_id'); self::scope($r['scope']);
            $at=self::positive($r['requested_at'],'requested_at'); if(self::positive($r['expires_at'],'expires_at') <= $at) throw new InvalidArgumentException('request expiry invalid.');
        }
    }

    private static function audits(mixed $rows): void
    {
        if (!is_array($rows)||!array_is_list($rows)||count($rows)>500) throw new InvalidArgumentException('audit invalid.');
        foreach ($rows as $r) {
            self::fields($r,['event_id','command_id','idempotency_key','operation','actor_identity_id','target_identity_id','scope','outcome','reason_code','occurred_at','expires_at'],'audit');
            self::key($r['event_id'],'event_id'); self::key($r['command_id'],'command_id'); self::key($r['idempotency_key'],'idempotency_key');
            self::oneOf($r['operation'],self::OPS,'operation'); self::oneOf($r['outcome'],self::OUTCOMES,'outcome');
            self::id($r['actor_identity_id'],'actor_identity_id'); self::id($r['target_identity_id'],'target_identity_id');
            self::scope($r['scope']); self::reason($r['reason_code']); self::positive($r['occurred_at'],'occurred_at'); self::nullableTime($r['expires_at'],'expires_at');
        }
    }

    private static function target(array $state, array $command): string
    {
        return match($command['operation']) {
            'invite'=>$command['payload']['candidate']['identity_id'], 'create'=>$command['payload']['identity']['identity_id'],
            default=>self::identity($state)['identity_id'],
        };
    }
    private static function identity(array $state): array { if($state['identity']===null) throw new InvalidArgumentException('identity required.'); return $state['identity']; }
    private static function capabilityFor(string $op): string { return match($op) {
        'invite','create'=>'identity.create','suspend','reactivate'=>'identity.lifecycle',
        'grant_scope','revoke_scope','change_role','change_capability'=>'identity.access.manage',
        'request_reauth','request_reset'=>'identity.auth.request','set_mfa_required'=>'identity.security.manage',
    }; }
    private static function requiredAuthority(array $c): string { return $c['operation']==='grant_scope' ? $c['payload']['grant']['authority_level'] : (in_array($c['operation'],['request_reauth','request_reset'],true)?'L1_OPERATOR':'L2_VENTURE_ADMIN'); }
    private static function grantOrder(array $a,array $b): int { return $a['grant_id'] <=> $b['grant_id']; }
    private static function noSecrets(mixed $v): void { if(!is_array($v))return; foreach($v as $k=>$n){ if(is_string($k)&&preg_match('/password|hash|token|cookie|otp|recovery|secret|credential|session/i',$k)) throw new InvalidArgumentException('sensitive field rejected.'); self::noSecrets($n); } }
    private static function capability(mixed $v): string { if(!is_string($v)||preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){0,7}$/D',$v)!==1||strlen($v)>120) throw new InvalidArgumentException('capability invalid.'); return $v; }
    private static function scope(mixed $v): string { if(!is_string($v)||preg_match('/^(group|venture|project|institution):[a-z][a-z0-9-]{1,63}$/D',$v)!==1) throw new InvalidArgumentException('scope invalid.'); return $v; }
    private static function reason(mixed $v): string { if(!is_string($v)||preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$v)!==1) throw new InvalidArgumentException('reason invalid.'); return $v; }
    private static function id(mixed $v,string $l): string { if(!is_string($v)||preg_match('/^[a-z][a-z0-9-]{1,63}$/D',$v)!==1) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function key(mixed $v,string $l): string { if(!is_string($v)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D',$v)!==1) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function oneOf(mixed $v,array $a,string $l): string { if(!is_string($v)||!in_array($v,$a,true)) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function positive(mixed $v,string $l): int { if(!is_int($v)||$v<1) throw new InvalidArgumentException($l.' invalid.'); return $v; }
    private static function nullableTime(mixed $v,string $l): ?int { return $v===null?null:self::positive($v,$l); }
    private static function fields(mixed $r,array $e,string $l): void { if(!is_array($r)) throw new InvalidArgumentException($l.' invalid.'); $a=array_keys($r);sort($a);sort($e);if($a!==$e)throw new InvalidArgumentException($l.' fields invalid.'); }
}

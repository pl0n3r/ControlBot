<?php
declare(strict_types=1);

namespace ControlBot\Replay;

use InvalidArgumentException;

final class AgentReplayCore
{
    private const FIELDS=[
        'event_id','occurred_at','observed_at','sequence','source','kind','actor_type','actor_ref',
        'work_item_id','project_id','repo','issue_number','pr_number','commit_sha','execution_order_id',
        'execution_event_id','summary','evidence_ref','payload_digest',
    ];
    private const SOURCES=['github_issue','github_pr','github_commit','github_actions','controlbot','factory','factoryrunner','production','external'];
    private const KINDS=['requested','attempted','success','failure','skipped','startup_failure','cancelled','blocked','waiting_human','unknown','handoff'];
    private const ACTORS=['human','agent','workflow','runner','system','session'];
    private const SENSITIVE='/(?:bearer\\s+|password|passwd|token\\s*[:=?]|secret\\s*[:=]|cookie\\s*[:=]|authorization\\s*[:=]|private[_ -]?key|api[_ -]?key|dsn\\s*[:=]|(?:sk|rk|pk)-[A-Za-z0-9_-]{12,})/i';
    private const FORBIDDEN_TEXT='/(?:full\\s+transcript|\\btranscript\\b|full\\s+chat|chat\\s+history|chain[- ]of[- ]thought|reasoning\\s+trace|private\\s+scratchpad|internal\\s+reasoning)/i';
    private const EMAIL='/\\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}\\b/i';
    private const PHONE='/(?:^|\\D)\\+?\\d[\\d .()\\-]{7,}\\d(?:\\D|$)/';

    public static function build(array $rows): array
    {
        if(!array_is_list($rows)||$rows===[]||count($rows)>256) throw new InvalidArgumentException('Replay events invalid.');

        $unique=[];$groups=[];
        foreach($rows as $raw){
            if(!is_array($raw)) throw new InvalidArgumentException('Replay event invalid.');
            $event=self::event($raw);
            $variant=self::fingerprint($event);
            if(isset($unique[$variant])) continue;
            $unique[$variant]=$event;
            $groups[$event['event_id']][$variant]=$event;
        }

        $events=array_values($unique);
        usort($events,static fn(array $a,array $b):int=>
            [$a['occurred_at'],$a['sequence'],$a['source'],$a['event_id'],$a['payload_digest']]
            <=>
            [$b['occurred_at'],$b['sequence'],$b['source'],$b['event_id'],$b['payload_digest']]
        );

        $conflicts=[];
        ksort($groups,SORT_STRING);
        foreach($groups as $eventId=>$variants){
            if(count($variants)<2) continue;
            $fingerprints=array_keys($variants);sort($fingerprints,SORT_STRING);
            $refs=[];
            foreach($variants as $event)$refs[]=$event['evidence_ref'];
            $refs=array_values(array_unique($refs));sort($refs,SORT_STRING);
            $conflicts[]=[
                'event_id'=>$eventId,
                'state'=>'unknown',
                'reason'=>'conflicting_evidence',
                'variant_count'=>count($variants),
                'variant_fingerprints'=>$fingerprints,
                'evidence_refs'=>$refs,
            ];
        }

        $canonical=['version'=>1,'events'=>$events,'conflicts'=>$conflicts];
        $out=$canonical+['fingerprint'=>self::fingerprint($canonical)];
        self::secretFree($out);
        return $out;
    }

    public static function event(array $raw): array
    {
        self::fields($raw,self::FIELDS,'ReplayEvent');
        $occurred=self::nonNegativeInt($raw['occurred_at'],'occurred_at');
        $observed=self::nonNegativeInt($raw['observed_at'],'observed_at');
        if($observed<$occurred) throw new InvalidArgumentException('observed_at cannot precede occurred_at.');

        return [
            'event_id'=>self::id($raw['event_id'],'event_id'),
            'occurred_at'=>$occurred,
            'observed_at'=>$observed,
            'sequence'=>self::nonNegativeInt($raw['sequence'],'sequence'),
            'source'=>self::choice($raw['source'],self::SOURCES,'source'),
            'kind'=>self::choice($raw['kind'],self::KINDS,'kind'),
            'actor_type'=>self::choice($raw['actor_type'],self::ACTORS,'actor_type'),
            'actor_ref'=>self::opaqueRef($raw['actor_ref'],'actor_ref'),
            'work_item_id'=>self::id($raw['work_item_id'],'work_item_id'),
            'project_id'=>self::slug($raw['project_id'],'project_id'),
            'repo'=>self::repo($raw['repo']),
            'issue_number'=>self::nullablePositiveInt($raw['issue_number'],'issue_number'),
            'pr_number'=>self::nullablePositiveInt($raw['pr_number'],'pr_number'),
            'commit_sha'=>self::nullableSha($raw['commit_sha']),
            'execution_order_id'=>self::nullableOpaqueRef($raw['execution_order_id'],'execution_order_id'),
            'execution_event_id'=>self::nullableOpaqueRef($raw['execution_event_id'],'execution_event_id'),
            'summary'=>self::safeText($raw['summary'],'summary',240),
            'evidence_ref'=>self::evidenceRef($raw['evidence_ref']),
            'payload_digest'=>self::sha256($raw['payload_digest'],'payload_digest'),
        ];
    }

    private static function fields(array $raw,array $expected,string $label): void
    {
        if(array_is_list($raw)) throw new InvalidArgumentException($label.' invalid.');
        $actual=array_keys($raw);sort($actual,SORT_STRING);$wanted=$expected;sort($wanted,SORT_STRING);
        if($actual!==$wanted) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function choice(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nonNegativeInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<0) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullablePositiveInt(mixed $value,string $label): ?int
    {
        if($value===null) return null;
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function id(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._:-]{2,95}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{1,79}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function repo(mixed $value): string
    {
        if(!is_string($value)||preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D',$value)!==1)
            throw new InvalidArgumentException('repo invalid.');
        return $value;
    }

    private static function opaqueRef(mixed $value,string $label): string
    {
        if(!is_string($value)||strlen($value)<3||strlen($value)>180||str_contains($value,'@')
            ||preg_match(self::SENSITIVE,$value)===1||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\\/#-]{2,179}$/D',$value)!==1)
            throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function nullableOpaqueRef(mixed $value,string $label): ?string
    {
        return $value===null?null:self::opaqueRef($value,$label);
    }

    private static function nullableSha(mixed $value): ?string
    {
        if($value===null) return null;
        if(!is_string($value)||preg_match('/^[0-9a-f]{40}$/D',$value)!==1) throw new InvalidArgumentException('commit_sha invalid.');
        return $value;
    }

    private static function sha256(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[0-9a-f]{64}$/D',$value)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function safeText(mixed $value,string $label,int $max): string
    {
        if(!is_string($value)) throw new InvalidArgumentException($label.' invalid.');
        $value=trim($value);
        if($value===''||strlen($value)>$max||preg_match('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/',$value)===1
            ||preg_match(self::SENSITIVE,$value)===1||preg_match(self::FORBIDDEN_TEXT,$value)===1
            ||preg_match(self::EMAIL,$value)===1||preg_match(self::PHONE,$value)===1)
            throw new InvalidArgumentException($label.' contains sensitive or unsupported material.');
        return $value;
    }

    private static function evidenceRef(mixed $value): string
    {
        if(!is_string($value)||strlen($value)<3||strlen($value)>240||str_contains($value,'@')
            ||preg_match(self::SENSITIVE,$value)===1||preg_match(self::EMAIL,$value)===1||preg_match(self::FORBIDDEN_TEXT,$value)===1)
            throw new InvalidArgumentException('evidence_ref invalid.');
        $github=preg_match('~^https://github\\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9._/#-]+)?$~D',$value)===1;
        $internal=preg_match('/^(?:controlbot|factory|factoryrunner|production|external):[A-Za-z0-9][A-Za-z0-9._:\\/#-]{1,199}$/D',$value)===1;
        if(!$github&&!$internal) throw new InvalidArgumentException('evidence_ref invalid.');
        return $value;
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private static function secretFree(mixed $value): void
    {
        if(is_array($value)){foreach($value as $item)self::secretFree($item);return;}
        if(is_string($value)&&(preg_match(self::SENSITIVE,$value)===1||preg_match(self::EMAIL,$value)===1||preg_match(self::FORBIDDEN_TEXT,$value)===1))
            throw new InvalidArgumentException('Replay output contains sensitive material.');
    }
}

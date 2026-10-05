<?php
declare(strict_types=1);
namespace ControlBot\GitHub;
use InvalidArgumentException;

final class GitHubIntentEnvelope
{
    private const TYPES=['issue.create','issue.update','issue.close','issue.reserve','issue.release','pr.review','pr.merge','workflow.dispatch','release.approve','project.freeze','project.unfreeze'];
    private const PARAMS=[
        'issue.create'=>['payload_ref'=>'ref'],
        'issue.update'=>['issue_number'=>'int','payload_ref'=>'ref'],
        'issue.close'=>['issue_number'=>'int'],
        'issue.reserve'=>['issue_number'=>'int','reservation_ref'=>'ref'],
        'issue.release'=>['issue_number'=>'int','reservation_ref'=>'ref'],
        'pr.review'=>['pr_number'=>'int','review_ref'=>'ref'],
        'pr.merge'=>['pr_number'=>'int','expected_head_sha'=>'sha','merge_method'=>'merge'],
        'workflow.dispatch'=>['workflow_ref'=>'workflow','git_ref'=>'gitref','inputs_ref'=>'ref'],
        'release.approve'=>['release_ref'=>'ref','candidate_sha'=>'sha','approval_ref'=>'ref'],
        'project.freeze'=>['reason_ref'=>'ref'],
        'project.unfreeze'=>['reason_ref'=>'ref'],
    ];
    private const SENSITIVE='#(?:^|[:/._-])(?:password|passwd|secret|credential|authorization|bearer|private[-_ ]?key|api[-_ ]?key|access[-_ ]?token|refresh[-_ ]?token|token|otp|cookie)(?:$|[:/._-])#i';

    public static function envelope(array $raw): array
    {
        self::fields($raw,['version','intent_id','project_ref','repository_ref','type','params','idempotency_key','evidence_refs'],'GitHubIntentEnvelope');
        if(($raw['version']??null)!==1) throw new InvalidArgumentException('GitHubIntentEnvelope version invalid.');
        $type=self::enum($raw['type'],self::TYPES,'type');
        return [
            'version'=>1,'intent_id'=>self::match($raw['intent_id'],'/^[a-f0-9]{32}$/D','intent_id'),
            'project_ref'=>self::match($raw['project_ref'],'#^controlbot:project/[a-z][a-z0-9-]{1,63}$#D','project_ref',true),
            'repository_ref'=>self::match($raw['repository_ref'],'/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D','repository_ref',true,160),
            'type'=>$type,'params'=>self::params($type,$raw['params']),
            'idempotency_key'=>self::match($raw['idempotency_key'],'/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D','idempotency_key',true),
            'evidence_refs'=>self::refs($raw['evidence_refs']),'execution'=>false,
        ];
    }

    private static function params(string $type,mixed $raw): array
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException('params invalid.');
        $schema=self::PARAMS[$type]; self::fields($raw,array_keys($schema),'params'); $out=[];
        foreach($schema as $field=>$kind){
            $out[$field]=match($kind){
                'int'=>self::positiveInt($raw[$field],$field),
                'ref'=>self::ref($raw[$field],$field),
                'sha'=>self::match($raw[$field],'/^[a-f0-9]{40}$/D',$field),
                'merge'=>self::enum($raw[$field],['merge','squash','rebase'],$field),
                'workflow'=>self::match($raw[$field],'/^[A-Za-z0-9_.-]+(?:\.ya?ml)?$/D','workflow_ref',true,120),
                'gitref'=>self::gitRef($raw[$field]),
                default=>throw new InvalidArgumentException('params schema invalid.'),
            };
        }
        return $out;
    }

    private static function fields(array $raw,array $fields,string $label): void
    {
        $expected=array_fill_keys($fields,true);
        if(array_is_list($raw)||array_diff_key($raw,$expected)!==[]||array_diff_key($expected,$raw)!==[]) throw new InvalidArgumentException($label.' fields invalid.');
    }

    private static function refs(mixed $value): array
    {
        if(!is_array($value)||!array_is_list($value)||$value===[]||count($value)>20) throw new InvalidArgumentException('evidence_refs invalid.');
        $refs=array_map(static fn($ref)=>self::ref($ref,'evidence_ref'),$value);
        if(count(array_unique($refs,SORT_STRING))!==count($refs)) throw new InvalidArgumentException('evidence_refs duplicated.');
        return $refs;
    }

    private static function ref(mixed $value,string $label): string
    { return self::match($value,'#^(?:controlbot|github):[a-z][a-z0-9._/-]{1,159}$#D',$label,true,180); }

    private static function match(mixed $value,string $pattern,string $label,bool $sensitive=false,int $max=0): string
    {
        if(!is_string($value)||($max>0&&strlen($value)>$max)||preg_match($pattern,$value)!==1||($sensitive&&preg_match(self::SENSITIVE,$value)===1)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function positiveInt(mixed $value,string $label): int
    { if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.'); return $value; }

    private static function gitRef(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>120||str_contains($value,'..')||preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#D',$value)!==1||preg_match(self::SENSITIVE,$value)===1) throw new InvalidArgumentException('git_ref invalid.');
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    { if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.'); return $value; }
}

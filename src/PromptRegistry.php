<?php
declare(strict_types=1);

namespace ControlBot\Prompt;

use InvalidArgumentException;

final class PromptRegistry
{
    private const STATUSES=['draft','candidate','approved','deprecated','revoked'];
    private const TYPES=['string','integer','number','boolean','array','object'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|credential|private[_ -]?key|api[_ -]?key|otp|recovery[_ -]?code)/i';

    public static function register(array $history,array $candidate): array
    {
        $rows=self::history($history); $next=self::version($candidate); $previous=null;
        foreach($rows as $row){
            if($row['template_id']!==$next['template_id']) continue;
            if($row['version']===$next['version']) throw new InvalidArgumentException('Prompt version duplicated.');
            $previous=$row;
        }
        if($previous===null){
            if($next['version']!==1||$next['supersedes']!==null) throw new InvalidArgumentException('Initial prompt version invalid.');
        }elseif(
            $next['version']<=$previous['version']||
            $next['supersedes']!==$previous['version']||
            !self::sameContract($next,$previous)||
            $next['created_at']<=$previous['created_at']
        ){
            throw new InvalidArgumentException('Prompt version sequence invalid.');
        }
        if(count($rows)>=128) throw new InvalidArgumentException('Prompt history limit exceeded.');
        $rows[]=$next; return self::sort($rows);
    }

    public static function active(array $history,string $templateId): array
    {
        $id=self::slug($templateId,'template_id'); $selected=null;
        foreach(self::history($history) as $row){
            if($row['template_id']===$id&&$row['status']==='approved') $selected=$row;
        }
        if($selected===null) throw new InvalidArgumentException('Approved prompt version unavailable.');
        return $selected;
    }

    public static function rollback(array $history,string $templateId,int $targetVersion): array
    {
        $id=self::slug($templateId,'template_id'); $rows=self::history($history); $current=self::active($rows,$id);
        if($targetVersion<1||$targetVersion>=$current['version']) throw new InvalidArgumentException('Rollback target invalid.');
        foreach($rows as $row){
            if($row['template_id']===$id&&$row['version']===$targetVersion){
                if($row['status']!=='approved') throw new InvalidArgumentException('Rollback target is not approved.');
                return $row;
            }
        }
        throw new InvalidArgumentException('Rollback target unavailable.');
    }

    private static function history(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>128) throw new InvalidArgumentException('Prompt history invalid.');
        $out=[]; $seen=[];
        foreach($rows as $raw){
            $row=self::version($raw); $key=$row['template_id'].'@'.$row['version'];
            if(isset($seen[$key])) throw new InvalidArgumentException('Prompt version duplicated.');
            $seen[$key]=true; $out[]=$row;
        }
        $out=self::sort($out); $previous=[];
        foreach($out as $row){
            $id=$row['template_id']; $prior=$previous[$id]??null;
            if($prior===null){
                if($row['version']!==1||$row['supersedes']!==null) throw new InvalidArgumentException('Prompt history initial version invalid.');
            }elseif(
                $row['supersedes']!==$prior['version']||
                !self::sameContract($row,$prior)||
                $row['created_at']<=$prior['created_at']
            ){
                throw new InvalidArgumentException('Prompt history chain invalid.');
            }
            $previous[$id]=$row;
        }
        return $out;
    }

    private static function version(mixed $raw): array
    {
        $raw=self::fields($raw,['template_id','version','task_class','provider_scope','body_ref','variables_schema','status','created_at','created_by','supersedes'],'PromptTemplateVersion');
        $template=self::slug($raw['template_id'],'template_id');
        $task=self::slug($raw['task_class'],'task_class');
        $provider=self::slug($raw['provider_scope'],'provider_scope');
        $body=self::bodyRef($raw['body_ref']);
        $actor=self::actorRef($raw['created_by']);
        foreach([$template,$task,$provider,$body,$actor] as $text) self::safe($text);
        $version=self::positiveInt($raw['version'],'version');
        $supersedes=$raw['supersedes']===null?null:self::positiveInt($raw['supersedes'],'supersedes');
        if($supersedes!==null&&$supersedes>=$version) throw new InvalidArgumentException('supersedes invalid.');
        return [
            'template_id'=>$template,'version'=>$version,'task_class'=>$task,'provider_scope'=>$provider,
            'body_ref'=>$body,'variables_schema'=>self::schema($raw['variables_schema']),
            'status'=>self::enum($raw['status'],self::STATUSES,'status'),
            'created_at'=>self::positiveInt($raw['created_at'],'created_at'),'created_by'=>$actor,'supersedes'=>$supersedes,
        ];
    }

    private static function schema(mixed $raw): array
    {
        if(!is_array($raw)||($raw!==[]&&array_is_list($raw))||count($raw)>64) throw new InvalidArgumentException('variables_schema invalid.');
        $out=[];
        foreach($raw as $name=>$definition){
            if(!is_string($name)||preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$name)!==1) throw new InvalidArgumentException('Variable name invalid.');
            self::safe($name); $definition=self::fields($definition,['type','required'],'VariableDefinition');
            if(!is_bool($definition['required'])) throw new InvalidArgumentException('Variable required invalid.');
            $out[$name]=['type'=>self::enum($definition['type'],self::TYPES,'variable type'),'required'=>$definition['required']];
        }
        ksort($out,SORT_STRING); return $out;
    }

    private static function sameContract(array $a,array $b): bool
    {
        return $a['task_class']===$b['task_class']&&$a['provider_scope']===$b['provider_scope']&&$a['variables_schema']===$b['variables_schema'];
    }

    private static function sort(array $rows): array
    {
        usort($rows,static fn(array $a,array $b): int=>[$a['template_id'],$a['version']]<=>[$b['template_id'],$b['version']]);
        return $rows;
    }

    private static function fields(mixed $raw,array $expected,string $label): array
    {
        if(!is_array($raw)||array_is_list($raw)) throw new InvalidArgumentException($label.' fields invalid.');
        $actual=array_keys($raw); sort($actual); sort($expected);
        if($actual!==$expected) throw new InvalidArgumentException($label.' fields invalid.');
        return $raw;
    }

    private static function slug(mixed $value,string $label): string
    {
        if(!is_string($value)||preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$value)!==1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function bodyRef(mixed $value): string
    {
        if(!is_string($value)||preg_match('#^prompt:[a-z0-9][a-z0-9._/-]{1,119}$#D',$value)!==1) throw new InvalidArgumentException('body_ref invalid.');
        return $value;
    }

    private static function actorRef(mixed $value): string
    {
        if(!is_string($value)||preg_match('#^(?:owner|agent|system|actor):[a-z0-9][a-z0-9._/-]{0,95}$#D',$value)!==1) throw new InvalidArgumentException('created_by invalid.');
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    {
        if(!is_string($value)||!in_array($value,$allowed,true)) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function positiveInt(mixed $value,string $label): int
    {
        if(!is_int($value)||$value<1) throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function safe(string $value): void
    {
        if(preg_match(self::SENSITIVE,$value)===1) throw new InvalidArgumentException('Sensitive prompt metadata invalid.');
    }
}

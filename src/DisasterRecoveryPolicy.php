<?php
declare(strict_types=1);
namespace ControlBot\Infrastructure;
use InvalidArgumentException;

final class DisasterRecoveryPolicy
{
    private const COMPONENTS=['database','media','code','secrets'];
    private const STRATEGIES=[
        'database'=>'consistent_backup','media'=>'versioned_backup',
        'code'=>'repository_mirror','secrets'=>'vault_reference_only',
    ];

    public static function normalize(array $r): array
    {
        self::fields($r,['version','project_id','rpo_seconds','rto_seconds','retention','strategies','capabilities','destinations','policy_ref','provenance_refs'],'policy');
        self::safe($r);
        if(($r['version']??null)!==1) throw new InvalidArgumentException('version');
        $project=self::slug($r['project_id']??null,'project_id','-');
        $strategies=self::map($r['strategies']??null,self::COMPONENTS,fn($v,$k)=>self::one($v,[self::STRATEGIES[$k]],"strategy.$k"));
        $caps=self::map($r['capabilities']??null,['offsite','versioned_or_immutable','checksum_required','freshness_required','restore_drill_required'],function($v,$k){
            if(!is_bool($v)) throw new InvalidArgumentException("capability.$k"); return $v;
        });
        $ret=self::map($r['retention']??null,['recent','daily','weekly','monthly'],function($v,$k){
            if(!is_int($v)||$v<0||$v>100000) throw new InvalidArgumentException("retention.$k"); return $v;
        });
        if(array_sum($ret)<1) throw new InvalidArgumentException('retention empty');
        $ref=$r['policy_ref']??null; $prefix="controlbot:dr-policy/$project/";
        if(!is_string($ref)||!str_starts_with($ref,$prefix)||strlen($ref)<=strlen($prefix)) throw new InvalidArgumentException('policy_ref');
        return [
            'version'=>1,'project_id'=>$project,
            'rpo_seconds'=>self::positive($r['rpo_seconds']??null,'rpo'),
            'rto_seconds'=>self::positive($r['rto_seconds']??null,'rto'),
            'retention'=>$ret,'strategies'=>$strategies,'capabilities'=>$caps,
            'destinations'=>self::destinations($r['destinations']??null),
            'policy_ref'=>$ref,'provenance_refs'=>self::refs($r['provenance_refs']??null),
        ];
    }

    public static function evidenceStatus(?array $r): string
    {
        if($r===null) return 'unknown';
        self::fields($r,['state','freshness','complete'],'evidence');
        $state=self::one($r['state']??null,['healthy','degraded','unknown','blocked'],'state');
        $fresh=self::one($r['freshness']??null,['fresh','stale','unknown'],'freshness');
        if(!is_bool($r['complete']??null)) throw new InvalidArgumentException('complete');
        if($state==='blocked') return 'blocked';
        if($fresh==='unknown') return 'unknown';
        if($fresh==='stale'||!$r['complete']) return 'degraded';
        return $state;
    }

    public static function fingerprint(array $r): string
    {
        return hash('sha256',json_encode(self::canon(self::normalize($r)),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    private static function destinations(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)) throw new InvalidArgumentException('destinations');
        $out=[];$seen=[];
        foreach($rows as $row){
            self::fields($row,['provider','role'],'destination');
            $p=self::slug($row['provider']??null,'provider','_'); $role=self::slug($row['role']??null,'role','_');
            if($p==='google_drive'&&$role!=='offsite_encrypted_copy') throw new InvalidArgumentException('drive role');
            if($p==='icloud'&&in_array($role,['primary_runtime_storage','server_automation_dependency'],true)) throw new InvalidArgumentException('icloud role');
            $key="$p|$role"; if(isset($seen[$key])) throw new InvalidArgumentException('destination duplicate');
            $seen[$key]=true;$out[]=['provider'=>$p,'role'=>$role];
        }
        usort($out,fn($a,$b)=>($a['provider'].'|'.$a['role'])<=>($b['provider'].'|'.$b['role']));
        return $out;
    }

    private static function refs(mixed $rows): array
    {
        if(!is_array($rows)||!array_is_list($rows)) throw new InvalidArgumentException('refs');
        $out=[];
        foreach($rows as $v){
            if(!is_string($v)||$v===''||strlen($v)>500||preg_match('/\s/',$v)) throw new InvalidArgumentException('ref');
            if(isset($out[$v])) throw new InvalidArgumentException('ref duplicate'); $out[$v]=true;
        }
        $rows=array_keys($out);sort($rows,SORT_STRING);return $rows;
    }

    private static function map(mixed $r,array $keys,callable $fn): array
    {
        self::fields($r,$keys,'map');$out=[];
        foreach($keys as $k) $out[$k]=$fn($r[$k]??null,$k);
        return $out;
    }

    private static function fields(mixed $r,array $keys,string $label): void
    {
        if(!is_array($r)||array_is_list($r)) throw new InvalidArgumentException($label);
        $a=array_keys($r);sort($a);$b=$keys;sort($b);
        if($a!==$b) throw new InvalidArgumentException("$label fields");
    }

    private static function positive(mixed $v,string $label): int
    { if(!is_int($v)||$v<1||$v>31536000) throw new InvalidArgumentException($label); return $v; }

    private static function one(mixed $v,array $allowed,string $label): string
    { if(!is_string($v)||!in_array($v,$allowed,true)) throw new InvalidArgumentException($label); return $v; }

    private static function slug(mixed $v,string $label,string $extra): string
    {
        $pattern=$extra==='-'?'/^[a-z][a-z0-9-]{1,63}$/D':'/^[a-z][a-z0-9_]{1,63}$/D';
        if(!is_string($v)||preg_match($pattern,$v)!==1) throw new InvalidArgumentException($label); return $v;
    }

    private static function safe(mixed $v): void
    {
        if(is_array($v)){foreach($v as $x) self::safe($x);return;}
        if(!is_string($v)) return;
        if(preg_match('/(?:password|passwd|token|secret|api[_-]?key|private[_-]?key)\s*[:=]/i',$v)||str_contains($v,'-----BEGIN PRIVATE KEY-----')||preg_match('/\bsk-[A-Za-z0-9_-]{12,}\b/',$v)) throw new InvalidArgumentException('sensitive');
    }

    private static function canon(mixed $v): mixed
    {
        if(!is_array($v)) return $v;
        if(array_is_list($v)) return array_map([self::class,'canon'],$v);
        ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::canon($x);return $v;
    }
}

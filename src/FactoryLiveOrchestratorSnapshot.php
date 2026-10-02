<?php
declare(strict_types=1);
namespace ControlBot\Business;
use InvalidArgumentException;

final class FactoryLiveOrchestratorSnapshot
{
    private const REPOS=['pl0n3r/Factory','pl0n3r/Condor','pl0n3r/GrindFlow','pl0n3r/brvtal','pl0n3r/ControlBot','pl0n3r/AutoFactory','pl0n3r/FactoryRunner'];
    private const FRONT=['available','reserved','in_review','blocked','merged','unknown'];
    private const SENSITIVE='/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PII='/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function build(array $snapshot,int $now): array
    {
        self::canonical($snapshot,$now);
        $central=self::central($snapshot['work_inventory']??null,$now);
        $fronts=self::fronts($snapshot['sections']['work'],$now);
        $decisions=self::decisions($snapshot['sections']['owner_decisions'],$now);
        $out=['version'=>1,'observed_at'=>$now,'source_snapshot'=>$snapshot['fingerprint'],'read_only'=>true,'central'=>$central,'fronts'=>$fronts,'owner_decisions'=>$decisions];
        return $out+['fingerprint'=>hash('sha256',json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))];
    }

    private static function canonical(array $s,int $now): void
    {
        if(($s['version']??null)!==1||!is_int($s['observed_at']??null)||$s['observed_at']<1||$s['observed_at']>$now||!is_array($s['sections']??null)||array_is_list($s['sections']))
            throw new InvalidArgumentException('Canonical FactoryLiveSnapshot invalid.');
        $keys=array_keys($s['sections']);$expected=['batches','owner_decisions','releases','blockers','production','quality','work','learning'];
        if($keys!==$expected)throw new InvalidArgumentException('FactoryLiveSnapshot sections invalid.');
        if(!is_string($s['fingerprint']??null)||preg_match('/^[0-9a-f]{64}$/D',$s['fingerprint'])!==1)
            throw new InvalidArgumentException('FactoryLiveSnapshot fingerprint invalid.');
        $copy=$s;unset($copy['fingerprint']);
        $hash=hash('sha256',json_encode($copy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        if(!hash_equals($hash,$s['fingerprint']))throw new InvalidArgumentException('FactoryLiveSnapshot fingerprint mismatch.');
    }

    private static function central(mixed $inventory,int $now): array
    {
        if($inventory===null)return ['activity_state'=>'UNKNOWN','available'=>null,'reserved'=>null,'blocked'=>null,'source_ref'=>null,'observed_at'=>null,'freshness'=>'unknown','age_seconds'=>null];
        self::fields($inventory,['version','source_ref','observed_at','freshness','projects'],'work_inventory');
        if($inventory['version']!==1)throw new InvalidArgumentException('work_inventory.version invalid.');
        $source=self::text($inventory['source_ref'],'work_inventory.source_ref',240);
        $at=self::time($inventory['observed_at'],'work_inventory.observed_at',$now);
        if(!in_array($inventory['freshness'],['current','stale'],true))throw new InvalidArgumentException('work_inventory.freshness invalid.');
        if(!is_array($inventory['projects'])||!array_is_list($inventory['projects'])||count($inventory['projects'])!==7)throw new InvalidArgumentException('work_inventory.projects invalid.');
        $a=$r=$b=0;
        foreach($inventory['projects'] as $i=>$p){
            if(!is_array($p)||array_is_list($p)||($p['repository_ref']??null)!==self::REPOS[$i]||!is_array($p['counts']??null))throw new InvalidArgumentException('work_inventory project invalid.');
            $a+=self::count($p['counts']['available']??null);$r+=self::count($p['counts']['reserved']??null);$b+=self::count($p['counts']['blocked']??null);
        }
        $state=$inventory['freshness']==='stale'?'STALE':($r>0?'ACTIVE':($a>0?'READY':($b>0?'BLOCKED':'IDLE')));
        return ['activity_state'=>$state,'available'=>$a,'reserved'=>$r,'blocked'=>$b,'source_ref'=>$source,'observed_at'=>$at,'freshness'=>$inventory['freshness'],'age_seconds'=>$now-$at];
    }

    private static function fronts(mixed $rows,int $now): array
    {
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('work signals invalid.');
        $out=[];
        foreach($rows as $row){
            self::signal($row,'github_project_snapshot',$now);
            if($row['freshness']==='unknown')continue;
            $data=$row['data']??null;if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('work data invalid.');
            $repo=$data['repository_ref']??null;if(!is_string($repo)||!in_array($repo,self::REPOS,true))throw new InvalidArgumentException('repository_ref invalid.');
            $status=$data['status']??'unknown';if(!is_string($status)||!in_array($status,self::FRONT,true))throw new InvalidArgumentException('work status invalid.');
            $progress=null;$progressState='UNKNOWN';
            if($row['freshness']==='current'&&isset($data['progress_percent'],$data['progress_evidence'])){
                if(!is_int($data['progress_percent'])||$data['progress_percent']<0||$data['progress_percent']>100)throw new InvalidArgumentException('progress invalid.');
                self::text($data['progress_evidence'],'progress_evidence',240);$progress=$data['progress_percent'];$progressState='EVIDENCED';
            }
            if($row['freshness']==='stale')$status='STALE';
            $out[]=['id'=>self::id($row['id']),'repository_ref'=>$repo,'issue_ref'=>self::issue($data['issue_ref']??null),'status'=>$status,'progress_percent'=>$progress,'progress_state'=>$progressState,'source_ref'=>self::text($row['source_ref'],'work.source_ref',240),'observed_at'=>$row['observed_at'],'freshness'=>$row['freshness'],'age_seconds'=>$now-$row['observed_at']];
        }
        if(count($out)>24)throw new InvalidArgumentException('Too many active fronts.');
        usort($out,static fn($a,$b)=>$a['id']<=>$b['id']);return $out;
    }

    private static function decisions(mixed $rows,int $now): array
    {
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('owner_decisions invalid.');
        $out=[];
        foreach($rows as $row){
            self::signal($row,'owner_inbox',$now);if($row['freshness']==='unknown')continue;
            $data=$row['data']??null;if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('owner decision data invalid.');
            $out[]=['id'=>self::id($row['id']),'issue_ref'=>self::issue($data['issue_ref']??null),'source_ref'=>self::text($row['source_ref'],'decision.source_ref',240),'observed_at'=>$row['observed_at'],'freshness'=>$row['freshness'],'age_seconds'=>$now-$row['observed_at']];
        }
        usort($out,static fn($a,$b)=>$a['id']<=>$b['id']);return $out;
    }

    private static function signal(mixed $row,string $authority,int $now): void
    {
        if(!is_array($row)||array_is_list($row)||($row['authority']??null)!==$authority||!in_array($row['freshness']??null,['current','stale','unknown'],true))throw new InvalidArgumentException('Canonical signal invalid.');
        if($row['freshness']==='unknown'){if(($row['source_ref']??null)!==null||($row['observed_at']??null)!==null)throw new InvalidArgumentException('Unknown signal invalid.');return;}
        self::text($row['source_ref']??null,'signal.source_ref',240);self::time($row['observed_at']??null,'signal.observed_at',$now);
        if($row['freshness']==='stale'&&($row['state']??null)==='healthy')throw new InvalidArgumentException('Stale signal cannot be healthy.');
    }

    private static function issue(mixed $v): string
    { if(!is_string($v)||preg_match('~^(?:https://github\.com/pl0n3r/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*|github:pl0n3r/[A-Za-z0-9_.-]+#[1-9][0-9]*)$~D',$v)!==1)throw new InvalidArgumentException('issue_ref invalid.');return self::text($v,'issue_ref',240); }
    private static function id(mixed $v): string
    { if(!is_string($v)||preg_match('/^[a-z][a-z0-9._:\/#-]{2,160}$/D',$v)!==1)throw new InvalidArgumentException('signal id invalid.');return self::text($v,'signal.id',180); }
    private static function text(mixed $v,string $label,int $max): string
    { if(!is_string($v)||trim($v)===''||strlen(trim($v))>$max||preg_match('/[\x00-\x1f\x7f]/',$v)===1||preg_match(self::SENSITIVE,$v)===1||preg_match(self::PII,$v)===1)throw new InvalidArgumentException($label.' unsafe.');return trim($v); }
    private static function time(mixed $v,string $label,int $now): int
    { if(!is_int($v)||$v<1||$v>$now)throw new InvalidArgumentException($label.' invalid.');return $v; }
    private static function count(mixed $v): int
    { if(!is_int($v)||$v<0||$v>1000000)throw new InvalidArgumentException('count invalid.');return $v; }
    private static function fields(mixed $row,array $expected,string $label): void
    { if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException($label.' invalid.');$a=array_keys($row);sort($a);sort($expected);if($a!==$expected)throw new InvalidArgumentException($label.' fields invalid.'); }
}

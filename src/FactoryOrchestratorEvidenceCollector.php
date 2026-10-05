<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use RuntimeException;

final class FactoryOrchestratorEvidenceCollector
{
    private const REPOS=['Factory','Condor','GrindFlow','brvtal','ControlBot','AutoFactory','FactoryRunner'];
    private const MAX_REQUESTS=40;
    private const MAX_SIGNALS=50;

    public static function run(array $env,callable $transport,int $now): array
    {
        if(($env['CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED']??'')!=='1')return ['executed'=>false,'state'=>'disabled'];
        if($now<1)throw new InvalidArgumentException('collector clock invalid.');
        $tokenPath=self::path($env['CONTROLBOT_GITHUB_READ_TOKEN_FILE']??'','token',true);
        $evidencePath=self::path($env['CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH']??'','evidence',false);
        $token=self::token($tokenPath);$requests=0;
        $get=static function(string $path,array $query=[])use($transport,$token,&$requests):array{
            if(++$requests>self::MAX_REQUESTS)throw new RuntimeException('request budget exceeded.');
            $url='https://api.github.com'.$path.($query?'?'.http_build_query($query):'');
            for($attempt=0;$attempt<2;$attempt++){
                $r=$transport('GET',$url,['Accept'=>'application/vnd.github+json','Authorization'=>'Bearer '.$token]);
                if(!is_array($r)||array_is_list($r)||!is_int($r['status']??null)||!is_array($r['headers']??null))throw new RuntimeException('transport invalid.');
                if(isset($r['headers']['retry-after']))throw new RuntimeException('github retry deferred.');
                $remaining=$r['headers']['x-ratelimit-remaining']??null;
                if(is_numeric($remaining)&&(int)$remaining<5)throw new RuntimeException('rate limit low.');
                if($r['status']>=500&&$attempt===0)continue;
                if($r['status']!==200||!is_array($r['json']??null))throw new RuntimeException('github read failed.');
                return $r['json'];
            }
            throw new RuntimeException('github read failed.');
        };
        $paged=static function(string $path,array $query=[])use($get):array{
            $query['per_page']=100;$query['page']=1;$first=$get($path,$query);
            if(!array_is_list($first))throw new RuntimeException('github page invalid.');
            if(count($first)<100)return $first;
            $query['page']=2;$second=$get($path,$query);
            if(!array_is_list($second))throw new RuntimeException('github page invalid.');
            if(count($second)===100)throw new RuntimeException('github pagination exceeded.');
            return [...$first,...$second];
        };
        $evidence=self::collect($get,$paged,$now);
        $json=json_encode($evidence,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>2_000_000)throw new RuntimeException('evidence too large.');
        self::atomicWrite($evidencePath,$json."\n");
        return ['executed'=>true,'state'=>'written','requests'=>$requests,'bytes'=>strlen($json)];
    }

    private static function collect(callable $get,callable $paged,int $now): array
    {
        $work=[];$blockers=[];$decisions=[];$releases=[];
        foreach(self::REPOS as $name){
            $repo='pl0n3r/'.$name;
            $issues=$paged('/repos/'.$repo.'/issues',['state'=>'open']);
            $closedPrs=$paged('/repos/'.$repo.'/pulls',['state'=>'closed','sort'=>'updated','direction'=>'desc']);
            foreach($issues as $row){
                if(!is_array($row)||array_is_list($row))throw new RuntimeException('github issue invalid.');
                $number=self::positive($row['number']??null);
                if(isset($row['pull_request'])){
                    self::append($work,self::signal('work:'.strtolower($name).'-pr-'.$number,'github_project_snapshot','pending',$repo,$number,['repository_ref'=>$repo,'issue_ref'=>'github:'.$repo.'#'.$number,'status'=>'in_review'],$now));
                    continue;
                }
                $labels=self::labels($row['labels']??[]);$status=self::status($labels);
                if($status!==null)self::append($work,self::signal('work:'.strtolower($name).'-'.$number,'github_project_snapshot',$status==='blocked'?'blocked':'pending',$repo,$number,['repository_ref'=>$repo,'issue_ref'=>'github:'.$repo.'#'.$number,'status'=>$status],$now));
                if($status==='blocked')self::append($blockers,self::signal('blocker:'.strtolower($name).'-'.$number,'github_project_snapshot','blocked',$repo,$number,['issue_ref'=>'github:'.$repo.'#'.$number],$now));
                if(in_array('decisión: dueño',$labels,true)||in_array('decision: owner',$labels,true))
                    self::append($decisions,self::signal('decision:'.strtolower($name).'-'.$number,'owner_inbox','pending',$repo,$number,['issue_ref'=>'github:'.$repo.'#'.$number],$now));
            }
            foreach($closedPrs as $pr){
                if(!is_array($pr)||array_is_list($pr))throw new RuntimeException('github pull invalid.');
                if(($pr['merged_at']??null)===null)continue;
                $n=self::positive($pr['number']??null);
                self::append($releases,self::signal('release:'.strtolower($name).'-'.$n,'github_project_snapshot','healthy',$repo,$n,['repository_ref'=>$repo,'pr_number'=>$n],$now));
                break;
            }
        }
        $factory767=$get('/repos/pl0n3r/Factory/issues/767');
        if(!is_array($factory767)||array_is_list($factory767)||!preg_match('/factory-unattended-kill-switch\s+\{[^}]*"state":"RUNNING"/',$factory767['body']??''))
            self::append($blockers,self::signal('blocker:factory-767','github_project_snapshot','blocked','pl0n3r/Factory',767,['issue_ref'=>'github:pl0n3r/Factory#767'],$now));
        return ['owner_decisions'=>$decisions,'releases'=>$releases,'blockers'=>$blockers,'work'=>$work];
    }

    private static function append(array &$rows,array $row): void
    {if(count($rows)>=self::MAX_SIGNALS)throw new RuntimeException('signal budget exceeded.');$rows[]=$row;}
    private static function signal(string $id,string $authority,string $state,string $repo,int $n,array $data,int $now): array
    {return ['id'=>$id,'authority'=>$authority,'state'=>$state,'source_ref'=>'github:'.$repo.'#'.$n,'observed_at'=>$now,'freshness'=>'current','data'=>$data];}
    private static function labels(mixed $raw): array
    {if(!is_array($raw)||!array_is_list($raw))throw new RuntimeException('labels invalid.');$out=[];foreach($raw as $x){$v=is_array($x)?($x['name']??null):$x;if(is_string($v))$out[]=mb_strtolower(trim($v));}return $out;}
    private static function status(array $labels): ?string
    {foreach(['available'=>'estado: disponible','reserved'=>'estado: reservado','in_review'=>'estado: en revisión','blocked'=>'estado: bloqueado'] as $s=>$l)if(in_array($l,$labels,true))return $s;foreach(['available'=>'status: available','reserved'=>'status: reserved','in_review'=>'status: in review','blocked'=>'status: blocked'] as $s=>$l)if(in_array($l,$labels,true))return $s;return null;}
    private static function positive(mixed $v): int
    {if(!is_int($v)||$v<1)throw new RuntimeException('github number invalid.');return $v;}
    private static function path(mixed $v,string $label,bool $mustExist): string
    {if(!is_string($v)||$v===''||!str_starts_with($v,DIRECTORY_SEPARATOR)||is_link($v)||str_contains($v,'/public_html/')||($mustExist&&!is_file($v)))throw new InvalidArgumentException($label.' path invalid.');return $v;}
    private static function token(string $path): string
    {$size=filesize($path);$mode=fileperms($path)&0777;if(!is_int($size)||$size<1||$size>1024||!in_array($mode,[0600,0640],true))throw new RuntimeException('credential file invalid.');$v=trim((string)file_get_contents($path));if($v===''||strlen($v)>1000||preg_match('/[\x00-\x20\x7f]/',$v))throw new RuntimeException('credential file invalid.');return $v;}
    private static function atomicWrite(string $path,string $data): void
    {$dir=dirname($path);if(!is_dir($dir))throw new RuntimeException('evidence directory invalid.');$tmp=tempnam($dir,'.orchestrator-evidence-');if($tmp===false)throw new RuntimeException('evidence write failed.');try{chmod($tmp,0600);if(file_put_contents($tmp,$data,LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('evidence write failed.');}finally{if(is_file($tmp))@unlink($tmp);}}
}

<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use RuntimeException;

final class FactoryOrchestratorEvidenceCollector
{
    private const REPOS=['Factory','Condor','GrindFlow','brvtal','ControlBot','AutoFactory','FactoryRunner'];
    private const MAX_REQUESTS=40;

    public static function run(array $env,callable $transport,int $now): array
    {
        if(($env['CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED']??'')!=='1')return ['executed'=>false,'state'=>'disabled'];
        if($now<1)throw new InvalidArgumentException('collector clock invalid.');
        $tokenPath=self::path($env['CONTROLBOT_GITHUB_READ_TOKEN_FILE']??'','token');
        $evidencePath=self::path($env['CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH']??'','evidence',false);
        $token=self::token($tokenPath);$requests=0;
        $get=static function(string $path,array $query=[])use($transport,$token,&$requests):array{
            if(++$requests>self::MAX_REQUESTS)throw new RuntimeException('request budget exceeded.');
            $url='https://api.github.com'.$path.($query?'?'.http_build_query($query):'');
            for($attempt=0;$attempt<2;$attempt++){
                $r=$transport('GET',$url,['Accept'=>'application/vnd.github+json','Authorization'=>'Bearer '.$token]);
                if(!is_array($r)||array_is_list($r)||!is_int($r['status']??null)||!is_array($r['headers']??null))throw new RuntimeException('transport invalid.');
                $remaining=$r['headers']['x-ratelimit-remaining']??null;
                if(is_numeric($remaining)&&(int)$remaining<5)throw new RuntimeException('rate limit low.');
                if(($r['status']===429||$r['status']>=500)&&$attempt===0&&!isset($r['headers']['retry-after']))continue;
                if($r['status']!==200||!is_array($r['json']??null))throw new RuntimeException('github read failed.');
                return $r['json'];
            }
            throw new RuntimeException('github read failed.');
        };
        $evidence=self::collect($get,$now);
        $json=json_encode($evidence,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>2_000_000)throw new RuntimeException('evidence too large.');
        self::atomicWrite($evidencePath,$json."\n");
        return ['executed'=>true,'state'=>'written','requests'=>$requests,'bytes'=>strlen($json)];
    }

    private static function collect(callable $get,int $now): array
    {
        $work=[];$blockers=[];$decisions=[];$releases=[];$projects=[];
        foreach(self::REPOS as $name){
            $repo='pl0n3r/'.$name;
            $issues=$get('/repos/'.$repo.'/issues',['state'=>'open','per_page'=>100]);
            $openPrs=$get('/repos/'.$repo.'/pulls',['state'=>'open','per_page'=>100]);
            $closedPrs=$get('/repos/'.$repo.'/pulls',['state'=>'closed','per_page'=>10]);
            $counts=['completed'=>0,'available'=>0,'reserved'=>0,'blocked'=>0,'unmaterialized'=>0,'decision_required'=>0,'live_only'=>0,'future_idea'=>0,'already_materialized'=>0];
            $next=null;
            foreach($issues as $issue){
                if(!is_array($issue)||isset($issue['pull_request']))continue;
                $number=self::positive($issue['number']??null);$labels=self::labels($issue['labels']??[]);$status=self::status($labels);
                if($status!==null){
                    if(isset($counts[$status]))$counts[$status]++;
                    if($status==='available'&&$next===null)$next=$name.'#'.$number;
                    $work[]=self::signal('work:'.strtolower($name).'-'.$number,'github_project_snapshot',$status==='blocked'?'blocked':'pending',$repo,$number,['repository_ref'=>$repo,'issue_ref'=>'github:'.$repo.'#'.$number,'status'=>$status],$now);
                }
                if($status==='blocked')$blockers[]=self::signal('blocker:'.strtolower($name).'-'.$number,'github_project_snapshot','blocked',$repo,$number,['issue_ref'=>'github:'.$repo.'#'.$number],$now);
                if(in_array('decisión: dueño',$labels,true)||in_array('decision: owner',$labels,true)){
                    $counts['decision_required']++;
                    $decisions[]=self::signal('decision:'.strtolower($name).'-'.$number,'owner_inbox','pending',$repo,$number,['issue_ref'=>'github:'.$repo.'#'.$number],$now);
                }
            }
            foreach($closedPrs as $pr)if(is_array($pr)&&($pr['merged_at']??null)!==null){
                $n=self::positive($pr['number']??null);$releases[]=self::signal('release:'.strtolower($name).'-'.$n,'github_project_snapshot','healthy',$repo,$n,['repository_ref'=>$repo,'pr_number'=>$n],$now);break;
            }
            $state=$counts['available']>0?'READY':($counts['decision_required']>0?'WAITING_DECISION':($counts['blocked']>0?'ALL_BLOCKED':'NO_WORK'));
            $projects[]=['repository_ref'=>$repo,'state'=>$state,'counts'=>$counts,'next_work'=>$state==='READY'?$next:null,'unmaterialized_identities'=>[],'parent_progress'=>[]];
        }
        $factory767=$get('/repos/pl0n3r/Factory/issues/767');
        if(!preg_match('/factory-unattended-kill-switch\s+\{[^}]*"state":"RUNNING"/',$factory767['body']??''))
            $blockers[]=self::signal('blocker:factory-767','github_project_snapshot','blocked','pl0n3r/Factory',767,['issue_ref'=>'github:pl0n3r/Factory#767'],$now);
        return ['owner_decisions'=>$decisions,'releases'=>$releases,'blockers'=>$blockers,'work'=>$work,'work_inventory'=>['version'=>1,'source_ref'=>'github:api/factory-inventory','observed_at'=>$now,'freshness'=>'current','projects'=>$projects]];
    }

    private static function signal(string $id,string $authority,string $state,string $repo,int $n,array $data,int $now): array
    {return ['id'=>$id,'authority'=>$authority,'state'=>$state,'source_ref'=>'github:'.$repo.'#'.$n,'observed_at'=>$now,'freshness'=>'current','data'=>$data];}
    private static function labels(mixed $raw): array
    {if(!is_array($raw)||!array_is_list($raw))throw new RuntimeException('labels invalid.');$out=[];foreach($raw as $x){$v=is_array($x)?($x['name']??null):$x;if(is_string($v))$out[]=mb_strtolower(trim($v));}return $out;}
    private static function status(array $labels): ?string
    {foreach(['available'=>'estado: disponible','reserved'=>'estado: reservado','in_review'=>'estado: en revisión','blocked'=>'estado: bloqueado'] as $s=>$l)if(in_array($l,$labels,true))return $s;foreach(['available'=>'status: available','reserved'=>'status: reserved','in_review'=>'status: in review','blocked'=>'status: blocked'] as $s=>$l)if(in_array($l,$labels,true))return $s;return null;}
    private static function positive(mixed $v): int
    {if(!is_int($v)||$v<1)throw new RuntimeException('github number invalid.');return $v;}
    private static function path(mixed $v,string $label,bool $mustExist=true): string
    {if(!is_string($v)||$v===''||!str_starts_with($v,DIRECTORY_SEPARATOR)||is_link($v)||($mustExist&&!is_file($v)))throw new InvalidArgumentException($label.' path invalid.');return $v;}
    private static function token(string $path): string
    {$size=filesize($path);$mode=fileperms($path)&0777;if(!is_int($size)||$size<1||$size>1024||!in_array($mode,[0600,0640],true))throw new RuntimeException('token file invalid.');$v=trim((string)file_get_contents($path));if($v===''||strlen($v)>1000||preg_match('/[\x00-\x20\x7f]/',$v))throw new RuntimeException('token file invalid.');return $v;}
    private static function atomicWrite(string $path,string $data): void
    {$dir=dirname($path);if(!is_dir($dir))throw new RuntimeException('evidence directory invalid.');$tmp=tempnam($dir,'.orchestrator-evidence-');if($tmp===false)throw new RuntimeException('evidence write failed.');try{if(file_put_contents($tmp,$data,LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('evidence write failed.');}finally{if(is_file($tmp))@unlink($tmp);}}
}

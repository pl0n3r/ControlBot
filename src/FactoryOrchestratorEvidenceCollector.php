<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FactoryOrchestratorEvidenceCollector
{
    private const REPOS=['Factory','Condor','GrindFlow','brvtal','ControlBot','AutoFactory','FactoryRunner'];
    private const WORKFLOW_LABELS=['estado: disponible','estado: reservado','estado: en revisión','estado: bloqueado','status: available','status: reserved','status: in review','status: blocked'];
    private const MAX_REQUESTS=40;
    private const MAX_RESPONSE_BYTES=2_000_000;
    private const MAX_DOWNLOAD_BYTES=8_000_000;
    private const MAX_EVIDENCE_BYTES=2_000_000;
    private const CLOSED_PULLS_PER_PAGE=10;
    private const MAX_SIGNALS=50;
    private const MAX_WORK_SIGNALS=24;
    private const MAX_DECISION_BODY_BYTES=65_536;
    private const MAX_DECISION_OPTIONS=6;
    private const DECISION_OPTION_IDS=['A','B','C','D'];

    public static function validateLiveRequest(
        string $method,
        string $url,
    ): void {
        if ($method !== 'GET') {
            throw new RuntimeException('method denied.');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new RuntimeException('github url denied.');
        }
        if (($parts['scheme'] ?? null) !== 'https') {
            throw new RuntimeException('github url denied.');
        }
        if (($parts['host'] ?? null) !== 'api.github.com') {
            throw new RuntimeException('github url denied.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('github url denied.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new RuntimeException('github url denied.');
        }
    }

    public static function normalizeLiveResponse(
        int $status,
        array $headers,
        mixed $body,
    ): array {
        if (!is_string($body) || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('github response invalid.');
        }
        $response = [
            'status' => $status,
            'headers' => $headers,
            'bytes' => strlen($body),
        ];
        if ($status !== 200) {
            return $response;
        }
        try {
            $json = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('github json invalid.');
        }
        if (!is_array($json)) {
            throw new RuntimeException('github json invalid.');
        }
        return $response + ['json' => $json];
    }

    public static function diagnosticFor(Throwable $error): array
    {
        $message=$error->getMessage();
        if(preg_match('/^github http status ([1-5][0-9]{2}) path (\/[^?\s#]+)$/',$message,$matches)===1){
            return ['code'=>'http_status_'.$matches[1],'path'=>$matches[2],'status'=>(int)$matches[1]];
        }
        $codes=[
            'request budget exceeded.'=>'request_budget_exceeded',
            'download byte budget exceeded.'=>'download_budget_exceeded',
            'credential file invalid.'=>'token_file_invalid',
            'token path invalid.'=>'token_file_invalid',
            'evidence path invalid.'=>'evidence_path_unwritable',
            'evidence directory invalid.'=>'evidence_path_unwritable',
            'evidence write failed.'=>'evidence_path_unwritable',
            'github transport unavailable.'=>'transport_unavailable',
            'transport invalid.'=>'transport_unavailable',
            'github retry deferred.'=>'rate_limited',
            'rate limit low.'=>'rate_limited',
            'github response invalid.'=>'github_response_invalid',
            'github json invalid.'=>'github_response_invalid',
            'github page invalid.'=>'github_response_invalid',
            'github pagination exceeded.'=>'github_response_invalid',
            'github pull page invalid.'=>'github_response_invalid',
            'github issue invalid.'=>'github_response_invalid',
            'github pull invalid.'=>'github_response_invalid',
            'github number invalid.'=>'github_response_invalid',
            'labels invalid.'=>'github_response_invalid',
            'github read failed.'=>'github_read_failed',
            'evidence too large.'=>'evidence_budget_exceeded',
            'active work signal budget exceeded.'=>'signal_budget_exceeded',
            'signal budget exceeded.'=>'signal_budget_exceeded',
            'method denied.'=>'request_policy_denied',
            'github url denied.'=>'request_policy_denied',
            'collector clock invalid.'=>'clock_invalid',
        ];
        return ['code'=>$codes[$message]??'internal_error'];
    }

    public static function run(array $env,callable $transport,int $now): array
    {
        if(($env['CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED']??'')!=='1')return ['executed'=>false,'state'=>'disabled'];
        if($now<1)throw new InvalidArgumentException('collector clock invalid.');
        $tokenPath=self::path($env['CONTROLBOT_GITHUB_READ_TOKEN_FILE']??'','token',true);
        $evidencePath=self::path($env['CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH']??'','evidence',false);
        $token=self::token($tokenPath);$requests=0;$downloadBytes=0;
        $get=static function(string $path,array $query=[])use($transport,$token,&$requests,&$downloadBytes):array{
            $url='https://api.github.com'.$path.($query?'?'.http_build_query($query):'');
            for($attempt=0;$attempt<2;$attempt++){
                if(++$requests>self::MAX_REQUESTS)throw new RuntimeException('request budget exceeded.');
                $r=$transport('GET',$url,[
                    'Accept'=>'application/vnd.github+json',
                    'Authorization'=>'Bearer '.$token,
                    'User-Agent'=>'controlbot-orchestrator-evidence-collector/1',
                    'X-GitHub-Api-Version'=>'2022-11-28',
                ]);
                if (!is_array($r) || array_is_list($r)) {
                    throw new RuntimeException('transport invalid.');
                }
                if (
                    !is_int($r['status'] ?? null)
                    || !is_array($r['headers'] ?? null)
                ) {
                    throw new RuntimeException('transport invalid.');
                }
                if (
                    !array_key_exists('bytes', $r)
                    || !is_int($r['bytes'])
                    || $r['bytes'] < 0
                ) {
                    throw new RuntimeException('transport invalid.');
                }
                $downloadBytes += $r['bytes'];
                if ($downloadBytes > self::MAX_DOWNLOAD_BYTES) {
                    throw new RuntimeException('download byte budget exceeded.');
                }
                if(isset($r['headers']['retry-after']))throw new RuntimeException('github retry deferred.');
                $remaining=$r['headers']['x-ratelimit-remaining']??null;
                if(is_numeric($remaining)&&(int)$remaining<5)throw new RuntimeException('rate limit low.');
                if($r['status']>=500&&$attempt===0)continue;
                if($r['status']!==200)throw new RuntimeException('github http status '.$r['status'].' path '.$path);
                if (!is_array($r['json'] ?? null)) {
                    throw new RuntimeException('github json invalid.');
                }
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
        $evidenceBytes=strlen($json);
        if($evidenceBytes>self::MAX_EVIDENCE_BYTES)throw new RuntimeException('evidence too large.');
        self::atomicWrite($evidencePath,$json."\n");
        return ['executed'=>true,'state'=>'written','requests'=>$requests,'download_bytes'=>$downloadBytes,'evidence_bytes'=>$evidenceBytes];
    }

    private static function collect(callable $get,callable $paged,int $now): array
    {
        $work=[];$blockers=[];$decisions=[];
        foreach(self::REPOS as $name){
            $repo='pl0n3r/'.$name;
            $issues=$paged('/repos/'.$repo.'/issues',['state'=>'open']);
            $closedPrs=$get('/repos/'.$repo.'/pulls',['state'=>'closed','sort'=>'updated','direction'=>'desc','per_page'=>self::CLOSED_PULLS_PER_PAGE,'page'=>1]);
            if(!array_is_list($closedPrs))throw new RuntimeException('github pull page invalid.');
            foreach($issues as $row){
                if(!is_array($row)||array_is_list($row))throw new RuntimeException('github issue invalid.');
                $number=self::positive($row['number']??null);
                if(isset($row['pull_request'])){
                    self::appendWork($work,self::signal('work:'.strtolower($name).'-pr-'.$number,'github_project_snapshot','pending',$repo,$number,['repository_ref'=>$repo,'issue_ref'=>'github:'.$repo.'#'.$number,'status'=>'in_review'],$now));
                    continue;
                }
                $labels=self::labels($row['labels']??[]);$workflowLabels=self::workflowLabels($labels);
                if(in_array('estado: bloqueado',$workflowLabels,true)||in_array('status: blocked',$workflowLabels,true))
                    self::append($blockers,self::signal('blocker:'.strtolower($name).'-'.$number,'github_project_snapshot','blocked',$repo,$number,['issue_ref'=>'github:'.$repo.'#'.$number,'labels'=>$workflowLabels],$now));
                elseif($workflowLabels!==[])
                    self::appendWork($work,self::signal('work:'.strtolower($name).'-'.$number,'github_project_snapshot','pending',$repo,$number,['repository_ref'=>$repo,'issue_ref'=>'github:'.$repo.'#'.$number,'labels'=>$workflowLabels],$now));
                if(in_array('decisión: dueño',$labels,true)||in_array('decision: owner',$labels,true))
                    self::append($decisions,self::signal(
                        'decision:'.strtolower($name).'-'.$number,
                        'owner_inbox',
                        'pending',
                        $repo,
                        $number,
                        self::ownerDecisionData($row,$repo,$number),
                        $now,
                    ));
            }
            foreach($closedPrs as $pr){
                if(!is_array($pr)||array_is_list($pr))throw new RuntimeException('github pull invalid.');
                if(($pr['merged_at']??null)===null)continue;
                $n=self::positive($pr['number']??null);
                self::appendWork($work,self::signal('work:'.strtolower($name).'-pr-'.$n,'github_project_snapshot','healthy',$repo,$n,['repository_ref'=>$repo,'issue_ref'=>'github:'.$repo.'#'.$n,'status'=>'merged'],$now));
                break;
            }
        }
        $factory767=$get('/repos/pl0n3r/Factory/issues/767');
        if(!self::killSwitchRunning($factory767))
            self::append($blockers,self::signal('blocker:factory-767','github_project_snapshot','blocked','pl0n3r/Factory',767,['issue_ref'=>'github:pl0n3r/Factory#767'],$now));
        return ['owner_decisions'=>$decisions,'releases'=>[],'blockers'=>$blockers,'work'=>$work];
    }

    private static function killSwitchRunning(mixed $issue): bool
    {
        if (!is_array($issue) || array_is_list($issue)) {
            return false;
        }
        if (($issue['number'] ?? null) !== 767) {
            return false;
        }
        if (($issue['user']['login'] ?? null) !== 'pl0n3r') {
            return false;
        }
        if (!is_string($issue['body'] ?? null)) {
            return false;
        }
        $count=preg_match_all('/<!--\s*factory-unattended-kill-switch\s+(\{.*?\})\s*-->/s',$issue['body'],$matches);
        if($count!==1)return false;
        try{$marker=json_decode($matches[1][0],true,8,JSON_THROW_ON_ERROR);}catch(\Throwable){return false;}
        if(!is_array($marker)||array_is_list($marker))return false;
        $keys=array_keys($marker);sort($keys);
        return $keys===['owner','state','version']
            &&($marker['version']??null)===1
            &&($marker['state']??null)==='RUNNING'
            &&($marker['owner']??null)==='pl0n3r';
    }

    private static function ownerDecisionData(array $row, string $repo, int $number): array
    {
        $title=self::boundedUntrustedText($row['title']??null,160)??('Issue '.$number);
        $legacy=[
            'issue_ref'=>'github:'.$repo.'#'.$number,'repository_ref'=>$repo,'issue_number'=>$number,
            'format'=>'legacy','title'=>$title,'title_simple'=>null,'summary_simple'=>null,
            'explain_simple'=>null,'why_recommended'=>null,'blocks'=>null,'options'=>[],
            'recommendation'=>null,'safe_default'=>null,'expires_at'=>null,
        ];
        $body=$row['body']??null;
        if(!is_string($body)||strlen($body)>self::MAX_DECISION_BODY_BYTES)return $legacy;
        $gate=self::decisionMarker($body,'factory-human-gate',16);
        if($gate===null)return $legacy;

        $fields=[
            'title_simple'=>self::boundedPlainText($gate['title_simple']??null,160),
            'summary_simple'=>self::boundedPlainText($gate['summary_simple']??null,600),
            'explain_simple'=>self::boundedPlainText($gate['explain_simple']??null,600,true),
            'why_recommended'=>self::boundedPlainText($gate['why_recommended']??null,600),
            'blocks'=>self::boundedPlainText($gate['blocks']??null,600),
        ];
        $recommendation=$gate['recommendation']??null;
        $safeDefault=$gate['safe_default']??null;
        $options=self::decisionOptions($gate['options']??null);
        if(in_array(null,[$fields['title_simple'],$fields['summary_simple'],$fields['why_recommended'],$fields['blocks'],$options],true)
            ||!is_string($recommendation)||!is_string($safeDefault))return $legacy;

        $ids=array_column($options,'id');
        if(!in_array($recommendation,$ids,true)||!in_array($safeDefault,$ids,true))return $legacy;
        [$windowValid,$expiresAt]=self::decisionReleaseExpiry($body);
        if(!$windowValid)return $legacy;
        return array_replace($legacy,$fields,[
            'format'=>'structured','options'=>$options,'recommendation'=>$recommendation,
            'safe_default'=>$safeDefault,'expires_at'=>$expiresAt,
        ]);
    }

    private static function decisionMarker(string $body,string $name,int $depth): ?array
    {
        $pattern='/<!--\\s*'.preg_quote($name,'/').'\\s+(.+?)\\s*-->/s';
        if(preg_match_all($pattern,$body,$matches)!==1)return null;
        try{$value=json_decode($matches[1][0],true,$depth,JSON_THROW_ON_ERROR);}
        catch(Throwable){return null;}
        return is_array($value)&&!array_is_list($value)?$value:null;
    }

    private static function decisionOptions(mixed $raw): ?array
    {
        if(!is_array($raw)||!array_is_list($raw)||$raw===[]||count($raw)>self::MAX_DECISION_OPTIONS)return null;
        $out=[];$ids=[];
        foreach($raw as $option){
            $id=is_array($option)&&!array_is_list($option)?($option['id']??null):null;
            if(!is_string($id)||!in_array($id,self::DECISION_OPTION_IDS,true)
                ||isset($ids[$id])||!is_bool($option['reversible']??null))return null;
            $pros=self::boundedTextList($option['pros']??null,6,300);
            $cons=self::boundedTextList($option['cons']??null,6,300);
            $label=self::boundedPlainText($option['label']??null,200);
            $effect=self::boundedPlainText($option['effect']??null,600);
            $risk=self::boundedPlainText($option['risk']??null,80);
            if(in_array(null,[$pros,$cons,$label,$effect,$risk],true))return null;
            $ids[$id]=true;
            $out[]=[
                'id'=>$id,'label'=>$label,'effect'=>$effect,'pros'=>$pros,'cons'=>$cons,'risk'=>$risk,
                'cost'=>self::boundedPlainText($option['cost']??null,240,true),
                'reversible'=>$option['reversible'],
                'explain_simple'=>self::boundedPlainText($option['explain_simple']??null,400,true),
            ];
        }
        return $out;
    }

    private static function decisionReleaseExpiry(string $body): array
    {
        $pattern='/<!--\\s*factory-release-window\\s+(.+?)\\s*-->/s';
        $count=preg_match_all($pattern,$body,$matches);
        if($count===0)return [true,null];
        if($count!==1)return [false,null];
        try{$window=json_decode($matches[1][0],true,8,JSON_THROW_ON_ERROR);}
        catch(Throwable){return [false,null];}
        $expires=is_array($window)&&!array_is_list($window)?($window['expires_at']??null):null;
        $valid=is_string($expires)
            &&preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/D',$expires)===1
            &&strtotime($expires)!==false;
        return [$valid,$valid?$expires:null];
    }

    private static function boundedTextList(mixed $raw,int $maxItems,int $maxLength): ?array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>$maxItems)return null;
        $out=[];
        foreach($raw as $value){
            $text=self::boundedPlainText($value,$maxLength);
            if($text===null)return null;
            $out[]=$text;
        }
        return $out;
    }

    private static function boundedUntrustedText(mixed $value,int $maxLength): ?string
    {
        if(!is_string($value))return null;
        $value=trim(preg_replace('/\\s+/u',' ',$value)??'');
        $valid=$value!==''&&strlen($value)<=$maxLength&&preg_match('/[\\x00-\\x1f\\x7f]/',$value)!==1;
        return $valid?$value:null;
    }

    private static function boundedPlainText(mixed $value,int $maxLength,bool $optional=false): ?string
    {
        if($value===null&&$optional)return null;
        if(!is_string($value))return null;
        $value=trim(preg_replace('/\\s+/u',' ',$value)??'');
        $valid=$value!==''&&strlen($value)<=$maxLength&&preg_match('/[\\x00-\\x1f\\x7f]/',$value)!==1
            &&!str_contains($value,'<')&&!str_contains($value,'>');
        return $valid?$value:null;
    }

    private static function appendWork(array &$rows,array $row): void
    {if(count($rows)>=self::MAX_WORK_SIGNALS)throw new RuntimeException('active work signal budget exceeded.');$rows[]=$row;}
    private static function append(array &$rows,array $row): void
    {if(count($rows)>=self::MAX_SIGNALS)throw new RuntimeException('signal budget exceeded.');$rows[]=$row;}
    private static function signal(string $id,string $authority,string $state,string $repo,int $n,array $data,int $now): array
    {return ['id'=>$id,'authority'=>$authority,'state'=>$state,'source_ref'=>'github:'.$repo.'#'.$n,'observed_at'=>$now,'freshness'=>'current','data'=>$data];}
    private static function labels(mixed $raw): array
    {if(!is_array($raw)||!array_is_list($raw))throw new RuntimeException('labels invalid.');$out=[];foreach($raw as $x){$v=is_array($x)?($x['name']??null):$x;if(is_string($v))$out[]=mb_strtolower(trim($v));}return $out;}
    private static function workflowLabels(array $labels): array
    {
        $out=[];
        foreach($labels as $label)if(in_array($label,self::WORKFLOW_LABELS,true)&&!in_array($label,$out,true))$out[]=$label;
        return $out;
    }
    private static function positive(mixed $v): int
    {if(!is_int($v)||$v<1)throw new RuntimeException('github number invalid.');return $v;}
    private static function path(mixed $v,string $label,bool $mustExist): string
    {if(!is_string($v)||$v===''||!str_starts_with($v,DIRECTORY_SEPARATOR)||is_link($v)||str_contains($v,'/public_html/')||($mustExist&&!is_file($v)))throw new InvalidArgumentException($label.' path invalid.');return $v;}
    private static function token(string $path): string
    {$size=filesize($path);$mode=fileperms($path)&0777;if(!is_int($size)||$size<1||$size>1024||!in_array($mode,[0600,0640],true))throw new RuntimeException('credential file invalid.');$v=trim((string)file_get_contents($path));if($v===''||strlen($v)>1000||preg_match('/[\x00-\x20\x7f]/',$v))throw new RuntimeException('credential file invalid.');return $v;}
    private static function atomicWrite(string $path,string $data): void
    {$dir=dirname($path);if(!is_dir($dir))throw new RuntimeException('evidence directory invalid.');$tmp=tempnam($dir,'.orchestrator-evidence-');if($tmp===false)throw new RuntimeException('evidence write failed.');try{chmod($tmp,0600);if(file_put_contents($tmp,$data,LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('evidence write failed.');}finally{if(is_file($tmp))@unlink($tmp);}}
}

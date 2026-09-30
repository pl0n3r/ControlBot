<?php
declare(strict_types=1);

namespace ControlBot\Requirements;

use ControlBot\Approvals\OwnerContext;
use ControlBot\Project\ProjectModel;
use InvalidArgumentException;
use RuntimeException;

interface RequirementMaterializationGateway
{
    public function ensureProjectLink(string $projectRef, string $idempotencyKey): string;
    public function ensureProjectCreate(array $project, string $idempotencyKey): string;
    public function ensureEpic(string $projectRef, array $epic, string $idempotencyKey): string;
    public function ensureIssue(string $epicRef, array $issue, string $idempotencyKey): string;
}

final class RequirementMaterializer
{
    private const SENSITIVE='/(?:-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?-----END [^-]*PRIVATE KEY-----|\bbearer\s+[A-Za-z0-9._~+\/-]{8,}|\b(?:password|passwd|token|secret|api[_ -]?key|private[_ -]?key|dsn|otp|recovery[_ -]?code|session[_ -]?token)\s*[:=]\s*[^\s,;]+|\b(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|\b(?:sk|rk|pk)-[A-Za-z0-9_-]{12,})/i';

    public function __construct(private readonly RequirementMaterializationGateway $gateway) {}

    public function materialize(array $proposal, array $decision, array $approval, OwnerContext $owner, int $now): array
    {
        $owner->assertFresh($now);
        $canonical=RequirementIntakeDecisionUi::project($proposal);
        if(!hash_equals(self::digest($canonical),self::digest($decision))) self::bad('decision');
        $a=$this->approval($approval,$canonical,$proposal);
        $seed=self::digest(['decision_ref'=>$a['decision_ref'],'decision_fingerprint'=>$a['decision_fingerprint'],'proposal_ref'=>$a['proposal_ref'],'proposal_fingerprint'=>$a['proposal_fingerprint']]);
        $projectKey=self::key($seed,'project');$project=$canonical['materialization_diff']['project'];
        $projectRef=match($project['operation']){
            'link_existing'=>$this->linkProject($project,$a,$projectKey),
            'owner_choice_required'=>$this->createProject($a,$projectKey),
            default=>throw new RuntimeException('Project materialization requires owner resolution.'),
        };
        $epicKey=self::key($seed,'epic');
        $epicRef=self::typedRef($this->gateway->ensureEpic($projectRef,$canonical['materialization_diff']['epic'],$epicKey),'epic');
        $issues=[];
        foreach($canonical['materialization_diff']['issues'] as $issue){
            $key=self::key($seed,'issue:'.$issue['slice_key']);
            $issues[]=['slice_key'=>$issue['slice_key'],'ref'=>self::typedRef($this->gateway->ensureIssue($epicRef,$issue,$key),'issue'),'idempotency_key'=>$key];
        }
        $base=['version'=>1,'proposal_ref'=>$a['proposal_ref'],'proposal_fingerprint'=>$a['proposal_fingerprint'],'decision_ref'=>$a['decision_ref'],'decision_fingerprint'=>$a['decision_fingerprint'],'project_ref'=>$projectRef,'epic_ref'=>$epicRef,'issues'=>$issues,'idempotency_keys'=>['project'=>$projectKey,'epic'=>$epicKey],'replay_safe'=>true];
        $out=['receipt_ref'=>'requirement-materialization:'.substr(self::digest($base),0,40)]+$base;
        self::secretFree($out);return $out;
    }

    private function approval(array $a,array $d,array $p): array
    {
        self::fields($a,['version','decision_ref','decision_fingerprint','proposal_ref','proposal_fingerprint','option','project_resolution'],'approval');
        if(($a['version']??null)!==1||($a['option']??null)!=='approve') self::bad('approval state');
        foreach(['decision_ref'=>$d['view_ref'],'decision_fingerprint'=>$d['fingerprint'],'proposal_ref'=>$p['proposal_ref'],'proposal_fingerprint'=>$p['fingerprint']] as $k=>$v){if(!is_string($a[$k])||!hash_equals($v,$a[$k])) self::bad($k);}
        return $a;
    }

    private function linkProject(array $project,array $a,string $key): string
    {
        if($a['project_resolution']!==null||!is_string($project['project_ref'])) self::bad('project resolution');
        $ref=$this->gateway->ensureProjectLink($project['project_ref'],$key);
        if(!hash_equals($project['project_ref'],$ref)) self::bad('project link receipt');
        return $ref;
    }

    private function createProject(array $a,string $key): string
    {
        $r=$a['project_resolution'];
        self::fields($r,['operation','project_id','slug','title','phase','priority'],'project_resolution');
        if(($r['operation']??null)!=='create_project') self::bad('project_resolution.operation');
        self::secretFree($r);
        $project=ProjectModel::normalize(['version'=>1,'project_id'=>$r['project_id'],'slug'=>$r['slug'],'title'=>$r['title'],'phase'=>$r['phase'],'priority'=>$r['priority'],'repositories'=>[],'environments'=>[],'aggregate_refs'=>['roadmap'=>null,'agents'=>null,'decisions'=>null,'health'=>null,'incidents'=>null,'costs'=>null],'history_refs'=>[]]);
        $ref=$this->gateway->ensureProjectCreate($project,$key);$expected='controlbot:project/'.$project['slug'];
        if(!hash_equals($expected,$ref)) self::bad('project create receipt');
        return $ref;
    }

    private static function key(string $seed,string $artifact): string{return hash('sha256',$seed.'|'.$artifact);}
    private static function typedRef(mixed $v,string $type): string
    {if(!is_string($v)||preg_match('/^controlbot:'.preg_quote($type,'/').'\/[a-f0-9]{40}$/D',$v)!==1)self::bad($type.' ref');return $v;}
    private static function digest(array $v): string{return hash('sha256',serialize(self::ordered($v)));}
    private static function ordered(mixed $v): mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::ordered($x);return $v;}
    private static function secretFree(mixed $v): void{$s=is_string($v)?$v:json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(preg_match(self::SENSITIVE,$s))self::bad('sensitive material');}
    private static function fields(mixed $r,array $e,string $label): void{if(!is_array($r)||array_is_list($r))self::bad($label);$k=array_keys($r);sort($k);sort($e);if($k!==$e)self::bad($label.' fields');}
    private static function bad(string $m): never{throw new InvalidArgumentException($m.' invalid.');}
}

<?php
declare(strict_types=1);

require_once __DIR__.'/../src/RequirementIntake.php';
require_once __DIR__.'/../src/RequirementIntakeDecisionUi.php';
require_once __DIR__.'/../src/ProjectModel.php';
require_once __DIR__.'/../src/Approvals.php';
require_once __DIR__.'/../src/RequirementMaterializer.php';

use ControlBot\Approvals\OwnerContext;
use ControlBot\Requirements\RequirementIntake;
use ControlBot\Requirements\RequirementIntakeDecisionUi;
use ControlBot\Requirements\RequirementMaterializationGateway;
use ControlBot\Requirements\RequirementMaterializer;

final class FakeRequirementGateway implements RequirementMaterializationGateway
{
    public array $counts=['link'=>0,'create'=>0,'epic'=>0,'issue'=>0];
    private array $seen=[];
    private function keep(string $kind,string $key,mixed $payload,string $ref): string
    {
        $fp=hash('sha256',serialize($payload));
        if(isset($this->seen[$key])){if($this->seen[$key]['fp']!==$fp)throw new RuntimeException('idempotency conflict');return $this->seen[$key]['ref'];}
        $this->seen[$key]=['fp'=>$fp,'ref'=>$ref];$this->counts[$kind]++;return $ref;
    }
    public function ensureProjectLink(string $projectRef,string $key): string{return $this->keep('link',$key,$projectRef,$projectRef);}
    public function ensureProjectCreate(array $project,string $key): string{return $this->keep('create',$key,$project,'controlbot:project/'.$project['slug']);}
    public function ensureEpic(string $projectRef,array $epic,string $key): string{return $this->keep('epic',$key,[$projectRef,$epic],'controlbot:epic/'.sha1($key));}
    public function ensureIssue(string $epicRef,array $issue,string $key): string{return $this->keep('issue',$key,[$epicRef,$issue],'controlbot:issue/'.sha1($key));}
}

function proposal(string $mode): array
{
    $name=$mode==='matched'?'ControlBot':($mode==='ambiguous'?'Creator':'New venture');
    $draft=RequirementIntake::normalize(['version'=>1,'source_kind'=>'text','source_ref'=>'controlbot:intake/demo','captured_at'=>1000,'content'=>"problem: Build $name requirement flow\nuser: owner\nobjectives: structure approved work\nout_of_scope: repositories; deploys\nconstraints: preserve approval"]);
    $projects=match($mode){
        'matched'=>[['project_ref'=>'controlbot:project/controlbot','title'=>'ControlBot','aliases'=>[]]],
        'ambiguous'=>[['project_ref'=>'controlbot:project/creator-a','title'=>'Creator','aliases'=>[]],['project_ref'=>'controlbot:project/creator-b','title'=>'Creator','aliases'=>[]]],
        default=>[],
    };
    return RequirementIntake::analyze($draft,$projects);
}
function resolution(): array{return ['operation'=>'create_project','project_id'=>'project-new-venture','slug'=>'new-venture','title'=>'New Venture','phase'=>'discovery','priority'=>'high'];}
function approval(array $p,array $v,mixed $r=null,string $option='approve'): array{return ['version'=>1,'decision_ref'=>$v['view_ref'],'decision_fingerprint'=>$v['fingerprint'],'proposal_ref'=>$p['proposal_ref'],'proposal_fingerprint'=>$p['fingerprint'],'option'=>$option,'project_resolution'=>$r];}
function blocked(callable $fn): bool{try{$fn();return false;}catch(Throwable){return true;}}
function mutations(FakeRequirementGateway $g): int{return array_sum($g->counts);}
function runner(string $mode): array
{
    $owner=new OwnerContext('pl0n3r',true,1000);
    if($mode==='idempotent'){
        $p=proposal('none');$v=RequirementIntakeDecisionUi::project($p);$g=new FakeRequirementGateway();$m=new RequirementMaterializer($g);$a=approval($p,$v,resolution());
        $first=$m->materialize($p,$v,$a,$owner,1100);$second=$m->materialize($p,$v,$a,$owner,1100);return compact('first','second')+['counts'=>$g->counts];
    }
    if($mode==='rejected'){
        $p=proposal('none');$v=RequirementIntakeDecisionUi::project($p);$cases=[];$totals=[];
        $attempts=[approval($p,$v,resolution(),'revise'),approval($p,$v,resolution()),approval($p,$v,resolution())];$attempts[1]['decision_ref']='requirement-decision-view:'.str_repeat('0',40);
        foreach($attempts as $i=>$a){$g=new FakeRequirementGateway();$m=new RequirementMaterializer($g);$who=$i===2?new OwnerContext('pl0n3r',true,1):$owner;$cases[]=blocked(fn()=>$m->materialize($p,$v,$a,$who,1100));$totals[]=mutations($g);}return ['blocked'=>$cases,'mutations'=>$totals];
    }
    if($mode==='existing'){
        $p=proposal('matched');$v=RequirementIntakeDecisionUi::project($p);$g=new FakeRequirementGateway();$m=new RequirementMaterializer($g);$a=approval($p,$v);$first=$m->materialize($p,$v,$a,$owner,1100);$second=$m->materialize($p,$v,$a,$owner,1100);return compact('first','second')+['counts'=>$g->counts];
    }
    if($mode==='project-choice'){
        $p=proposal('none');$v=RequirementIntakeDecisionUi::project($p);$g=new FakeRequirementGateway();$m=new RequirementMaterializer($g);$missing=blocked(fn()=>$m->materialize($p,$v,approval($p,$v),$owner,1100));
        $pa=proposal('ambiguous');$va=RequirementIntakeDecisionUi::project($pa);$amb=new FakeRequirementGateway();$ambiguous=blocked(fn()=>(new RequirementMaterializer($amb))->materialize($pa,$va,approval($pa,$va,resolution()),$owner,1100));
        $receipt=$m->materialize($p,$v,approval($p,$v,resolution()),$owner,1100);return ['missing_blocked'=>$missing,'ambiguous_blocked'=>$ambiguous,'ambiguous_mutations'=>mutations($amb),'receipt'=>$receipt,'counts'=>$g->counts];
    }
    if($mode==='stable') return runner('idempotent');
    if($mode==='receipt'){
        $p=proposal('none');$v=RequirementIntakeDecisionUi::project($p);$g=new FakeRequirementGateway();$m=new RequirementMaterializer($g);$receipt=$m->materialize($p,$v,approval($p,$v,resolution()),$owner,1100);$bad=resolution();$bad['title']='password=do-not-store';$secretBlocked=blocked(fn()=>$m->materialize($p,$v,approval($p,$v,$bad),$owner,1100));return ['receipt'=>$receipt,'secret_blocked'=>$secretBlocked];
    }
    throw new InvalidArgumentException('scenario');
}

echo json_encode(runner($argv[1]??''),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";

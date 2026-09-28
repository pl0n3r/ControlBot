<?php
declare(strict_types=1);
require __DIR__.'/../src/VentureIdentity.php';
require __DIR__.'/../src/DecisionRights.php';
require __DIR__.'/../src/AegisRemediation.php';
require __DIR__.'/../src/AegisRuntime.php';
use ControlBot\Security\AegisRuntime;

function base(array $finding=[], array $proposal=[], array $verification=[]): array {
    $policy='controlbot:policy/aegis-safe-remediation';
    $f=array_replace([
        'finding_id'=>'finding-worker-down','scope'=>'venture:condor','category'=>'infrastructure',
        'severity'=>'high','freshness'=>'fresh','policy_ref'=>$policy,
        'evidence_refs'=>['controlbot:aegis/finding-worker-down'],'observed_at'=>'2026-09-28T19:00:00Z',
    ],$finding);
    $p=array_replace([
        'action'=>'restart-worker','capability'=>'restart-worker','scope'=>'venture:condor','reversible'=>true,
        'blast_radius'=>'low','risk_categories'=>[],'preauthorized'=>true,
        'required_authority_level'=>'L0_AI_AUTONOMOUS','policy_ref'=>$policy,'budget_amount'=>null,
    ],$proposal);
    return [
        'version'=>1,'group_id'=>'pl0n3r','venture_id'=>'condor','project_id'=>'condor','repository_ref'=>'pl0n3r/Condor',
        'finding'=>$f,'remediation_input'=>[
            'version'=>1,'finding'=>['finding_id'=>$f['finding_id'],'scope'=>$f['scope']],
            'proposal'=>$p,
            'verified_context'=>[
                'identity'=>['version'=>1,'identity_id'=>'agent-aegis','kind'=>'agent','display_name'=>'AEGIS Agent','state'=>'active','source_ref'=>'controlbot:identity/agent-aegis','observed_at'=>1000],
                'scope'=>'venture:condor','active_policy_refs'=>[$policy],
            ],
            'grant'=>[
                'version'=>1,'grant_id'=>'grant-aegis','identity_id'=>'agent-aegis','role'=>'operator',
                'capability'=>'restart-worker','scope'=>'venture:condor','authority_level'=>'L0_AI_AUTONOMOUS',
                'policy_ref'=>$policy,'budget_limit'=>0,'granted_at'=>900,'expires_at'=>3000,
            ],
            'verification'=>array_replace(['status'=>'not_attempted','evidence_refs'=>[]],$verification),
        ],
    ];
}
$name=$argv[1]??'';
$now=1200;
if($name==='workitem'||$name==='living') $out=AegisRuntime::integrate(base(),$now);
elseif($name==='stale') $out=['stale'=>AegisRuntime::integrate(base(['freshness'=>'stale']),$now),'unknown'=>AegisRuntime::integrate(base(['freshness'=>'unknown']),$now)];
elseif($name==='owner') $out=AegisRuntime::integrate(base([],['risk_categories'=>['legal']]),$now);
elseif($name==='dedupe') $out=AegisRuntime::integrateBatch([base(),base()],$now);
elseif($name==='verified') $out=AegisRuntime::integrate(base([],[],['status'=>'verified','evidence_refs'=>['controlbot:aegis/verification-worker']]),$now);
elseif($name==='identity'){
    $first=AegisRuntime::integrate(base(),$now);
    $second=AegisRuntime::integrate(base([],['action'=>'restart-cron']),$now);
    $out=['first'=>$first,'second'=>$second];
}
elseif($name==='timestamp'){
    try{AegisRuntime::integrate(base(['observed_at'=>'tomorrow']),$now);$out=['rejected'=>false];}
    catch(Throwable){$out=['rejected'=>true];}
}
elseif($name==='secret'){try{AegisRuntime::integrate(base(['evidence_refs'=>['controlbot:aegis/token-supersecret']]),$now);$out=['rejected'=>false];}catch(Throwable){$out=['rejected'=>true];}}
else{fwrite(STDERR,"scenario invalid\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;

"""ControlBot #1451: owner-only HTML and private observability evidence."""
import json
import subprocess
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
PHP = r"""
declare(strict_types=1);
foreach(['Approvals.php','OwnerSession.php','ControlBotWebEntrypoint.php'] as $name)
    require_once getcwd().'/src/'.$name;
$now=14000;$hex=str_repeat('a',32);
$auth=new \ControlBot\Security\OwnerSessionService('pl0n3r',
    new \ControlBot\Security\TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));
$session=[];$auth->establishTrustedOAuthSession($session,'pl0n3r','fixture-token');
$auth->reauthenticateTotp($session,\ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP',$now),
    'JBSWY3DPEHPK3PXP',$now);
$events=[];$types=['health'=>'health_probe','ci'=>'ci_run','deploy'=>'deploy','agent'=>'heartbeat'];
foreach($types as $src=>$type)$events[]=['version'=>1,'source'=>$src,'project_id'=>'project-controlbot',
    'environment_id'=>null,'repository_id'=>null,'severity'=>'info','type'=>$type,
    'payload'=>['status'=>'ok'],'occurred_at'=>13980,'received_at'=>13981,
    'correlation_keys'=>['project:project-controlbot']];
$incident=['version'=>1,'incident_ref'=>'incident:'.$hex,'opened_at'=>13900,'detected_at'=>13920,
    'recovered_at'=>null,'events'=>[['event_id'=>'event-initial','timestamp'=>13920,
        'sequence'=>0,'kind'=>'incident.opened','source'=>'health',
        'evidence_ref'=>'evidence:'.str_repeat('b',32)]]];
$data=['version'=>1,'observed_at'=>$now-1,'project_ids'=>['project-controlbot','project-condor'],
    'events'=>$events,'ttl_by_source'=>array_fill_keys(array_keys($types),120),
    'incidents'=>['incident:'.$hex=>$incident]];
$file=tempnam(sys_get_temp_dir(),'cb1451-');chmod($file,0600);
file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR));
$env=['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r','CONTROLBOT_OBSERVABILITY_SNAPSHOT_PATH'=>$file,
    'CONTROLBOT_DOCUMENT_ROOT'=>getcwd().'/public'];
$server=static fn($method,$path)=>['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$path,
    'REMOTE_USER'=>'forged','HTTP_AUTHORIZATION'=>'Bearer forged'];
$call=static fn($method,$path,$sess=null,$settings=null,$at=14000)=>
    \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated(
        $server($method,$path),$settings??$env,$at,$sess??$session,$auth);
try{
    if(($argv[1]??'')==='principal'){
        $page=$call('GET','/observabilidad');
        $detail=$call('GET','/incidentes/incident-'.$hex);
        $api=$call('GET','/api/v1/observabilidad');
        $view=json_decode($api['body'],true,20,JSON_THROW_ON_ERROR);
        $out=['status'=>[$page['status'],$detail['status'],$api['status']],
            'overview'=>$page['body'],'detail'=>$detail['body'],
            'statuses'=>array_column($view['projects'],'status','project_id'),
            'read_only'=>$view['read_only']&&$view['notifications_sent']===0,
            'no_store'=>$page['headers']['Cache-Control']==='no-store',
            'unchanged'=>json_encode($data)===file_get_contents($file)];
    }else{
        $oauth=[];$auth->establishTrustedOAuthSession($oauth,'pl0n3r','fixture-token');
        $responses=['oauth'=>$call('GET','/observabilidad',$oauth),
            'anonymous'=>$call('GET','/observabilidad',[]),
            'public'=>\ControlBot\Web\ControlBotWebEntrypoint::handle(
                $server('GET','/observabilidad'),$env,$now),
            'local'=>\ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner(
                $server('GET','/observabilidad'),$env,$now),
            'post'=>$call('POST','/observabilidad'),
            'invalid'=>$call('GET','/incidentes/no'),
            'absent'=>$call('GET','/incidentes/incident-'.str_repeat('b',32)),
            'unconfigured'=>$call('GET','/observabilidad',null,['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'])];
        chmod($file,0644);$responses['permissions']=$call('GET','/observabilidad');
        chmod($file,0600);$bad=$data;$bad['observed_at']=$now-301;
        file_put_contents($file,json_encode($bad,JSON_THROW_ON_ERROR));
        $responses['stale']=$call('GET','/observabilidad');
        $out=['statuses'=>array_map(static fn($r)=>$r['status'],$responses),
            'unknown'=>str_contains($responses['absent']['body'],'UNKNOWN'),
            'no_store'=>array_reduce($responses,static fn($ok,$r)=>$ok&&
                ($r['headers']['Cache-Control']??null)==='no-store',true)];
    }
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}finally{chmod($file,0600);unlink($file);}
"""
def fixture(case):
    p=subprocess.run(["php","-r",PHP,case],cwd=ROOT,capture_output=True,text=True,timeout=40)
    if p.returncode: raise AssertionError(p.stderr[-1800:])
    return json.loads(p.stdout)
class ObservabilityTests(unittest.TestCase):
    def test_criterio_principal(self):
        r=fixture("principal")
        self.assertEqual([200,200,200],r["status"])
        self.assertEqual({"project-condor":"UNKNOWN","project-controlbot":"HEALTHY"},r["statuses"])
        self.assertIn("Estado por proyecto",r["overview"])
        self.assertIn("UNKNOWN",r["overview"])
        self.assertIn("incident.opened",r["detail"])
        self.assertTrue(r["no_store"] and r["read_only"] and r["unchanged"])
    def test_criterio_seguridad_y_bordes(self):
        r=fixture("security")
        self.assertEqual({"oauth":403,"anonymous":403,"public":403,"local":403,
            "post":405,"invalid":404,"absent":200,"unconfigured":503,
            "permissions":503,"stale":503},r["statuses"])
        self.assertTrue(r["unknown"] and r["no_store"])
if __name__=="__main__": unittest.main()

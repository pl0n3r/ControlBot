#!/usr/bin/env python3
"""#1478: owner/TOTP HTML Proyectos, fuente privada y negativos exactos."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r"""
declare(strict_types=1);
$root = getcwd().'/src/';
foreach (['Approvals.php','OwnerSession.php','ControlBotWebEntrypoint.php','ProjectReadSource.php'] as $module) require_once $root.$module;
$now=14000;
$auth=new \ControlBot\Security\OwnerSessionService('pl0n3r',new \ControlBot\Security\TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));
$session=[];
$auth->establishTrustedOAuthSession($session,'pl0n3r','fixture-private-token');
$auth->reauthenticateTotp($session,\ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP',$now),'JBSWY3DPEHPK3PXP',$now);
$projects=[];
foreach ([['controlbot','ControlBot','ControlBot'],['condor','Condor','Condor'],['brvtal','BRVTAL','brvtal'],['grindflow','GrindFlow','GrindFlow']] as [$slug,$title,$repo]) {
    $id='project-'.$slug;
    $projects[]=['version'=>1,'project_id'=>$id,'slug'=>$slug,'title'=>$title,'phase'=>'building','priority'=>'medium',
        'repositories'=>[['repository_id'=>'repo-'.$slug,'repository'=>'pl0n3r/'.$repo,'source_ref'=>'https://github.com/pl0n3r/'.$repo,'observed_at'=>$now-1]],
        'environments'=>[['environment_id'=>'env-'.$slug,'kind'=>'dev','source_ref'=>'controlbot:environment/'.$id.'/dev','observed_at'=>$now-1]],
        'aggregate_refs'=>array_fill_keys(['roadmap','agents','decisions','health','incidents','costs'],null),'history_refs'=>[]];
}
$snapshot=['version'=>1,'observed_at'=>$now-1,'projects'=>$projects];
$file=tempnam(sys_get_temp_dir(),'cb1478-'); if (!is_string($file)) throw new RuntimeException('Temp file unavailable');
chmod($file,0600); file_put_contents($file,json_encode($snapshot,JSON_THROW_ON_ERROR));
$env=['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r','CONTROLBOT_PROJECTS_SNAPSHOT_PATH'=>$file,'CONTROLBOT_DOCUMENT_ROOT'=>getcwd().'/public'];
$request=static fn(string $method,string $uri):array=>['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$uri,'REMOTE_USER'=>'forged-owner','HTTP_AUTHORIZATION'=>'Bearer forged'];
$calls=[];
$backend=static function(string $op,array $vars,array $data,int $at) use ($projects,&$calls):array {
    $calls[]=[$op,$vars,$data,$at]; if($op!=='projects.read') throw new RuntimeException('Write prohibited');
    return ['version'=>1,'projects'=>$projects];
};
$call=static fn(string $method,string $uri,?array $sess=null,?callable $src=null,?array $settings=null,int $at=14000):array=>
    \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated($request($method,$uri),$settings??$env,$at,$sess??$session,$auth,null,null,null,$src);
try {
    if (($argv[1]??'')==='principal') {
        $list=$call('GET','/proyectos',null,$backend);
        $detail=$call('GET','/proyectos/project-controlbot',null,$backend);
        $configured=$call('GET','/proyectos'); $api=$call('GET','/api/v1/proyectos');
        $actual=\ControlBot\Project\ProjectReadSource::readConfigured($env,$now);
        $out=['statuses'=>[$list['status'],$detail['status'],$configured['status'],$api['status']],
            'html'=>[$list['body'],$detail['body'],$configured['body']],'headers'=>$list['headers'],
            'read_only'=>count($calls)===2 && array_reduce($calls,static fn(bool $ok,array $c):bool=>$ok&&$c===['projects.read',[],[],$now],true),
            'source_ids'=>array_column($actual['projects'],'project_id'),
            'api_projects'=>count(json_decode($api['body'],true,20,JSON_THROW_ON_ERROR)['projects']),
            'unchanged'=>json_encode($snapshot)===file_get_contents($file)];
    } elseif (($argv[1]??'')==='security') {
        $oauthOnly=[]; $auth->establishTrustedOAuthSession($oauthOnly,'pl0n3r','fixture-private-token');
        $stale=$projects; $stale[0]['repositories'][0]['observed_at']=$now-301;
        $fake=$projects; $fake[0]['repositories'][0]['repository']='pl0n3r/Other';
        $responses=[
            'oauth_only'=>$call('GET','/proyectos',$oauthOnly,$backend),
            'anonymous'=>$call('GET','/proyectos',[],$backend),
            'expired'=>$call('GET','/proyectos',$session,$backend,null,$now+301),
            'public'=>\ControlBot\Web\ControlBotWebEntrypoint::handle($request('GET','/proyectos'),$env,$now),
            'local'=>\ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner($request('GET','/proyectos'),$env,$now),
            'post'=>$call('POST','/proyectos',null,$backend),
            'unknown'=>$call('GET','/proyectos/project-does-not-exist',null,$backend),
            'invalid_path'=>$call('GET','/proyectos/%2e%2e',null,$backend),
            'missing_source'=>$call('GET','/proyectos',null,null,['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r']),
            'empty'=>$call('GET','/proyectos',null,static fn()=>['version'=>1,'projects'=>[]]),
            'wrong_repo'=>$call('GET','/proyectos',null,static fn()=>['version'=>1,'projects'=>$fake]),
            'stale_repo'=>$call('GET','/proyectos',null,static fn()=>['version'=>1,'projects'=>$stale])];
        chmod($file,0644); $responses['public_permissions']=$call('GET','/proyectos'); chmod($file,0600);
        $dated=$snapshot; $dated['observed_at']=$now-301;
        file_put_contents($file,json_encode($dated,JSON_THROW_ON_ERROR));
        $responses['stale_snapshot']=$call('GET','/proyectos');
        file_put_contents($file,json_encode($snapshot,JSON_THROW_ON_ERROR));
        $publicEnv=$env; $publicEnv['CONTROLBOT_DOCUMENT_ROOT']=dirname($file);
        $responses['public_file']=$call('GET','/proyectos',null,null,$publicEnv);
        $link=$file.'-link'; symlink($file,$link);
        $linked=$env; $linked['CONTROLBOT_PROJECTS_SNAPSHOT_PATH']=$link;
        $responses['symlink']=$call('GET','/proyectos',null,null,$linked); unlink($link);
        $markup=$projects; $markup[0]['title']='<script>window.bad=1</script>';
        $xss=$call('GET','/proyectos',null,static fn()=>['version'=>1,'projects'=>$markup]);
        $noEnvironment=$projects; $noEnvironment[0]['environments']=[];
        $unknownHtml=$call('GET','/proyectos/project-controlbot',null,static fn()=>['version'=>1,'projects'=>$noEnvironment]);
        $out=['statuses'=>array_map(static fn($r)=>$r['status'],$responses),
            'no_store'=>array_reduce($responses,static fn($ok,$r)=>$ok&&($r['headers']['Cache-Control']??null)==='no-store',true),
            'not_leaked'=>!str_contains(implode('',array_map(static fn($r)=>$r['body'],$responses)),'fixture-private-token'),
            'xss_escaped'=>$xss['status']===200&&!str_contains($xss['body'],'<script>window.bad')&&str_contains($xss['body'],'&lt;script&gt;'),
            'unknown'=>$unknownHtml['status']===200&&str_contains($unknownHtml['body'],'UNKNOWN')&&str_contains($unknownHtml['body'],'Sin entornos.')];
    } else throw new RuntimeException('Scenario missing');
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
} finally { chmod($file,0600); unlink($file); }
"""

def scenario(name: str) -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI unavailable")
    result = subprocess.run([php, "-r", PHP, name], cwd=ROOT, capture_output=True,
                            text=True, check=False, timeout=45)
    if result.returncode:
        raise AssertionError("PHP fixture failed: " + result.stderr[-2200:])
    return json.loads(result.stdout)

class ProjectsHttpTests(unittest.TestCase):
    def test_criterio_principal(self):
        data = scenario("principal")
        self.assertEqual([200] * 4, data["statuses"])
        self.assertEqual("no-store", data["headers"]["Cache-Control"])
        self.assertEqual("text/html; charset=utf-8", data["headers"]["Content-Type"])
        self.assertTrue(data["read_only"] and data["unchanged"])
        self.assertEqual(4, data["api_projects"])
        self.assertEqual({"project-controlbot","project-condor","project-brvtal","project-grindflow"}, set(data["source_ids"]))
        for name in ("ControlBot","Condor","BRVTAL","GrindFlow"):
            self.assertIn(name, data["html"][0])
            self.assertIn(name, data["html"][2])
        for label in ("pl0n3r/ControlBot","Entornos","Salud"):
            self.assertIn(label, data["html"][1])
        self.assertIn("CONTROLBOT_PROJECTS_SNAPSHOT_PATH", (ROOT / "public/index.php").read_text())

    def test_criterio_seguridad_y_bordes(self):
        data = scenario("security")
        self.assertEqual({"oauth_only":403,"anonymous":403,"expired":403,"public":403,"local":403,
            "post":405,"unknown":404,"invalid_path":404,"missing_source":503,"empty":503,
            "wrong_repo":503,"stale_repo":503,"public_permissions":503,
            "stale_snapshot":503,"public_file":503,"symlink":503}, data["statuses"])
        self.assertTrue(data["no_store"] and data["not_leaked"])
        self.assertTrue(data["xss_escaped"] and data["unknown"])

if __name__ == "__main__":
    unittest.main()

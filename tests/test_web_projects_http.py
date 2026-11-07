#!/usr/bin/env python3
"""#1478: GET HTML owner+TOTP de Proyectos y fuente privada con fallos cerrados."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

PHP = r"""
declare(strict_types=1);
$root = getcwd() . '/src/';
require_once $root . 'Approvals.php';
require_once $root . 'OwnerSession.php';
require_once $root . 'ControlBotWebEntrypoint.php';
require_once $root . 'ProjectReadSource.php';

$now = 14000;
$auth = new \ControlBot\Security\OwnerSessionService(
    'pl0n3r',
    new \ControlBot\Security\TokenVault(base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)))
);
$session = [];
$auth->establishTrustedOAuthSession($session, 'pl0n3r', 'fixture-private-token');
$auth->reauthenticateTotp(
    $session, \ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP', $now),
    'JBSWY3DPEHPK3PXP', $now
);

$projects = [];
foreach ([
    ['controlbot', 'ControlBot', 'ControlBot'],
    ['condor', 'Condor', 'Condor'],
    ['brvtal', 'BRVTAL', 'brvtal'],
    ['grindflow', 'GrindFlow', 'GrindFlow'],
] as [$slug, $title, $repo]) {
    $projects[] = [
        'version'=>1, 'project_id'=>'project-'.$slug, 'slug'=>$slug,
        'title'=>$title, 'phase'=>'building', 'priority'=>'medium',
        'repositories'=>[[
            'repository_id'=>'repo-'.$slug, 'repository'=>'pl0n3r/'.$repo,
            'source_ref'=>'https://github.com/pl0n3r/'.$repo, 'observed_at'=>$now-1,
        ]],
        'environments'=>[[
            'environment_id'=>'env-'.$slug, 'kind'=>'dev',
            'source_ref'=>'controlbot:environment/project-'.$slug.'/dev', 'observed_at'=>$now-1,
        ]],
        'aggregate_refs'=>[
            'roadmap'=>null, 'agents'=>null, 'decisions'=>null,
            'health'=>null, 'incidents'=>null, 'costs'=>null,
        ],
        'history_refs'=>[],
    ];
}
$snapshot = ['version'=>1,'observed_at'=>$now-1,'projects'=>$projects];
$file = tempnam(sys_get_temp_dir(), 'cb1478-');
if (!is_string($file)) throw new RuntimeException('No temporary file');
chmod($file, 0600);
file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
$env = [
    'CONTROLBOT_OWNER_LOGIN'=>'pl0n3r',
    'CONTROLBOT_PROJECTS_SNAPSHOT_PATH'=>$file,
    'CONTROLBOT_DOCUMENT_ROOT'=>getcwd().'/public',
];
$server = static fn(string $method,string $uri):array => [
    'REQUEST_METHOD'=>$method,'REQUEST_URI'=>$uri,
    'REMOTE_USER'=>'forged-owner','HTTP_AUTHORIZATION'=>'Bearer forged',
];
$calls = [];
$backend = static function(string $op,array $params,array $payload,int $at)
    use ($projects,&$calls):array {
    $calls[] = [$op,$params,$payload,$at];
    if ($op !== 'projects.read') throw new RuntimeException('Not read-only');
    return ['version'=>1,'projects'=>$projects];
};
$call = static fn(string $method,string $uri,?array $sess=null,?callable $src=null,
    ?array $settings=null,int $at=14000):array =>
    \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated(
        $server($method,$uri),$settings??$env,$at,$sess??$session,$auth,
        null,null,null,$src,
    );
$source = \ControlBot\Project\ProjectReadSource::class;
try {
    if (($argv[1] ?? '') === 'principal') {
        $list = $call('GET','/proyectos',null,$backend);
        $detail = $call('GET','/proyectos/project-controlbot',null,$backend);
        $configured = $call('GET','/proyectos');
        $api = $call('GET','/api/v1/proyectos');
        $actual = $source::readConfigured($env,$now);
        $out = [
            'statuses'=>[$list['status'],$detail['status'],$configured['status'],$api['status']],
            'html'=>[$list['body'],$detail['body'],$configured['body']],
            'headers'=>$list['headers'],
            'read_only'=>array_reduce($calls,static fn(bool $ok,array $c):bool =>
                $ok && $c === ['projects.read',[],[],$now], true),
            'source_ids'=>array_column($actual['projects'],'project_id'),
            'api_projects'=>count(json_decode($api['body'],true,20,JSON_THROW_ON_ERROR)['projects']),
            'project_calls'=>count($calls),
            'unchanged'=>json_encode($snapshot)===file_get_contents($file),
        ];
    } elseif (($argv[1] ?? '') === 'security') {
        $oauthOnly = [];
        $auth->establishTrustedOAuthSession($oauthOnly,'pl0n3r','fixture-private-token');
        $bad = $projects;
        $bad[0]['repositories'][0]['observed_at']=$now-301;
        $staleBackend = static fn()=>['version'=>1,'projects'=>$bad];
        $fakeBackend = static fn()=>['version'=>1,'projects'=>[]];
        $mismatched=$projects;
        $mismatched[0]['repositories'][0]['repository']='pl0n3r/Other';
        $wrongRepo=static fn()=>['version'=>1,'projects'=>$mismatched];
        $responses=[
            'oauth_only'=>$call('GET','/proyectos',$oauthOnly,$backend),
            'anonymous'=>$call('GET','/proyectos',[],$backend),
            'expired'=>$call('GET','/proyectos',$session,$backend,null,$now+301),
            'public'=>\ControlBot\Web\ControlBotWebEntrypoint::handle(
                $server('GET','/proyectos'),$env,$now),
            'local'=>\ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner(
                $server('GET','/proyectos'),$env,$now),
            'post'=>$call('POST','/proyectos',null,$backend),
            'unknown'=>$call('GET','/proyectos/project-does-not-exist',null,$backend),
            'invalid_path'=>$call('GET','/proyectos/%2e%2e',null,$backend),
            'missing_source'=>$call('GET','/proyectos',null,null,['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r']),
            'empty'=>$call('GET','/proyectos',null,$fakeBackend),
            'wrong_repo'=>$call('GET','/proyectos',null,$wrongRepo),
            'stale_repo'=>$call('GET','/proyectos',null,$staleBackend),
        ];
        chmod($file,0644);
        $responses['public_permissions']=$call('GET','/proyectos');
        chmod($file,0600);
        $stale=$snapshot;$stale['observed_at']=$now-301;
        file_put_contents($file,json_encode($stale,JSON_THROW_ON_ERROR));
        $responses['stale_snapshot']=$call('GET','/proyectos');
        file_put_contents($file,json_encode($snapshot,JSON_THROW_ON_ERROR));
        $responses['public_file']=$call('GET','/proyectos',null,null,$env+[]);
        // A document root pointing at the snapshot directory must deny the file.
        $denied=$env;
        $denied['CONTROLBOT_DOCUMENT_ROOT']=dirname($file);
        $responses['public_file']=$call('GET','/proyectos',null,null,$denied);
        $alias=$file.'-link';
        symlink($file,$alias);
        $linked=$env;$linked['CONTROLBOT_PROJECTS_SNAPSHOT_PATH']=$alias;
        $responses['symlink']=$call('GET','/proyectos',null,null,$linked);
        unlink($alias);
        $rows=$projects;
        $rows[0]['title']='<script>window.bad=1</script>';
        $escaped=static fn()=>['version'=>1,'projects'=>$rows];
        $xss=$call('GET','/proyectos',null,$escaped);
        $withoutEnv=$projects;
        $withoutEnv[0]['environments']=[];
        $unknown=static fn()=>['version'=>1,'projects'=>$withoutEnv];
        $unknownHtml=$call('GET','/proyectos/project-controlbot',null,$unknown);
        $out=[
            'statuses'=>array_map(static fn(array $r):int=>$r['status'],$responses),
            'no_store'=>array_reduce($responses,static fn(bool $ok,array $r):bool =>
                $ok && ($r['headers']['Cache-Control']??null)==='no-store',true),
            'not_leaked'=>!str_contains(implode('',array_map(static fn($r)=>$r['body'],$responses)),
                'fixture-private-token'),
            'xss_escaped'=>$xss['status']===200 && !str_contains($xss['body'],'<script>window.bad')
                && str_contains($xss['body'],'&lt;script&gt;'),
            'unknown'=>$unknownHtml['status']===200 && str_contains($unknownHtml['body'],'UNKNOWN')
                && str_contains($unknownHtml['body'],'Sin entornos.'),
        ];
    } else throw new RuntimeException('Missing fixture scenario');
    echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    chmod($file,0600);
    unlink($file);
}
"""


def scenario(name: str) -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI unavailable")
    completed = subprocess.run(
        [php, "-r", PHP, name], cwd=ROOT, capture_output=True,
        text=True, check=False, timeout=45,
    )
    if completed.returncode:
        raise AssertionError("PHP fixture failed: " + completed.stderr[-2200:])
    return json.loads(completed.stdout)


class ProjectsHttpTests(unittest.TestCase):
    def test_criterio_principal(self):
        data = scenario("principal")
        self.assertEqual([200, 200, 200, 200], data["statuses"])
        self.assertEqual("no-store", data["headers"]["Cache-Control"])
        self.assertEqual("text/html; charset=utf-8", data["headers"]["Content-Type"])
        self.assertTrue(data["read_only"])
        self.assertTrue(data["unchanged"])
        self.assertEqual(2, data["project_calls"])
        self.assertEqual(4, data["api_projects"])
        self.assertEqual(
            {"project-controlbot","project-condor","project-brvtal","project-grindflow"},
            set(data["source_ids"]),
        )
        for name in ("ControlBot","Condor","BRVTAL","GrindFlow"):
            self.assertIn(name, data["html"][0])
            self.assertIn(name, data["html"][2])
        self.assertIn("pl0n3r/ControlBot", data["html"][1])
        self.assertIn("Entornos", data["html"][1])
        self.assertIn("Salud", data["html"][1])
        index = (ROOT / "public/index.php").read_text()
        self.assertIn("CONTROLBOT_PROJECTS_SNAPSHOT_PATH", index)

    def test_criterio_seguridad_y_bordes(self):
        data = scenario("security")
        self.assertEqual({
            "oauth_only":403, "anonymous":403, "expired":403,
            "public":403, "local":403, "post":405,
            "unknown":404, "invalid_path":404, "missing_source":503,
            "empty":503, "wrong_repo":503, "stale_repo":503,
            "public_permissions":503, "stale_snapshot":503,
            "public_file":503, "symlink":503,
        },data["statuses"])
        self.assertTrue(data["no_store"])
        self.assertTrue(data["not_leaked"])
        self.assertTrue(data["xss_escaped"])
        self.assertTrue(data["unknown"])


if __name__ == "__main__":
    unittest.main()

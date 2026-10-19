<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Approvals.php';
require_once dirname(__DIR__) . '/src/OwnerSession.php';
require_once dirname(__DIR__) . '/src/ControlBotWebEntrypoint.php';

use ControlBot\Web\ControlBotWebEntrypoint;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;

function github_projection(): string
{
    $path=tempnam(sys_get_temp_dir(),'cb-shell-github-');
    if(!is_string($path)) throw new RuntimeException('temp failed');
    $snapshot=[
        'version'=>1,
        'project_id'=>'alpha-project',
        'observed_at'=>900,
        'repositories'=>[[
            'repository_id'=>'alpha-repo',
            'repository'=>'pl0n3r/Alpha',
            'source_ref'=>'https://github.com/pl0n3r/Alpha',
            'observed_at'=>900,
            'main_sha'=>str_repeat('a',40),
            'checks'=>['items'=>[],'truncated'=>false],
            'pull_requests'=>['items'=>[],'truncated'=>false],
            'issues'=>['items'=>[],'truncated'=>false],
            'latest_release'=>null,
            'latest_workflow'=>null,
        ]],
    ];
    file_put_contents(
        $path,
        json_encode(['version'=>1,'projects'=>[$snapshot]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
    );
    return $path;
}

function route(string $uri,string $projection,string $method='GET',string $user='owner'): array
{
    $now=1000;
    $service=new OwnerSessionService(
        'owner',
        new TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)))
    );
    $session=[];
    $service->establishTrustedOAuthSession($session,'owner','gho_synthetic_fixture_not_live');
    $secret='JBSWY3DPEHPK3PXP';
    $service->reauthenticateTotp($session,Totp::code($secret,$now),$secret,$now);
    if($user!=='owner'){
        // Negative fixture: a falsified owner session cannot reach private views.
        $session['_owner_login']=$user;
    }

    return ControlBotWebEntrypoint::handleAuthenticated(
        ['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$uri,'REMOTE_USER'=>'untrusted-client'],
        ['CONTROLBOT_OWNER_LOGIN'=>'owner'],
        $now,
        $session,
        $service,
        $projection,
        sys_get_temp_dir().'/cb-shell-missing-orchestrator.json',
        sys_get_temp_dir().'/cb-shell-cache.json',
    );
}

$name=$argv[1]??'';
$projection=github_projection();
try{
    if($name==='surfaces'){
        echo json_encode([
            'global'=>route('/github',$projection),
            'project'=>route('/projects/alpha-project/github',$projection),
            'delegated'=>route('/api/orchestrator-live',$projection),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        exit;
    }
    if($name==='guards'){
        echo json_encode([
            'wrong_owner'=>route('/github',$projection,'GET','intruder'),
            'wrong_method'=>route('/github',$projection,'POST'),
            'unknown_project'=>route('/projects/missing-project/github',$projection),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        exit;
    }
}finally{
    @unlink($projection);
    @unlink(sys_get_temp_dir().'/cb-shell-cache.json');
}
fwrite(STDERR,"unknown scenario\n");
exit(2);

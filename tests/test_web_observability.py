#!/usr/bin/env python3
"""#1451: offline contract tests; owner authentication is covered by router tests."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

PHP = r"""
declare(strict_types=1);
$routes = require getcwd().'/src/Routing/Routes/ObservabilityRoutes.php';
$handlers = [];
foreach ($routes as $route) $handlers[$route['path']] = $route['handler'];
$now = 14000;
$hex = str_repeat('a', 32);
$event = static function (string $project, string $source, int $time = 13980,
    string $severity = 'info'): array {
    $types = ['health'=>'health_probe','ci'=>'ci_run','deploy'=>'deploy','agent'=>'heartbeat'];
    return ['version'=>1,'source'=>$source,'project_id'=>$project,'environment_id'=>null,
        'repository_id'=>null,'severity'=>$severity,'type'=>$types[$source],
        'payload'=>['status'=>'ok'],'occurred_at'=>$time,'received_at'=>$time+1,
        'correlation_keys'=>['project:'.$project]];
};
$timeline = ['version'=>1,'incident_ref'=>'incident:'.$hex,'opened_at'=>13900,
    'detected_at'=>13920,'recovered_at'=>null,'events'=>[[
        'event_id'=>'event-initial','timestamp'=>13920,'sequence'=>0,
        'kind'=>'incident.opened','source'=>'health',
        'evidence_ref'=>'evidence:'.str_repeat('b',32),
    ]]];
$mode = 'valid';
$calls = [];
$backend = static function ($op, $vars, $request, $when) use (
    &$calls, &$mode, $event, $timeline
): array {
    $calls[] = $op;
    if ($op === 'observability.snapshot') {
        $events = [];
        if ($mode === 'valid') {
            foreach (['health','ci','deploy','agent'] as $source)
                $events[] = $event('project-controlbot',$source);
        } elseif ($mode === 'stale') {
            // Tres fuentes frescas: UNKNOWN debe depender de health stale.
            $events[] = $event('project-controlbot','health',1);
            foreach (['ci','deploy','agent'] as $source)
                $events[] = $event('project-controlbot',$source);
        } elseif ($mode === 'severities') {
            // Cuatro fuentes por proyecto: cada estado depende de severidad, no de ausencia.
            $levels = [
                'project-warning'=>['health'=>'warning'],
                'project-error'=>['ci'=>'error'],
                'project-critical'=>['deploy'=>'critical'],
                'project-mixed'=>['health'=>'warning','ci'=>'error'],
            ];
            foreach ($levels as $project => $severities)
                foreach (['health','ci','deploy','agent'] as $source)
                    $events[] = $event($project,$source,13980,$severities[$source] ?? 'info');
        }
        $projects = $mode === 'severities'
            ? ['project-warning','project-error','project-critical','project-mixed']
            : ['project-controlbot','project-condor'];
        $data = ['project_ids'=>$projects,
            'events'=>$events, 'ttl_by_source'=>['health'=>120,
                'ci'=>120,'deploy'=>120,'agent'=>120]];
        if ($mode === 'invalid') $data['extra'] = 'forbidden';
        return $data;
    }
    if ($op === 'incidents.read') {
        if ($mode === 'missing') return ['timeline'=>null];
        $record = $timeline;
        if ($mode === 'wrong_ref') $record['incident_ref']='incident:'.str_repeat('c',32);
        return ['timeline'=>$record];
    }
    throw new RuntimeException('Unknown operation.');
};
$call = static function (string $path, array $vars=[]) use (
    $handlers,$backend,$now
): array {
    return $handlers[$path]($backend,$vars,[],$now);
};
if (($argv[1] ?? '') === 'severities') {
    $mode = 'severities';
    $out = $call('/api/v1/observabilidad');
} elseif (($argv[1] ?? '') === 'principal') {
    $overview = $call('/api/v1/observabilidad');
    $incident = $call('/api/v1/incidentes/{incident_id}',
        ['incident_id'=>'incident-'.$hex]);
    $out = ['overview'=>$overview,'incident'=>$incident,'calls'=>$calls,
        'methods'=>array_column($routes,'method'),'paths'=>array_column($routes,'path')];
} else {
    $mode='missing'; $missing=$call('/api/v1/observabilidad');
    $missingIncident=$call('/api/v1/incidentes/{incident_id}',
        ['incident_id'=>'incident-'.$hex]);
    $mode='stale'; $stale=$call('/api/v1/observabilidad');
    $blocked = static function ($callback): bool {
        try { $callback(); return false; }
        catch (InvalidArgumentException|RuntimeException $error) { return true; }
    };
    $mode='invalid'; $badInput=$blocked(static fn()=>$call('/api/v1/observabilidad'));
    $mode='wrong_ref'; $badRef=$blocked(static fn()=>$call(
        '/api/v1/incidentes/{incident_id}',['incident_id'=>'incident-'.$hex]
    ));
    $mode='valid'; $badId=$blocked(static fn()=>$call(
        '/api/v1/incidentes/{incident_id}',['incident_id'=>'malformed']
    ));
    $out=['missing'=>$missing,'stale'=>$stale,'missing_incident'=>$missingIncident,
        'bad_input'=>$badInput,'bad_ref'=>$badRef,'bad_id'=>$badId,
        'read_only'=>count(array_diff($calls,['observability.snapshot','incidents.read']))===0];
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
"""


def fixture(case):
    completed = subprocess.run(
        ["php", "-r", PHP, case], cwd=ROOT, capture_output=True,
        text=True, timeout=30, check=False
    )
    if completed.returncode:
        raise AssertionError(completed.stderr[-1400:])
    return json.loads(completed.stdout)



HTTP_PHP = r"""
declare(strict_types=1);
$root=getcwd().'/src/';
foreach (['Approvals.php','OwnerSession.php','ControlBotWebEntrypoint.php'] as $name) require_once $root.$name;
$now=14000;
$hex=str_repeat('a',32);
$auth=new \ControlBot\Security\OwnerSessionService(
    'pl0n3r',new \ControlBot\Security\TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES)))
);
$session=[];
$auth->establishTrustedOAuthSession($session,'pl0n3r','synthetic-private-token');
$auth->reauthenticateTotp($session,\ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP',$now),'JBSWY3DPEHPK3PXP',$now);
$event=static function(string $source):array {
    $types=['health'=>'health_probe','ci'=>'ci_run','deploy'=>'deploy','agent'=>'heartbeat'];
    return ['version'=>1,'source'=>$source,'project_id'=>'project-controlbot',
        'environment_id'=>null,'repository_id'=>null,'severity'=>'info','type'=>$types[$source],
        'payload'=>['status'=>'ok'],'occurred_at'=>13980,'received_at'=>13981,
        'correlation_keys'=>['project:project-controlbot']];
};
$events=[]; foreach(['health','ci','deploy','agent'] as $name) $events[]=$event($name);
$timeline=['version'=>1,'incident_ref'=>'incident:'.$hex,'opened_at'=>13900,'detected_at'=>13920,
    'recovered_at'=>null,'events'=>[['event_id'=>'event-initial','timestamp'=>13920,
        'sequence'=>0,'kind'=>'incident.opened','source'=>'health',
        'evidence_ref'=>'evidence:'.str_repeat('b',32)]]];
$snapshot=['version'=>1,'observed_at'=>$now-1,
    'project_ids'=>['project-controlbot','project-condor'],'events'=>$events,
    'ttl_by_source'=>['health'=>120,'ci'=>120,'deploy'=>120,'agent'=>120],
    'incidents'=>['incident:'.$hex=>$timeline]];
$file=tempnam(sys_get_temp_dir(),'cb1451-');
if (!is_string($file)) throw new RuntimeException('Temp file unavailable.');
chmod($file,0600);file_put_contents($file,json_encode($snapshot,JSON_THROW_ON_ERROR));
$env=['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r','CONTROLBOT_OBSERVABILITY_SNAPSHOT_PATH'=>$file,
    'CONTROLBOT_DOCUMENT_ROOT'=>getcwd().'/public'];
$request=static fn(string $method,string $path):array=>[
    'REQUEST_METHOD'=>$method,'REQUEST_URI'=>$path,'REMOTE_USER'=>'forged-owner',
    'HTTP_AUTHORIZATION'=>'Bearer fake-header-token'];
$call=static fn(string $method,string $path,?array $sess=null,?array $settings=null,int $at=14000):array=>
    \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated(
        $request($method,$path),$settings??$env,$at,$sess??$session,$auth
    );
try {
    $incidentPath='/incidentes/incident-'.$hex;
    if(($argv[1]??'')==='principal') {
        $overview=$call('GET','/observabilidad');
        $detail=$call('GET',$incidentPath);
        $api=$call('GET','/api/v1/observabilidad');
        $apiDetail=$call('GET','/api/v1/incidentes/incident-'.$hex);
        $result=json_decode($api['body'],true,20,JSON_THROW_ON_ERROR);
        $incident=json_decode($apiDetail['body'],true,20,JSON_THROW_ON_ERROR);
        $out=['statuses'=>[$overview['status'],$detail['status'],$api['status'],$apiDetail['status']],
            'html'=>[$overview['body'],$detail['body']], 'headers'=>$overview['headers'],
            'states'=>array_column($result['projects'],'status','project_id'),
            'incident_status'=>$incident['status'],
            'unchanged'=>json_encode($snapshot)===file_get_contents($file)];
    } elseif(($argv[1]??'')==='security') {
        $oauth=[];$auth->establishTrustedOAuthSession($oauth,'pl0n3r','synthetic-private-token');
        $responses=[
            'oauth_only'=>$call('GET','/observabilidad',$oauth),
            'anonymous'=>$call('GET','/observabilidad',[]),
            'expired'=>$call('GET','/observabilidad',$session,null,$now+301),
            'public'=>\ControlBot\Web\ControlBotWebEntrypoint::handle($request('GET','/observabilidad'),$env,$now),
            'local'=>\ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner($request('GET','/observabilidad'),$env,$now),
            'post'=>$call('POST','/observabilidad'),
            'invalid_id'=>$call('GET','/incidentes/invalid'),
            'unknown_incident'=>$call('GET','/incidentes/incident-'.str_repeat('b',32)),
            'missing_source'=>$call('GET','/observabilidad',null,['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r']),
        ];
        chmod($file,0644);$responses['public_permissions']=$call('GET','/observabilidad');chmod($file,0600);
        $old=$snapshot;$old['observed_at']=$now-301;
        file_put_contents($file,json_encode($old,JSON_THROW_ON_ERROR));
        $responses['stale_source']=$call('GET','/observabilidad');
        file_put_contents($file,json_encode($snapshot,JSON_THROW_ON_ERROR));
        $publicEnv=$env;$publicEnv['CONTROLBOT_DOCUMENT_ROOT']=dirname($file);
        $responses['public_path']=$call('GET','/observabilidad',null,$publicEnv);
        $link=$file.'-link';symlink($file,$link);
        $linked=$env;$linked['CONTROLBOT_OBSERVABILITY_SNAPSHOT_PATH']=$link;
        $responses['symlink']=$call('GET','/observabilidad',null,$linked);unlink($link);
        $unsafe=$snapshot;$unsafe['project_ids']=['<script>alert(1)</script>'];
        file_put_contents($file,json_encode($unsafe,JSON_THROW_ON_ERROR));
        $responses['malformed']=$call('GET','/observabilidad');
        $out=['statuses'=>array_map(static fn($r)=>$r['status'],$responses),
            'no_store'=>array_reduce($responses,static fn($ok,$r)=>$ok&&
                ($r['headers']['Cache-Control']??null)==='no-store',true),
            'not_leaked'=>!str_contains(implode('',array_map(static fn($r)=>$r['body'],$responses)),
                'synthetic-private-token')];
    } else throw new RuntimeException('Unsupported fixture.');
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
} finally {
    chmod($file,0600);unlink($file);
}
"""


def http_fixture(case):
    completed = subprocess.run(
        ["php", "-r", HTTP_PHP, case], cwd=ROOT, capture_output=True,
        text=True, timeout=45, check=False,
    )
    if completed.returncode:
        raise AssertionError(completed.stderr[-1800:])
    return json.loads(completed.stdout)


class ObservabilityTests(unittest.TestCase):
    def test_criterio_principal(self):
        http = http_fixture("principal")
        self.assertEqual([200, 200, 200, 200], http["statuses"])
        self.assertEqual("no-store", http["headers"]["Cache-Control"])
        self.assertTrue(http["unchanged"])
        self.assertEqual({"project-controlbot": "HEALTHY", "project-condor": "UNKNOWN"}, http["states"])
        self.assertEqual("OPEN", http["incident_status"])
        self.assertIn("Estado por proyecto", http["html"][0])
        self.assertIn("UNKNOWN", http["html"][0])
        self.assertIn("incident.opened", http["html"][1])
        self.assertIn("CONTROLBOT_OBSERVABILITY_SNAPSHOT_PATH", (ROOT / "public/index.php").read_text())
        data = fixture("principal")
        self.assertEqual(["GET", "GET"], data["methods"])
        self.assertEqual([
            "/api/v1/observabilidad", "/api/v1/incidentes/{incident_id}"
        ], data["paths"])
        projects = data["overview"]["projects"]
        self.assertEqual(["project-condor", "project-controlbot"],
                         [p["project_id"] for p in projects])
        self.assertEqual("UNKNOWN", projects[0]["status"])
        self.assertEqual("HEALTHY", projects[1]["status"])
        self.assertEqual("unknown", projects[0]["sources"]["ci"]["freshness"])
        self.assertTrue(all(not p["alert"]["sent"] for p in projects))
        self.assertEqual(0, data["overview"]["notifications_sent"])
        self.assertTrue(data["overview"]["read_only"])
        incident = data["incident"]
        self.assertEqual("OPEN", incident["status"])
        self.assertEqual("incident:" + "a"*32, incident["incident_ref"])
        self.assertEqual(["incident.opened"], [e["kind"] for e in incident["timeline"]])
        self.assertEqual(0, incident["notifications_sent"])

    def test_proyectos_frescos_con_severidades_mixtas(self):
        """Warning/error degradan; critical cae, incluso con cuatro fuentes frescas."""
        data = fixture("severities")
        self.assertEqual(0, data["notifications_sent"])
        actual = {p["project_id"]: p for p in data["projects"]}
        self.assertEqual({
            "project-warning": "DEGRADED",
            "project-error": "DEGRADED",
            "project-critical": "DOWN",
            "project-mixed": "DEGRADED",
        }, {name: project["status"] for name, project in actual.items()})
        for project in actual.values():
            self.assertEqual({"health", "ci", "deploy", "agent"},
                             set(project["sources"]))
            self.assertTrue(all(slot["freshness"] == "fresh"
                                for slot in project["sources"].values()))
            self.assertFalse(project["alert"]["sent"])

    def test_criterio_seguridad_y_bordes(self):
        http = http_fixture("security")
        self.assertEqual({
            "oauth_only": 403, "anonymous": 403, "expired": 403,
            "public": 403, "local": 403, "post": 405, "invalid_id": 404,
            "unknown_incident": 200, "missing_source": 503,
            "public_permissions": 503, "stale_source": 503, "public_path": 503,
            "symlink": 503, "malformed": 503,
        }, http["statuses"])
        self.assertTrue(http["no_store"] and http["not_leaked"])
        data = fixture("security")
        self.assertTrue(all(p["status"]=="UNKNOWN" for p in data["missing"]["projects"]))
        self.assertTrue(all(p["status"]=="UNKNOWN" for p in data["stale"]["projects"]))
        controlbot = next(p for p in data["stale"]["projects"]
                          if p["project_id"] == "project-controlbot")
        self.assertEqual("stale", controlbot["sources"]["health"]["freshness"])
        for source in ("ci", "deploy", "agent"):
            self.assertEqual("fresh", controlbot["sources"][source]["freshness"])
        self.assertEqual("UNKNOWN", controlbot["status"])
        self.assertEqual("UNKNOWN", data["missing_incident"]["status"])
        self.assertEqual([], data["missing_incident"]["timeline"])
        self.assertTrue(data["bad_input"])
        self.assertTrue(data["bad_ref"])
        self.assertTrue(data["bad_id"])
        self.assertTrue(data["read_only"])
        self.assertTrue(all(not p["alert"]["sent"] for p in data["missing"]["projects"]))


if __name__ == "__main__":
    unittest.main()

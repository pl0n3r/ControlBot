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
$event = static function (string $project, string $source, int $time = 13980): array {
    $types = ['health'=>'health_probe','ci'=>'ci_run','deploy'=>'deploy','agent'=>'heartbeat'];
    return ['version'=>1,'source'=>$source,'project_id'=>$project,'environment_id'=>null,
        'repository_id'=>null,'severity'=>'info','type'=>$types[$source],
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
        }
        $data = ['project_ids'=>['project-controlbot','project-condor'],
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
if (($argv[1] ?? '') === 'principal') {
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


class ObservabilityTests(unittest.TestCase):
    def test_criterio_principal(self):
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

    def test_criterio_seguridad_y_bordes(self):
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

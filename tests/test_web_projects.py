#!/usr/bin/env python3
"""#1447: rutas owner de proyectos y preview reproducible sin I/O productivo."""
from __future__ import annotations

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
require_once $root . 'Routing/OwnerAuthenticatedRouter.php';
require_once $root . 'ControlBotWebEntrypoint.php';

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
    $id = 'project-' . $slug;
    $projects[] = [
        'version'=>1, 'project_id'=>$id, 'slug'=>$slug,
        'title'=>$title, 'phase'=>'building', 'priority'=>'medium',
        'repositories'=>[[
            'repository_id'=>'repo-' . $slug, 'repository'=>'pl0n3r/' . $repo,
            'source_ref'=>'https://github.com/pl0n3r/' . $repo, 'observed_at'=>$now - 1,
        ]],
        'environments'=>[[
            'environment_id'=>'dev-' . $slug, 'kind'=>'dev',
            'source_ref'=>'controlbot:environment/' . $id . '/dev', 'observed_at'=>$now - 1,
        ]],
        'aggregate_refs'=>[
            'roadmap'=>null, 'agents'=>null, 'decisions'=>null,
            'health'=>null, 'incidents'=>null, 'costs'=>null,
        ],
        'history_refs'=>[],
    ];
}
$baseline = serialize($projects);
$calls = [];
$backend = static function (string $operation, array $params, array $payload, int $at)
    use (&$calls, $projects): array {
    $calls[] = [$operation, $params, $payload, $at];
    if ($operation !== 'projects.read') throw new RuntimeException('No writes allowed');
    return ['version'=>1, 'projects'=>$projects];
};
$router = \ControlBot\Routing\OwnerAuthenticatedRouter::class;
$make = static fn(string $method, string $path): array => [
    'REQUEST_METHOD'=>$method, 'REQUEST_URI'=>$path,
    'REMOTE_USER'=>'forged-owner', 'HTTP_AUTHORIZATION'=>'Bearer untrusted',
];
$dispatch = static fn(string $method, string $path, ?array $sess = null, ?callable $source = null, int $at = 14000)
    => $router::dispatch($auth, $sess ?? $session, $make($method, $path), $at, $source ?? $backend);

if (($argv[1] ?? '') === 'principal') {
    $list = $dispatch('GET','/api/v1/proyectos');
    $repeat = $dispatch('GET','/api/v1/proyectos');
    $detail = $dispatch('GET','/api/v1/proyectos/project-controlbot');
    $preview = $dispatch('GET','/api/v1/proyectos/backfill');
    $second = $dispatch('GET','/api/v1/proyectos/backfill');
    $entry = \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated(
        $make('GET','/api/v1/proyectos'), ['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],
        $now, $session, $auth, null, null, null, $backend
    );
    $out = [
        'statuses'=>[$list['status'],$repeat['status'],$detail['status'],$preview['status'],$second['status'],$entry['status']],
        'headers'=>$list['headers'],
        'list'=>json_decode($list['body'],true,20,JSON_THROW_ON_ERROR),
        'detail'=>json_decode($detail['body'],true,20,JSON_THROW_ON_ERROR),
        'preview'=>json_decode($preview['body'],true,20,JSON_THROW_ON_ERROR),
        'deterministic'=>$list['body']===$repeat['body']
            && $preview['body']===$second['body'] && $entry['body']===$list['body'],
        'calls'=>$calls, 'unchanged'=>serialize($projects)===$baseline,
    ];
} elseif (($argv[1] ?? '') === 'security') {
    $invalid = static fn()=>['version'=>2,'projects'=>[]];
    $duplicate = static fn()=>['version'=>1,'projects'=>[$projects[0],$projects[0]]];
    $collision = $projects[0];
    $collision['project_id'] = 'project-other';
    $foreign = static fn()=>['version'=>1,'projects'=>[$collision]];
    $empty = static fn()=>['version'=>1,'projects'=>[]];
    $badSource = static fn()=>['version'=>1,'projects'=>'untrusted'];
    $responses = [
        'anonymous'=>$router::dispatch($auth,[],$make('GET','/api/v1/proyectos'),$now,$backend),
        'stale'=>$dispatch('GET','/api/v1/proyectos', $session, $backend, $now+301),
        'wrong_method'=>$dispatch('PUT','/api/v1/proyectos'),
        'post_no_csrf'=>$dispatch('POST','/api/v1/proyectos/backfill'),
        'encoded_path'=>$dispatch('GET','/api/v1/proyectos%2F'),
        'missing_backend'=>$router::dispatch($auth,$session,$make('GET','/api/v1/proyectos'),$now),
        'invalid_source'=>$dispatch('GET','/api/v1/proyectos', $session, $invalid),
        'duplicate'=>$dispatch('GET','/api/v1/proyectos', $session, $duplicate),
        'foreign_repository'=>$dispatch('GET','/api/v1/proyectos', $session, $foreign),
        'bad_source_type'=>$dispatch('GET','/api/v1/proyectos', $session, $badSource),
        'unmatched_id'=>$dispatch('GET','/api/v1/proyectos/project-unknown'),
        'empty'=>$dispatch('GET','/api/v1/proyectos', $session, $empty),
    ];
    $entryDenied = \ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated(
        $make('GET','/api/v1/proyectos'), ['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],
        $now, [], $auth, null, null, null, $backend
    );
    $localDenied = \ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner(
        $make('GET','/api/v1/proyectos'), ['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'], $now
    );
    $body = implode('',array_map(static fn(array $r):string=>$r['body'],$responses));
    $out = [
        'statuses'=>array_map(static fn(array $r):int=>$r['status'],$responses),
        'no_store'=>array_reduce($responses,static fn(bool $ok,array $r):bool=>
            $ok && ($r['headers']['Cache-Control']??null)==='no-store',true),
        'entry_denied'=>$entryDenied['status'],'local_denied'=>$localDenied['status'],
        'no_secret'=>!str_contains($body,'fixture-private-token')
            && !str_contains($body,'JBSWY3DPEHPK3PXP'),
        'not_found'=>json_decode($responses['unmatched_id']['body'],true,20,JSON_THROW_ON_ERROR),
        'unknown_environments'=>json_decode($responses['empty']['body'],true,20,JSON_THROW_ON_ERROR),
        'unchanged'=>serialize($projects)===$baseline,
        'all_read_only'=>array_reduce($calls,static fn(bool $ok,array $c):bool=>$ok&&$c[0]==='projects.read',true),
    ];
} else {
    throw new RuntimeException('Scenario missing');
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
"""


def scenario(name: str) -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP CLI unavailable")
    proc = subprocess.run(
        [php, "-r", PHP, name], cwd=ROOT, capture_output=True,
        text=True, check=False, timeout=45,
    )
    if proc.returncode:
        raise AssertionError("Projects fixture failed: " + proc.stderr[-1800:])
    return json.loads(proc.stdout)


class ProjectsTests(unittest.TestCase):
    def test_criterio_principal(self):
        result = scenario("principal")
        self.assertEqual([200] * 6, result["statuses"])
        self.assertEqual("no-store", result["headers"]["Cache-Control"])
        self.assertTrue(result["deterministic"])
        self.assertTrue(result["unchanged"])
        self.assertEqual([["projects.read", [], [], 14000]] * 6, result["calls"])

        listing = result["list"]
        self.assertEqual("read_only", listing["mode"])
        self.assertFalse(listing["persisted"])
        self.assertEqual("/proyectos", listing["path"])
        projects = {p["project_id"]: p for p in listing["projects"]}
        self.assertEqual(
            {"project-controlbot", "project-condor", "project-brvtal", "project-grindflow"},
            set(projects),
        )
        for project in projects.values():
            self.assertEqual(1, len(project["repositories"]))
            self.assertEqual(1, len(project["environments"]))
        for label in ("ControlBot", "Condor", "BRVTAL", "GrindFlow"):
            self.assertIn(label, listing["html"])
        self.assertIn("Proyectos", listing["html"])

        detail = result["detail"]
        self.assertTrue(detail["found"])
        self.assertEqual("project-controlbot", detail["project"]["project_id"])
        self.assertIn("pl0n3r/ControlBot", detail["html"])
        self.assertIn("controlbot:environment/project-controlbot/dev", detail["html"])
        self.assertIn("Salud", detail["html"])
        preview = result["preview"]
        self.assertEqual("preview_read_only", preview["mode"])
        self.assertTrue(preview["preview_only"])
        self.assertFalse(preview["persisted"])
        self.assertEqual(listing["projects"], preview["projects"])

    def test_criterio_seguridad_y_bordes(self):
        result = scenario("security")
        self.assertEqual(
            {
                "anonymous": 403, "stale": 403, "wrong_method": 405,
                "post_no_csrf": 404, "encoded_path": 400,
                "missing_backend": 503, "invalid_source": 503,
                "duplicate": 503, "foreign_repository": 503,
                "bad_source_type": 503, "unmatched_id": 200, "empty": 200,
            },
            result["statuses"],
        )
        self.assertEqual(403, result["entry_denied"])
        self.assertEqual(403, result["local_denied"])
        self.assertTrue(result["no_store"])
        self.assertTrue(result["no_secret"])
        self.assertTrue(result["all_read_only"])
        self.assertTrue(result["unchanged"])
        self.assertFalse(result["not_found"]["found"])
        self.assertEqual("project-unknown", result["not_found"]["project_id"])
        # An empty trusted source can preview project records but MUST NOT
        # fabricate real environments, health or production readiness.
        preview = result["unknown_environments"]["projects"]
        self.assertEqual(4, len(preview))
        for row in preview:
            self.assertEqual([], row["environments"])
            self.assertIsNone(row["aggregate_refs"]["health"])


if __name__ == "__main__":
    unittest.main()

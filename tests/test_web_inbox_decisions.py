#!/usr/bin/env python3
"""Regresión offline de las páginas owner /inbox y /decisiones/{id} (#1446)."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP_FIXTURE = (ROOT / "tests/test_owner_routes_registry.py").read_text(encoding="utf-8")
PHP_FIXTURE = PHP_FIXTURE.split('PHP = r"""', 1)[1].split('"""', 1)[0].split("try { $case=", 1)[0]

CASE = r"""
require_once getcwd().'/src/Routing/Routes/InboxRoutes.php';
$inboxEntry=[
'version'=>1,'entry_ref'=>'controlbot:inbox/entry-one', 'class'=>'decision',
'scope'=>['kind'=>'venture','ref'=>'controlbot:venture/alpha'],
'title'=>'Decisión de ejemplo','summary'=>'Elegir una opción de forma consciente',
'impact'=>'Impacto controlado','actor_ref'=>null,'required_authority_level'=>'L4_OWNER',
'decision_ref'=>'controlbot:decision/decision-alpha','options_ref'=>null,
'deadline_at'=>null,'source_ref'=>null,'evidence_refs'=>[],
'observed_at'=>null,'freshness'=>'unknown'
];
$entriesFile=tempnam(sys_get_temp_dir(),'inbox-audit-'); $commits=0;
$siteBackend=static function(string $op,array $params,array $payload,int $time)
use($backend,$meta,$inboxEntry,$entriesFile,&$commits):array {
if($op==='owner_inbox.read')return ['meta'=>$meta,'entries'=>[$inboxEntry]];
if($op==='decision_history.read')return ['entries'=>[
['at'=>13990,'result'=>'success'],['at'=>13900,'result'=>'blocked']]];
if($op==='owner_decision.decide'){
$commits++;
file_put_contents($entriesFile,json_encode(['decision_id'=>$params['decision_id'],
'outcome'=>$payload['outcome']],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
}
return $backend($op,$params,$payload,$time);
};
$call=static fn(string $method,string $url,array $sess,array $form=[],?callable $source=null,int $at=14000)
=>\ControlBot\Routing\Routes\InboxRoutes::page($auth,$sess,$make($method,$url),$at,$source??$siteBackend,$form);
$token=$auth->csrfToken($session);
$post=['_csrf'=>$token,'idempotency_key'=>str_repeat('a',32),'outcome'=>'approve'];
if(($argv[1]??'')==='principal'){
$inbox=$call('GET','/inbox',$session);
$filter=$call('GET','/inbox?estado=critical',$session);
$detail=$call('GET','/decisiones/decision-alpha',$session);
$decide=$call('POST','/decisiones/decision-alpha',$session,$post);
$alias=$router::dispatch($auth,$session,$make('GET','/api/v1/web-inbox'),$now,$siteBackend);
$out=['statuses'=>array_map(static fn($r)=>$r['status'],[$inbox,$filter,$detail,$decide,$alias]),
'list'=>str_contains($inbox['body'],'Decisión de ejemplo'),
'filter_empty'=>str_contains($filter['body'],'No hay decisiones pendientes'),
'detail'=>str_contains($detail['body'],'Staged rollout'),
'history'=>str_contains($detail['body'],'Historial'),
'csrf'=>str_contains($detail['body'],'name="_csrf"'),
'mobile'=>str_contains($detail['body'],'viewport'),
'no_store'=>($detail['headers']['Cache-Control']??'')==='no-store',
'commits'=>$commits,'audit'=>file_get_contents($entriesFile),
'no_remote_user'=>str_contains($inbox['body'],'Decisión de ejemplo')];
} else {
$empty=$call('GET','/inbox',[]);
$noCsrf=$call('POST','/decisiones/decision-alpha',$session,
['outcome'=>'approve','idempotency_key'=>str_repeat('a',32)]);
$badCsrf=$call('POST','/decisiones/decision-alpha',$session,
['_csrf'=>'wrong','outcome'=>'approve','idempotency_key'=>str_repeat('a',32)]);
$expired=$call('GET','/inbox',$session,[],null,14301);
$filter=$call('GET','/inbox?estado=%3Cscript%3E',$session);
$wrongMethod=$call('POST','/inbox',$session,$post);
$invalidOutcome=$call('POST','/decisiones/decision-alpha',$session,
['_csrf'=>$token,'outcome'=>'force','idempotency_key'=>str_repeat('a',32)]);
$noBackend=\ControlBot\Routing\Routes\InboxRoutes::page(
$auth,$session,$make('GET','/inbox'),$now,null);
$unknown=\ControlBot\Routing\Routes\InboxRoutes::page(
$auth,$session,$make('GET','/other'),$now,$siteBackend);
$out=['statuses'=>array_map(static fn($r)=>$r['status'],
[$empty,$noCsrf,$badCsrf,$expired,$filter,$wrongMethod,$invalidOutcome,$noBackend]),
'unknown'=>$unknown,'commits'=>$commits,
'no_secrets'=>!str_contains($empty['body'],'fixture-server-token')
&&!str_contains($noBackend['body'],'vault'),
'content_type'=>$noBackend['headers']['Content-Type']??''];
}
@unlink($entriesFile);
echo json_encode($out,JSON_THROW_ON_ERROR);
"""

class InboxDecisionsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if shutil.which("php") is None:
            raise unittest.SkipTest("php CLI unavailable")

    def run_case(self, case: str):
        proc = subprocess.run(
            ["php", "-d", "display_errors=stderr", "-r", PHP_FIXTURE + CASE, case],
            cwd=ROOT, text=True, capture_output=True, timeout=25, check=False,
        )
        self.assertEqual(proc.returncode, 0, proc.stderr[-3000:])
        return json.loads(proc.stdout)

    def test_criterio_principal(self):
        got = self.run_case("principal")
        self.assertEqual(got["statuses"], [200, 200, 200, 200, 200])
        for key in ("list", "filter_empty", "detail", "history", "csrf", "mobile", "no_store"):
            self.assertTrue(got[key], key)
        self.assertEqual(got["commits"], 1)
        self.assertIn('"decision_id":"decision-alpha"', got["audit"])

    def test_criterio_seguridad_y_bordes(self):
        got = self.run_case("security")
        self.assertEqual(got["statuses"], [403, 403, 403, 403, 400, 405, 400, 503])
        self.assertIsNone(got["unknown"])
        self.assertEqual(got["commits"], 0)
        self.assertTrue(got["no_secrets"])
        self.assertTrue(got["content_type"].startswith("text/html"))

if __name__ == "__main__":
    unittest.main()

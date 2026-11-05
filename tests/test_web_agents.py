#!/usr/bin/env python3
"""#1449: owner-authenticated agent presence, no secrets, offline fixtures only."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r'''
declare(strict_types=1);
$root=getcwd().'/src/';
require_once $root.'Approvals.php';
require_once $root.'OwnerSession.php';
require_once $root.'Routing/OwnerAuthenticatedRouter.php';
require_once $root.'ControlBotWebEntrypoint.php';
$now=14000;
$auth=new \ControlBot\Security\OwnerSessionService('pl0n3r',new \ControlBot\Security\TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));
$session=[];
$auth->establishTrustedOAuthSession($session,'pl0n3r','synthetic-oauth-value');
$auth->reauthenticateTotp($session,\ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP',$now),'JBSWY3DPEHPK3PXP',$now);
$agent=['version'=>1,'agent_id'=>'agent-qa','role'=>'qa','capabilities'=>['review','test']];
$account=['version'=>1,'account_id'=>'account-test','provider_id'=>'fixture','account_alias'=>'Cuenta <script>evil()</script>','plan'=>'trial','capacity'=>3,'status'=>'active'];
$record=['version'=>1,'session_id'=>'session-one','agent_id'=>'agent-qa','account_id'=>'account-test','profile_alias'=>'test','tab_id'=>'tab-one','status'=>'working','assignment_id'=>null,'last_heartbeat_at'=>13995,'mode'=>'web','repository'=>null,'issue_number'=>null];
$row=['session'=>$record,'agent'=>$agent,'assignment'=>null,'claims'=>[],'generation'=>1,'attempt'=>1,'safe_point'=>true,'non_preemptible'=>false];
$data=['accounts'=>[$account],'agents'=>[$agent],'rows'=>[$row],'capacity_observations'=>['account-test'=>['version'=>1,'state'=>'healthy','total_capacity'=>3,'occupied_capacity'=>1,'observed_at'=>13995]]];
$backend=static fn(string $operation,array $vars,array $request,int $at):array => $operation==='agents.presence' ? $data : throw new RuntimeException('Unknown operation');
$router=\ControlBot\Routing\OwnerAuthenticatedRouter::class;
$web=\ControlBot\Web\ControlBotWebEntrypoint::class;
$server=static fn(string $uri,string $method='GET'):array => ['REQUEST_URI'=>$uri,'REQUEST_METHOD'=>$method,'REMOTE_USER'=>'attacker','HTTP_AUTHORIZATION'=>'Bearer injected'];
$dispatch=static fn(array $s,?array $sess=null,?callable $provider=null) => $router::dispatch($auth,$sess??$session,$s,$now,$provider??$backend);
$parse=static fn(array $r):array => json_decode($r['body'],true,16,JSON_THROW_ON_ERROR);
$case=$argv[1]??'';
if($case==='principal'){
 $api=$dispatch($server('/api/v1/agents/presence'));
 $page=$web::handleAuthenticated($server('/agentes'),['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],$now,$session,$auth,null,null,null,$backend);
 $local=$web::handleLocalOwner($server('/agentes'),['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],$now);
 $out=['api_status'=>$api['status'],'api'=>$parse($api),'api_headers'=>$api['headers'],
 'page_status'=>$page['status'],'page_headers'=>$page['headers'],
 'page'=>$page['body'],'local_status'=>$local['status'],'local'=>$local['body']];
}elseif($case==='security'){
 $empty=static fn():array=>['accounts'=>[],'agents'=>[],'rows'=>[],'capacity_observations'=>[]];
 $extra=$data; $extra['token']='supersecretvalue';
 $secret=$data; $secret['accounts'][0]['account_alias']='token=supersecretvalue';
 $broken=$data; $broken['rows'][0]['session']['agent_id']='foreign-agent';
 $unknown=$data; $unknown['rows'][0]['session']['last_heartbeat_at']=null;
 $bad=static fn(array $source):callable=>static fn()=> $source;
 $without=$router::dispatch($auth,$session,$server('/api/v1/agents/presence'),$now);
 $cases=[
  'anonymous'=>$dispatch($server('/api/v1/agents/presence'),[]),
  'anonymous_page'=>$web::handleAuthenticated($server('/agentes'),['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],$now,[],$auth),
  'wrong_owner'=>$web::handleAuthenticated($server('/agentes'),['CONTROLBOT_OWNER_LOGIN'=>'other'],$now,$session,$auth),
  'no_source'=>$without,
  'secret_extra'=>$dispatch($server('/api/v1/agents/presence'),$session,$bad($extra)),
  'secret_alias'=>$dispatch($server('/api/v1/agents/presence'),$session,$bad($secret)),
  'invalid_relation'=>$dispatch($server('/api/v1/agents/presence'),$session,$bad($broken)),
  'post'=>$dispatch($server('/api/v1/agents/presence','POST')),
  'spoofed_public'=>$web::handle($server('/agentes'),['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],$now),
 ];
 $blank=$dispatch($server('/api/v1/agents/presence'),$session,$empty);
 $missing=$dispatch($server('/api/v1/agents/presence'),$session,$bad($unknown));
 $out=['denials'=>array_map(static fn(array $r):int=>$r['status'],$cases),
 'bodies'=>array_map(static fn(array $r):string=>$r['body'],$cases),
 'empty_status'=>$blank['status'],'empty'=>$parse($blank),
 'unknown_status'=>$missing['status'],'unknown'=>$parse($missing),
 'no_store'=>array_reduce($cases,static fn(bool $ok,array $r):bool=>$ok&&($r['headers']['Cache-Control']??null)==='no-store',true)];
}else{throw new RuntimeException('Unexpected fixture scenario');}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
'''


def scenario(which: str) -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP required for owner route regression")
    result = subprocess.run([php, "-r", PHP, which], cwd=ROOT, text=True,
                            capture_output=True, timeout=45, check=False)
    if result.returncode:
        raise AssertionError("Fixture failed: " + result.stderr[-1400:])
    return json.loads(result.stdout)


class AgentsTests(unittest.TestCase):
    def test_criterio_principal(self):
        result = scenario("principal")
        self.assertEqual(200, result["api_status"])
        self.assertEqual(200, result["page_status"])
        self.assertEqual(200, result["local_status"])
        self.assertEqual("no-store", result["api_headers"]["Cache-Control"])
        self.assertEqual("no-store", result["page_headers"]["Cache-Control"])
        self.assertEqual("READY", result["api"]["state"])
        self.assertEqual("SOLO", result["api"]["presence"])
        self.assertEqual("IDLE_CAPACITY", result["api"]["capacity"])
        self.assertEqual("HEALTHY", result["api"]["agents"][0]["presence"])
        self.assertEqual("HEALTHY", result["api"]["sessions"][0]["presence"])
        self.assertEqual(2, result["api"]["accounts"][0]["free_slots"])
        for text in ("Agentes", "agent-qa", "account-test", "session-one", "HEALTHY"):
            self.assertIn(text, result["page"])
        self.assertIn("UNKNOWN", result["local"])
        self.assertIn("&lt;script&gt;", result["page"])
        self.assertNotIn("<script>evil()", result["page"])

    def test_criterio_seguridad_y_bordes(self):
        result = scenario("security")
        self.assertEqual({"anonymous": 403, "anonymous_page": 403, "wrong_owner": 403,
                          "no_source": 503, "secret_extra": 503, "secret_alias": 503,
                          "invalid_relation": 503, "post": 404, "spoofed_public": 403},
                         result["denials"])
        self.assertTrue(result["no_store"])
        self.assertEqual(200, result["empty_status"])
        self.assertEqual("UNKNOWN", result["empty"]["state"])
        self.assertEqual("UNKNOWN", result["empty"]["presence"])
        self.assertEqual("UNKNOWN", result["empty"]["capacity"])
        self.assertEqual(200, result["unknown_status"])
        self.assertEqual("UNKNOWN", result["unknown"]["sessions"][0]["presence"])
        for body in result["bodies"].values():
            self.assertNotIn("synthetic-oauth-value", body)
            self.assertNotIn("supersecretvalue", body)
            self.assertNotIn("Bearer injected", body)


if __name__ == "__main__":
    unittest.main()

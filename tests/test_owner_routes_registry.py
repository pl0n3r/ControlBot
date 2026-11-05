#!/usr/bin/env python3
"""#1445: exact owner routes, synthetic OAuth/TOTP and no network/live access."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = r"""
declare(strict_types=1); $root = getcwd().'/src/'; foreach ([ 'Approvals','OwnerSession','GitHub','ApprovalEndpoint','VentureIdentity', 'VentureAccessSource','DecisionRights','DecisionRuntime', 'VerifiedAccessContext','ExternalApiContract','ExternalApiAccess', 'ExternalApiSession','ExternalApiSessionSource','VerifiedExternalSessionContext',
'ExternalApiRequestGate','ExternalApiReadProjection', 'ExternalApiOwnerDecisionRead','ExternalApiMobileAudit','ExternalApiDirectedWorkOrigin' ] as $name) require_once $root.$name.'.php'; require_once $root.'Routing/OwnerAuthenticatedRouter.php'; require_once $root.'ControlBotWebEntrypoint.php';  $now=14000;
$vault=new \ControlBot\Security\TokenVault(base64_encode(str_repeat('K',SODIUM_CRYPTO_SECRETBOX_KEYBYTES))); $auth=new \ControlBot\Security\OwnerSessionService('pl0n3r',$vault); $session=[]; $auth->establishTrustedOAuthSession($session,'pl0n3r','fixture-server-token'); $auth->reauthenticateTotp( $session,\ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP',$now), 'JBSWY3DPEHPK3PXP',$now
); $meta=[ 'request_id'=>str_repeat('a',32),'correlation_id'=>str_repeat('b',32), 'generated_at'=>$now,'freshness'=>['state'=>'unknown','observed_at'=>null,'source_ref'=>null] ]; $vault2=new \ControlBot\Security\TokenVault(base64_encode(str_repeat('D',SODIUM_CRYPTO_SECRETBOX_KEYBYTES))); $other=new \ControlBot\Security\OwnerSessionService('pl0n3r',$vault2);
$otherSession=[]; $other->establishTrustedOAuthSession($otherSession,'pl0n3r','fixture-internal'); $identity=[ 'version'=>1,'identity_id'=>'identity-owner','kind'=>'human','display_name'=>'Owner', 'state'=>'active','source_ref'=>'controlbot:identity/owner','observed_at'=>13900 ]; $policy='controlbot:policy/owner-route-v1';
$grant=[ 'version'=>1,'grant_id'=>'grant-owner','identity_id'=>'identity-owner', 'role'=>'owner','capability'=>'owner.decision.read','scope'=>'venture:alpha', 'authority_level'=>'L4_OWNER','policy_ref'=>$policy,'budget_limit'=>null, 'granted_at'=>13800,'expires_at'=>15000 ]; $accessSource=new class(['identity'=>$identity,'scope'=>'venture:alpha','active_policy_refs'=>[$policy],'grant'=>$grant])
implements \ControlBot\Business\VentureAccessSource { public function __construct(private array $value){} public function resolve(string $identityId,string $scope,string $capability,int $now):array {return $this->value;} }; $temp=tempnam(sys_get_temp_dir(),'cb-owner-routes-'); $audit=new \ControlBot\Approvals\AppendOnlyAuditLog($temp); $runtime=\ControlBot\Decisions\DecisionRuntime::fromServer($other,$audit,['pl0n3r/ControlBot'],$accessSource);
$access=\ControlBot\Business\VerifiedAccessContext::fromDecisionRuntime( $runtime,$otherSession,'pl0n3r/ControlBot', ['identity_id'=>'identity-owner','scope'=>'venture:alpha','capability'=>'owner.decision.read'],$now ); $device=[ 'version'=>1,'device_ref'=>'device:11111111111111111111111111111111', 'identity_id'=>'identity-owner','registered_at'=>13500,'state'=>'active',
'revoked_at'=>null,'revocation_reason'=>null ]; $deviceSession=[ 'version'=>1,'session_ref'=>'session:22222222222222222222222222222222', 'device_ref'=>$device['device_ref'],'identity_id'=>'identity-owner', 'scope'=>'venture:alpha','issued_at'=>13900,'expires_at'=>14500, 'state'=>'active','revoked_at'=>null,'revocation_reason'=>null
]; $deviceSource=new class([ 'version'=>1,'device'=>$device,'session'=>$deviceSession, 'step_up'=>null,'observed_at'=>$now,'freshness'=>'fresh', 'source_ref'=>'controlbot:session-source/owner-routes' ]) implements \ControlBot\ExternalApi\ExternalApiSessionSource { public function __construct(private array $value){}
public function resolve(string $identityId,string $scope,string $deviceRef, string $sessionRef,?string $stepUpRef,int $now):array {return $this->value;} }; $authentication=\ControlBot\ExternalApi\VerifiedExternalSessionContext::fromSource( $access,$deviceSource,$device['device_ref'],$deviceSession['session_ref'],null,$now ); $detail=[
'decision_id'=>'decision-alpha','category'=>'product-direction','title'=>'Staged rollout', 'question'=>'Approve the staged rollout for this fixture?', 'options'=>[['key'=>'A','label'=>'Approve'],['key'=>'B','label'=>'Reject']], 'state'=>'pending','deadline_at'=>14500 ]; $calls=0; $backend=static function(string $operation,array $params,array $request,int $time)
use (&$calls,$meta,$access,$authentication,$detail):array { $calls++; if ($operation==='cockpit.read') return ['meta'=>$meta,'ventures'=>[]]; if ($operation==='owner_inbox.read') return ['meta'=>$meta,'entries'=>[]]; if ($operation==='owner_decision.read') return [ 'access'=>$access,'authentication'=>$authentication,'scope'=>'venture:alpha', 'meta'=>$meta,'detail'=>$detail
]; if ($operation==='owner_decision.decide') return [ 'committed'=>true,'response'=>[ 'meta'=>['version'=>1]+$meta, 'data'=>['decision_id'=>$params['decision_id'],'outcome'=>$request['outcome']] ] ];
throw new RuntimeException('Unknown fixture operation.'); }; $base=['REMOTE_USER'=>'evil','HTTP_AUTHORIZATION'=>'Bearer forged','HTTP_X_REQUEST_ID'=>str_repeat('c',32),'HTTP_X_CORRELATION_ID'=>str_repeat('d',32)]; $make=static function(string $method,string $uri,array $headers=[] ) use($base):array { return $base+$headers+['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$uri]; }; $router=\ControlBot\Routing\OwnerAuthenticatedRouter::class;
$json=json_encode([ 'version'=>1,'outcome'=>'approve','idempotency_key'=>str_repeat('a',32) ],JSON_THROW_ON_ERROR); $post=['HTTP_X_CSRF_TOKEN'=>$auth->csrfToken($session),'CONTENT_TYPE'=>'application/json']; $dispatch=static fn(string $method,string $path,array $headers=[],?array $sess=null, ?string $body=null,?callable $serverBackend=null,?string $routesDir=null,int $at=14000) => $router::dispatch(
$auth,$sess??$session,$make($method,$path,$headers),$at, $serverBackend??$backend,$routesDir,$body ); try { $case=$argv[1]??''; if($case==='principal') { $responses=[];
foreach(['cockpit','owner-inbox','owner-decisions/decision-alpha'] as $route) { $responses[$route]=$dispatch('GET','/api/v1/'.$route); } $responses['post']=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',$post,$session,$json); $entrypoint=\ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated( $make('GET','/api/v1/cockpit'),['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'], $now,$session,$auth,null,null,null,$backend
); $local=\ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner( $make('GET','/api/v1/cockpit'),['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],$now ); $out=['statuses'=>array_map(static fn($r)=>$r['status'],$responses), 'no_store'=>array_reduce($responses,static fn($carry,$r)=>$carry && ($r['headers']['Cache-Control']??null)==='no-store',true), 'responses'=>array_map(static fn($r)=>json_decode($r['body'],true,16,JSON_THROW_ON_ERROR),$responses),
'backend_calls'=>$calls, 'entrypoint_status'=>$entrypoint['status'], 'local_owner_status'=>$local['status']]; } elseif($case==='security'){ $missing=$dispatch('GET','/api/v1/cockpit',[],[]); $anonymousPost=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',$post,[],$json); $stale=$dispatch('GET','/api/v1/cockpit',[],$session,null,null,null,$now+301); $csrf=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',[],$session,$json); $badCsrf=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',
['HTTP_X_CSRF_TOKEN'=>'wrong','CONTENT_TYPE'=>'application/json'],$session,$json); $badJson=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',$post,$session,'{"version":'); $badPayload=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',$post,$session,'{"foo":1}'); $badType=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',['HTTP_X_CSRF_TOKEN'=>$post['HTTP_X_CSRF_TOKEN'],'CONTENT_TYPE'=>'text/plain'],$session,$json); $badLength=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',$post+['CONTENT_LENGTH'=>'16385'],$session,$json); $emptyBody=$dispatch('POST','/api/v1/owner-decisions/decision-alpha/decision',$post,$session,''); $noUri=$router::dispatch($auth,$session,$base+['REQUEST_METHOD'=>'GET'],$now); $badPath=$dispatch('GET','/api/v1/%2e%2e/cockpit'); $unknown=$dispatch('GET','/api/v1/undefined'); $wrongMethod=$dispatch('PUT','/api/v1/cockpit'); $missingSource=$router::dispatch($auth,$session,$make('GET','/api/v1/cockpit'),$now);
$tmp=sys_get_temp_dir().'/cb-route-'.bin2hex(random_bytes(6)); mkdir($tmp,0700); file_put_contents($tmp.'/OtherRoutes.php', '<?php return [[\'method\'=>\'GET\',\'path\'=>\'/api/v1/custom\',\'handler\'=>static fn($f,$p,$b,$n):array=>[\'ok\'=>true]]];'); $custom=$dispatch('GET','/api/v1/custom',[],$session,null,null,$tmp); file_put_contents($tmp.'/DuplicateRoutes.php', '<?php return [[\'method\'=>\'GET\',\'path\'=>\'/api/v1/custom/\',\'handler\'=>static fn($f,$p,$b,$n):array=>[\'ok\'=>true]]];');
$duplicate=$dispatch('GET','/api/v1/custom',[],$session,null,null,$tmp); unlink($tmp.'/OtherRoutes.php');unlink($tmp.'/DuplicateRoutes.php');rmdir($tmp); $failure=\ControlBot\Web\ControlBotWebEntrypoint::handleAuthenticated($make('GET','/api/v1/cockpit'),['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],$now,[],$auth); $local=\ControlBot\Web\ControlBotWebEntrypoint::handleLocalOwner($make('GET','/api/v1/cockpit'),['CONTROLBOT_OWNER_LOGIN'=>'pl0n3r'],$now); $malformed=$dispatch('GET','/api/v1/cockpit',[],$session,null,static fn()=>['meta'=>$meta,'ventures'=>[['broken'=>true]]]); $responses=[ 'anonymous'=>$missing,'anonymous_post'=>$anonymousPost,'stale'=>$stale, 'csrf'=>$csrf,'bad_csrf'=>$badCsrf,'bad_json'=>$badJson, 'bad_payload'=>$badPayload,'bad_type'=>$badType,'bad_length'=>$badLength,'empty_body'=>$emptyBody,'no_uri'=>$noUri,'path'=>$badPath,'unknown'=>$unknown,'method'=>$wrongMethod,'no_source'=>$missingSource,'duplicate'=>$duplicate,'failed_entrypoint'=>$failure,'local_owner'=>$local,'malformed_source'=>$malformed ]; $bodies=array_map(static fn(array $r):string=>$r['body'],array_merge(array_values($responses),[$custom])); $out=[ 'denials'=>array_map(static fn($r):int=>$r['status'],$responses), 'errors'=>array_map(static fn($r):array=>json_decode($r['body'],true,16,JSON_THROW_ON_ERROR),$responses), 'additional_file'=>$custom['status'], 'not_leaked'=>!str_contains(implode('',$bodies),'fixture-server-token'), 'backend_calls'=>$calls, 'no_store'=>($missing['headers']['Cache-Control']??null)==='no-store'
&&($duplicate['headers']['Cache-Control']??null)==='no-store', ]; $generated=json_decode($router::forbidden([])['body'],true,16,JSON_THROW_ON_ERROR); $out['generated_ids']=preg_match('/^[a-f0-9]{32}$/D',$generated['request_id'])===1 && preg_match('/^[a-f0-9]{32}$/D',$generated['correlation_id'])===1; } else throw new RuntimeException('Unknown fixture scenario'); echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL; } finally { @unlink($temp); }
"""


def scenario(name: str) -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP runtime unavailable")
    result = subprocess.run(
        [php, "-r", PHP, name],
        cwd=ROOT, capture_output=True, text=True,
        check=False, timeout=45,
    )
    if result.returncode:
        raise AssertionError("Owner routes fixture error: " + result.stderr[-1500:])
    try:
        return json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("Owner routes fixture returned no JSON: " + result.stdout[-800:]) from exc

class RoutesTests(unittest.TestCase):
    def test_criterio_principal(self):
        found = scenario("principal")
        self.assertEqual([200, 200, 200, 200], list(found["statuses"].values()))
        self.assertTrue(found["no_store"])
        self.assertEqual(5, found["backend_calls"])
        self.assertEqual(200, found["entrypoint_status"])
        self.assertEqual(403, found["local_owner_status"])
        self.assertEqual([], found["responses"]["cockpit"]["data"]["ventures"])
        self.assertEqual([], found["responses"]["owner-inbox"]["data"]["entries"])
        self.assertEqual("decision-alpha",
                         found["responses"]["owner-decisions/decision-alpha"]["data"]["decision_id"])
        self.assertEqual("approve", found["responses"]["post"]["data"]["outcome"])

    def test_criterio_seguridad_y_bordes(self):
        found = scenario("security")
        expected = {
            "anonymous": 403, "anonymous_post": 403, "stale": 403,
            "csrf": 403, "bad_csrf": 403, "bad_json": 400,
            "bad_payload": 400, "bad_type": 400, "bad_length": 400, "empty_body": 400, "no_uri": 400, "path": 400, "unknown": 404,
            "method": 405, "no_source": 503, "duplicate": 503,
            "failed_entrypoint": 403, "local_owner": 403, "malformed_source": 503,
        }
        self.assertEqual(expected, found["denials"])
        self.assertEqual(200, found["additional_file"])
        self.assertTrue(found["no_store"])
        self.assertTrue(found["not_leaked"])
        self.assertTrue(found["generated_ids"])
        self.assertEqual(0, found["backend_calls"])
        for name, status in expected.items():
            error = found["errors"][name]
            self.assertEqual({"version", "code", "message", "request_id", "correlation_id"},
                             set(error), name)
            self.assertEqual(1, error["version"])
            self.assertEqual({400: "invalid_request", 403: "forbidden", 404: "not_found",
                              405: "invalid_request", 503: "internal_error"}[status], error["code"])
            self.assertTrue(error["message"])
            self.assertEqual("c" * 32, error["request_id"])
            self.assertEqual("d" * 32, error["correlation_id"])


if __name__ == "__main__":
    unittest.main()

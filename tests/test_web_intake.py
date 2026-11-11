#!/usr/bin/env python3
"""ControlBot #1509: synthetic owner intake API, no live effects."""
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
foreach ([
 'Approvals','OwnerSession','GitHub','ApprovalEndpoint','VentureIdentity',
 'VentureAccessSource','DecisionRights','DecisionRuntime','VerifiedAccessContext',
 'ExternalApiContract','ExternalApiAccess','ExternalApiSession',
 'ExternalApiSessionSource','VerifiedExternalSessionContext','ExternalApiRequestGate',
 'ExternalApiReadProjection','ExternalApiOwnerDecisionRead','ExternalApiMobileAudit',
 'ExternalApiDirectedWorkOrigin'
] as $class) require_once $root . $class . '.php';
require_once $root . 'Routing/OwnerAuthenticatedRouter.php';
$now=14000;
$auth=new \ControlBot\Security\OwnerSessionService('pl0n3r',
 new \ControlBot\Security\TokenVault(
   base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES))));
$session=[];
$auth->establishTrustedOAuthSession($session,'pl0n3r','offline-fixture-token');
$auth->reauthenticateTotp($session,
 \ControlBot\Security\Totp::code('JBSWY3DPEHPK3PXP',$now),
 'JBSWY3DPEHPK3PXP',$now);
$records=[];$ops=[];
$backend=static function(string $op,array $vars,array $payload,int $time)
 use (&$records,&$ops):array {
 $ops[]=$op;
 switch($op){
 case 'intake.candidates':return ['projects'=>[[
   'project_ref'=>'controlbot:project/controlbot','title'=>'ControlBot',
   'aliases'=>['control center']]]];
 case 'intake.record':
   $id=$payload['intake_id'];$records[$id]=[
     'draft'=>$payload['draft'],'candidates'=>$payload['candidates']];
   return ['stored'=>true,'intake_id'=>$id];
 case 'intake.list':return ['entries'=>array_map(
   static fn(string $id):array=>['intake_id'=>$id],array_keys($records))];
 case 'intake.read':
   if(!isset($records[$vars['intake_id']]))
     throw new RuntimeException('Missing trusted record.');
   return $records[$vars['intake_id']];
 default:throw new RuntimeException('External operation forbidden.');
 }
};
$dispatch=static function(string $method,string $path,?string $raw=null,
 array $headers=[],?array $active=null,?callable $source=null)
 use($auth,$session,$backend,$now):array {
 $server=array_merge(['REQUEST_URI'=>$path,'REQUEST_METHOD'=>$method,
 'CONTENT_TYPE'=>'application/json',
 'HTTP_X_CSRF_TOKEN'=>$auth->csrfToken($session)],$headers);
 return \ControlBot\Routing\OwnerAuthenticatedRouter::dispatch(
   $auth,$active??$session,$server,$now,$source??$backend,null,$raw);
};
$decode=static fn(array $response):array=>json_decode(
 $response['body'],true,16,JSON_THROW_ON_ERROR);
$idea="Problem: Orders are tracked manually\nUser: operators\n"
 ."Objective: centralize intake; preview project decisions\n"
 ."ControlBot requirements initiative";
$payload=json_encode(['content'=>$idea],JSON_THROW_ON_ERROR);
$initial=$dispatch('GET','/api/v1/intake');
$created=$dispatch('POST','/api/v1/intake',$payload);
$id=$decode($created)['intake_id']??'invalid';
$index=$dispatch('GET','/api/v1/intake');
$decision=$dispatch('GET',"/api/v1/intake/$id/decision");
$existing=$dispatch('POST',"/api/v1/intake/$id/decision",json_encode([
 'project_mode'=>'existing','project_ref'=>'controlbot:project/controlbot'
],JSON_THROW_ON_ERROR));
$new=$dispatch('POST',"/api/v1/intake/$id/decision",json_encode([
 'project_mode'=>'new','project_ref'=>null],JSON_THROW_ON_ERROR));
if(($argv[1]??'')==='principal'){
 $out=[
 'status'=>array_map(static fn($r):int=>$r['status'],
  [$initial,$created,$index,$decision,$existing,$new]),
 'initial'=>$decode($initial),'created'=>$decode($created),
 'index'=>$decode($index),'decision'=>$decode($decision),
 'existing'=>$decode($existing),'new'=>$decode($new),
 'stored'=>count($records),'operations'=>$ops];
}else{
 $bad=[
 'anonymous'=>$dispatch('POST','/api/v1/intake',$payload,[],[]),
 'csrf'=>$dispatch('POST','/api/v1/intake',$payload,
   ['HTTP_X_CSRF_TOKEN'=>'incorrect']),
 'invalid_json'=>$dispatch('POST','/api/v1/intake','{"'),
 'extra'=>$dispatch('POST','/api/v1/intake',
   '{"content":"idea","create_repo":true}'),
 'wrong_method'=>$dispatch('DELETE','/api/v1/intake'),
 'bad_id'=>$dispatch('GET','/api/v1/intake/invalid-id/decision'),
 'no_backend'=>\ControlBot\Routing\OwnerAuthenticatedRouter::dispatch(
   $auth,$session,['REQUEST_URI'=>'/api/v1/intake',
   'REQUEST_METHOD'=>'GET'],$now),
 'empty'=>$dispatch('POST','/api/v1/intake','{"content":""}'),
 'missing_id'=>$dispatch('GET','/api/v1/intake/idea-'.str_repeat('f',40).'/decision'),
 'wrong_existing'=>$dispatch('POST',"/api/v1/intake/$id/decision",
   '{"project_mode":"existing","project_ref":"controlbot:project/forged"}'),
 'wrong_new'=>$dispatch('POST',"/api/v1/intake/$id/decision",
   '{"project_mode":"new","project_ref":"controlbot:project/controlbot"}'),
 'bad_choice'=>$dispatch('POST',"/api/v1/intake/$id/decision",
   '{"project_mode":"execute","project_ref":null}'),
 'bad_index'=>$dispatch('GET','/api/v1/intake',null,[],null,
   static fn():array=>['entries'=>[],'extra'=>true]),
 'bad_candidates'=>$dispatch('POST','/api/v1/intake',$payload,[],null,
   static fn():array=>['projects'=>[],'extra'=>true]),
 'bad_receipt'=>$dispatch('POST','/api/v1/intake',$payload,[],null,
   static function(string $op,array $vars,array $data,int $now)
       use($backend):array {
     return $op==='intake.record' ? ['stored'=>false,
       'intake_id'=>$data['intake_id']] : $backend($op,$vars,$data,$now);
   })];
 $out=['status'=>array_map(static fn($r):int=>$r['status'],$bad),
   'stored'=>count($records),'operations'=>$ops];
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
"""
def execute(case: str) -> dict:
    """Execute disposable fixture against real PHP owner router."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP unavailable")
    r = subprocess.run([php, "-r", PHP, case], cwd=ROOT,
        capture_output=True, text=True, timeout=45, check=False)
    if r.returncode:
        raise AssertionError("PHP fixture: " + r.stderr[-1200:])
    return json.loads(r.stdout)
def render_html_fixture(method: str = "GET", csrf: str = "synthetic-csrf") -> dict:
    """Call the isolated renderer: no public route or authentication bypass."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("PHP runtime unavailable")
    program = r"""
require_once getcwd() . '/src/Routing/Routes/IntakePage.php';
$csrf = $argv[1] === '__nul__' ? "bad\0token" : $argv[1];
$response = controlbot_intake_html_page($csrf, ['REQUEST_METHOD' => $argv[2]]);
echo json_encode($response, JSON_THROW_ON_ERROR), "\n";
"""
    result = subprocess.run(
        [php, "-r", program, csrf, method], cwd=ROOT,
        capture_output=True, text=True, timeout=45, check=False,
    )
    if result.returncode:
        raise AssertionError("Isolated renderer failed: " + result.stderr[-1200:])
    return json.loads(result.stdout)


class IntakeTests(unittest.TestCase):
    def test_api_preview_only(self):
        data=execute("principal")
        self.assertEqual([200]*6,data["status"])
        self.assertEqual(1,data["stored"])
        self.assertEqual([],data["initial"]["entries"])
        self.assertEqual(1,len(data["index"]["entries"]))
        self.assertEqual(data["created"]["intake_id"],data["decision"]["intake_id"])
        self.assertFalse(data["decision"]["execution"])
        self.assertEqual("preview_only",data["new"]["status"])
        self.assertEqual("link_proposal",data["existing"]["project"]["operation"])
        self.assertEqual("propose_create",data["new"]["project"]["operation"])
        for kind in ("new","existing"):
            self.assertFalse(data[kind]["committed"])
            self.assertFalse(data[kind]["execution"])
            self.assertEqual(0,data[kind]["repositories_created"])
            self.assertEqual(0,data[kind]["issues_created"])
        self.assertTrue(set(data["operations"]) <= {
          "intake.candidates","intake.record","intake.list","intake.read"})
    def test_api_denials(self):
        data=execute("security")
        self.assertEqual({"anonymous":403,"csrf":403,"invalid_json":400,
          "extra":400,"wrong_method":405,"bad_id":400,"no_backend":503,
          "empty":400,"missing_id":503,"wrong_existing":400,"wrong_new":400,
          "bad_choice":400,"bad_index":503,"bad_candidates":503,
          "bad_receipt":503}, data["status"])
        self.assertEqual(1,data["stored"])
        self.assertFalse(any("github" in op or "materialize" in op
          for op in data["operations"]))
    def test_html_security(self):
        response = render_html_fixture()
        self.assertEqual(200, response["status"])
        self.assertEqual("text/html; charset=utf-8", response["headers"]["Content-Type"])
        self.assertEqual("no-store", response["headers"]["Cache-Control"])
        self.assertEqual("DENY", response["headers"]["X-Frame-Options"])
        policy = response["headers"]["Content-Security-Policy"]
        self.assertIn("script-src 'nonce-", policy)
        self.assertIn("connect-src 'self'", policy)
        self.assertIn("frame-ancestors 'none'", policy)
        self.assertIn("form-action 'none'", policy)
        self.assertIn('id="idea-form"', response["body"])
        self.assertIn('id="decide"', response["body"])
        self.assertIn('"/api/v1/intake"', response["body"])
        self.assertIn('nonce="', response["body"])
        self.assertEqual(405, render_html_fixture(method="POST")["status"])
        self.assertEqual(503, render_html_fixture(csrf="")["status"])
        self.assertEqual(503, render_html_fixture(csrf="x" * 4097)["status"])
        self.assertEqual(503, render_html_fixture(csrf="__nul__")["status"])
        injection = '<script>alert("owned")</script>'
        escaped = render_html_fixture(csrf=injection)
        self.assertEqual(200, escaped["status"])
        self.assertNotIn(injection, escaped["body"])
        self.assertIn("\\u003Cscript\\u003E", escaped["body"])
        for method, token in (("POST", "csrf"), ("GET", "")):
            denied = render_html_fixture(method=method, csrf=token)
            self.assertNotIn("<html", denied["body"])
            self.assertEqual("no-store", denied["headers"]["Cache-Control"])

    def test_html_preview_guards(self):
        body = render_html_fixture()["body"]
        # The UI must reject side-effectful API replies and stale async results.
        for guard in (
            "credentials:\"same-origin\"", 'cache:"no-store"',
            "data.execution !== false", "new TextEncoder().encode(content).length > 8000",
            "++selectionEpoch", "++previewEpoch", "epoch !== selectionEpoch",
            "epoch !== previewEpoch", "selectedId !== id",
            "matchedRef !== projectRef", "data.intake_id !== id",
            'data.status !== "preview_only"', "data.committed !== false",
            "data.repositories_created !== 0", "data.issues_created !== 0",
            "button.textContent =", "indexState.textContent =",
            "document.getElementById(\"result\").textContent =",
        ):
            self.assertIn(guard, body)
        self.assertNotIn("innerHTML", body)
        self.assertNotIn("window.location =", body)
        self.assertNotIn("RequirementMaterializer", body)
        self.assertNotIn("createRepository", body)
        self.assertNotIn("createIssue", body)

if __name__ == "__main__":
    unittest.main()

import json, os, subprocess, tempfile, unittest
from pathlib import Path
from urllib.parse import parse_qs, urlparse
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests/factory_orchestrator_evidence_collector_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
 return json.loads(r.stdout)

def php_eval(code):
 r=subprocess.run(["php","-r",code],cwd=ROOT,text=True,capture_output=True,timeout=30)
 if r.returncode: raise AssertionError(r.stderr.strip() or r.stdout.strip())
 return json.loads(r.stdout)

class OrchestratorEvidenceCollectorTests(unittest.TestCase):
 def test_collector_is_cli_only_disabled_by_default_and_fails_closed_without_valid_token_file(self):
  script=ROOT/"scripts/orchestrator-evidence-collector.php";r=subprocess.run(["php",str(script)],cwd=ROOT,text=True,capture_output=True)
  self.assertEqual(0,r.returncode);self.assertEqual("disabled",json.loads(r.stdout)["state"])
  env=os.environ|{"CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED":"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE":"/missing","CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH":"/tmp/cb-evidence.json"}
  r=subprocess.run(["php",str(script)],cwd=ROOT,text=True,capture_output=True,env=env);self.assertNotEqual(0,r.returncode)

 def test_evidence_is_canonical_for_snapshot_source_with_injected_github_responses(self):
  d=scenario("canonical")
  self.assertTrue(d["snapshot"]["read_only"]);self.assertEqual("UNKNOWN",d["snapshot"]["central"]["activity_state"]);self.assertNotIn("work_inventory",d["evidence"]);self.assertTrue(d["snapshot"]["fronts"])
  self.assertEqual([],d["evidence"]["releases"])
  self.assertTrue(any(row["data"].get("status")=="merged" for row in d["evidence"]["work"]))
  self.assertFalse(any(row["data"].get("status")=="blocked" for row in d["evidence"]["work"]))
  self.assertTrue(any(row["id"].endswith("-11") for row in d["evidence"]["blockers"]))
  self.assertFalse(any(row["id"]=="blocker:factory-767" for row in d["evidence"]["blockers"]))

 def test_only_get_requests_are_made_within_request_and_byte_budgets(self):
  d=scenario("canonical")
  self.assertEqual(len(d["calls"]),d["result"]["requests"]);self.assertLessEqual(d["result"]["requests"],40)
  self.assertLessEqual(d["result"]["download_bytes"],8_000_000);self.assertLessEqual(d["result"]["evidence_bytes"],2_000_000)
  self.assertTrue(all(x["method"]=="GET" for x in d["calls"]))
  data=php_eval(r'''
require "src/FactoryOrchestratorEvidenceCollector.php";
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
$dir=sys_get_temp_dir()."/cb691-budget-".bin2hex(random_bytes(4));mkdir($dir);$token=$dir."/token";$evidence=$dir."/evidence.json";file_put_contents($token,"read-only");chmod($token,0600);
$calls=0;
$transport=static function(string $method,string $url,array $headers)use(&$calls):array{
 $calls++;
 if($method!=="GET"||($headers["User-Agent"]??"")!=="controlbot-orchestrator-evidence-collector/1"||($headers["X-GitHub-Api-Version"]??"")!=="2022-11-28"||!str_starts_with($headers["Authorization"]??"","Bearer "))throw new RuntimeException("headers invalid.");
 if($calls===1)return ["status"=>500,"headers"=>["x-ratelimit-remaining"=>"100"],"bytes"=>3,"json"=>[]];
 $path=parse_url($url,PHP_URL_PATH)?:"";
 $json=str_ends_with($path,"/issues/767")?["number"=>767,"user"=>["login"=>"pl0n3r"],"body"=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->']:[];
 return ["status"=>200,"headers"=>["x-ratelimit-remaining"=>"100"],"bytes"=>10,"json"=>$json];
};
$result=FactoryOrchestratorEvidenceCollector::run(["CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED"=>"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE"=>$token,"CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"=>$evidence],$transport,200);
$old=$dir."/old.json";file_put_contents($old,'{"old":true}');
$tooLarge=static fn(string $method,string $url,array $headers):array=>["status"=>200,"headers"=>["x-ratelimit-remaining"=>"100"],"bytes"=>8_000_001,"json"=>[]];
$failed=false;try{FactoryOrchestratorEvidenceCollector::run(["CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED"=>"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE"=>$token,"CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"=>$old],$tooLarge,200);}catch(Throwable){$failed=true;}
echo json_encode(["result"=>$result,"calls"=>$calls,"byte_failed"=>$failed,"previous"=>file_get_contents($old)],JSON_THROW_ON_ERROR),PHP_EOL;
''')
  self.assertEqual(data["calls"],data["result"]["requests"]);self.assertGreater(data["calls"],15)
  self.assertTrue(data["byte_failed"]);self.assertEqual('{"old":true}',data["previous"])

 def test_realistic_large_github_pages_fit_within_the_download_budget(self):
  data=php_eval(r'''
require "src/FactoryOrchestratorEvidenceCollector.php";
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
$dir=sys_get_temp_dir()."/cb720-realistic-".bin2hex(random_bytes(4));mkdir($dir);$token=$dir."/token";$evidence=$dir."/evidence.json";file_put_contents($token,"read-only");chmod($token,0600);
$transport=static function(string $method,string $url,array $headers):array{
 $path=parse_url($url,PHP_URL_PATH)?:"";$json=[];$bytes=10;
 if(str_ends_with($path,"/issues/767")){$json=["number"=>767,"user"=>["login"=>"pl0n3r"],"body"=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->'];$bytes=5000;}
 elseif(str_ends_with($path,"/issues")){$bytes=str_contains($path,"/Factory/")?1_800_000:450_000;}
 elseif(str_ends_with($path,"/pulls")){$json=[["number"=>9,"merged_at"=>"2026-10-04T20:00:00Z"]];$bytes=180_000;}
 return ["status"=>200,"headers"=>["x-ratelimit-remaining"=>"100"],"bytes"=>$bytes,"json"=>$json];
};
$result=FactoryOrchestratorEvidenceCollector::run(["CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED"=>"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE"=>$token,"CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"=>$evidence],$transport,200);
echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;
''')
  self.assertEqual("written",data["state"]);self.assertGreater(data["download_bytes"],5_000_000);self.assertLessEqual(data["download_bytes"],8_000_000)

 def test_each_failure_cause_prints_an_allowlisted_code_without_secrets(self):
  data=php_eval(r'''
require "src/FactoryOrchestratorEvidenceCollector.php";
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
$messages=[
 "request budget exceeded.","download byte budget exceeded.","credential file invalid.","token path invalid.",
 "evidence write failed.","github transport unavailable.","transport invalid.","github retry deferred.","rate limit low.",
 "github response invalid.","github json invalid.","github page invalid.","github pull page invalid.","github issue invalid.",
 "github number invalid.","labels invalid.","evidence too large.","active work signal budget exceeded.","signal budget exceeded.",
 "method denied.","github url denied.","collector clock invalid.","github http status 403 path /repos/pl0n3r/Factory/issues"
];
$out=[];foreach($messages as $message)$out[]=FactoryOrchestratorEvidenceCollector::diagnosticFor(new RuntimeException($message));
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
''')
  codes=[row["code"] for row in data]
  self.assertNotIn("internal_error",codes)
  self.assertIn("download_budget_exceeded",codes);self.assertIn("request_budget_exceeded",codes);self.assertIn("token_file_invalid",codes)
  self.assertEqual({"code":"http_status_403","path":"/repos/pl0n3r/Factory/issues","status":403},data[-1])
  env=os.environ|{"CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED":"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE":"/definitely/private/sentinel-token-path","CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH":"/tmp/cb-evidence.json"}
  r=subprocess.run(["php",str(ROOT/"scripts/orchestrator-evidence-collector.php")],cwd=ROOT,text=True,capture_output=True,env=env)
  self.assertEqual(70,r.returncode);self.assertIn("token_file_invalid",r.stderr);self.assertNotIn("sentinel-token-path",r.stderr)

 def test_closed_pull_requests_are_fetched_with_minimal_fields_or_small_pages(self):
  d=scenario("full-pull-page");pull_urls=[url for url in d["calls"] if urlparse(url).path.endswith("/pulls")]
  self.assertEqual(7,len(pull_urls))
  for url in pull_urls:
   query=parse_qs(urlparse(url).query);self.assertEqual(["10"],query.get("per_page"));self.assertEqual(["1"],query.get("page"))
  self.assertFalse(any("page=2" in url for url in pull_urls))

 def test_transport_does_not_call_deprecated_curl_close(self):
  source=(ROOT/"scripts/orchestrator-evidence-collector.php").read_text(encoding="utf-8")
  self.assertNotIn("curl_close(",source);self.assertIn("curl_exec(",source);self.assertIn("normalizeLiveResponse(",source)

 def test_transport_requires_real_bytes_and_retries_non_json_5xx(self):
  negative=scenario("transport-negative-branches")
  self.assertTrue(all(negative["failed"]));self.assertEqual(502,negative["retry"]["status"]);self.assertNotIn("json",negative["retry"])
  data=php_eval(r'''
require "src/FactoryOrchestratorEvidenceCollector.php";
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
$dir=sys_get_temp_dir()."/cb695-transport-".bin2hex(random_bytes(4));mkdir($dir);$token=$dir."/token";$evidence=$dir."/evidence.json";file_put_contents($token,"read-only");chmod($token,0600);
$env=["CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED"=>"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE"=>$token,"CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"=>$evidence];$calls=0;
$transport=static function(string $method,string $url,array $headers)use(&$calls):array{$calls++;if($calls===1)return ["status"=>502,"headers"=>["x-ratelimit-remaining"=>"100"],"bytes"=>18];$path=parse_url($url,PHP_URL_PATH)?:"";$json=str_ends_with($path,"/issues/767")?["number"=>767,"user"=>["login"=>"pl0n3r"],"body"=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->']:[];return ["status"=>200,"headers"=>["x-ratelimit-remaining"=>"100"],"bytes"=>strlen(json_encode($json,JSON_THROW_ON_ERROR)),"json"=>$json];};
$result=FactoryOrchestratorEvidenceCollector::run($env,$transport,200);$old=$dir."/old.json";file_put_contents($old,'{"old":true}');$missing=static fn()=>["status"=>200,"headers"=>["x-ratelimit-remaining"=>"100"],"json"=>[]];$failed=false;try{FactoryOrchestratorEvidenceCollector::run($env|["CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"=>$old],$missing,200);}catch(Throwable){$failed=true;}echo json_encode(["result"=>$result,"calls"=>$calls,"missing_failed"=>$failed,"previous"=>file_get_contents($old)],JSON_THROW_ON_ERROR),PHP_EOL;
''')
  self.assertEqual(data["calls"],data["result"]["requests"]);self.assertGreater(data["calls"],15);self.assertTrue(data["missing_failed"]);self.assertEqual('{"old":true}',data["previous"])
  contract=php_eval(r'''
require "src/FactoryOrchestratorEvidenceCollector.php";
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
$invalid=[];$cases=[["POST","https://api.github.com/repos/x"],["GET","http://api.github.com/repos/x"],["GET","https://example.com/repos/x"],["GET","https://u:p@api.github.com/repos/x"],["GET","https://api.github.com:444/repos/x"]];
FactoryOrchestratorEvidenceCollector::validateLiveRequest("GET","https://api.github.com/repos/x");
foreach($cases as $case){$failed=false;try{FactoryOrchestratorEvidenceCollector::validateLiveRequest($case[0],$case[1]);}catch(Throwable){$failed=true;}$invalid[]=$failed;}
$retry=FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(502,["x-ratelimit-remaining"=>"100"],"<html>bad gateway</html>");
$ok=FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(200,["x-ratelimit-remaining"=>"100"],"[]");
$badJson=false;try{FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(200,[],"not-json");}catch(Throwable){$badJson=true;}
$badBody=false;try{FactoryOrchestratorEvidenceCollector::normalizeLiveResponse(200,[],false);}catch(Throwable){$badBody=true;}
echo json_encode(["invalid"=>$invalid,"retry"=>$retry,"ok"=>$ok,"bad_json"=>$badJson,"bad_body"=>$badBody],JSON_THROW_ON_ERROR),PHP_EOL;
''')
  self.assertTrue(all(contract["invalid"]));self.assertEqual(502,contract["retry"]["status"]);self.assertNotIn("json",contract["retry"])
  self.assertEqual([],contract["ok"]["json"]);self.assertTrue(contract["bad_json"]);self.assertTrue(contract["bad_body"])

  with tempfile.TemporaryDirectory() as directory:
   temp=Path(directory);token=temp/"token";evidence=temp/"evidence.json";prepend=temp/"curl_mock.php"
   token.write_text("read-only",encoding="utf-8");os.chmod(token,0o600)
   prepend.write_text(r'''<?php
namespace ControlBot\Cli;
$coverage=getenv('CONTROLBOT_PHP_COVERAGE_BOOTSTRAP');
if(is_string($coverage)&&$coverage!==''){require $coverage;}
function curl_init(string $url){$GLOBALS['cb_url']=$url;return new \stdClass();}
function curl_setopt_array($handle,array $options):bool{$GLOBALS['cb_options']=$options;return true;}
function curl_exec($handle){
 $callback=$GLOBALS['cb_options'][\CURLOPT_HEADERFUNCTION]??null;
 if(is_callable($callback)){$callback($handle,"x-ratelimit-remaining: 100\r\n");}
 $path=parse_url($GLOBALS['cb_url']??'',\PHP_URL_PATH)?:'';
 $json=str_ends_with($path,'/issues/767')
  ?['number'=>767,'user'=>['login'=>'pl0n3r'],'body'=>'<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->']
  :[];
 return json_encode($json,\JSON_THROW_ON_ERROR|\JSON_UNESCAPED_SLASHES);
}
function curl_getinfo($handle,int $option){return 200;}
function curl_close($handle):void{}
''',encoding="utf-8")
   env=os.environ|{
    "CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED":"1",
    "CONTROLBOT_GITHUB_READ_TOKEN_FILE":str(token),
    "CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH":str(evidence),
   }
   live=subprocess.run(
    ["php","-d",f"auto_prepend_file={prepend}",str(ROOT/"scripts/orchestrator-evidence-collector.php")],
    cwd=ROOT,text=True,capture_output=True,env=env,timeout=30,
   )
   self.assertEqual(0,live.returncode,live.stderr)
   self.assertEqual("written",json.loads(live.stdout)["state"]);self.assertTrue(evidence.is_file())

 def test_kill_switch_requires_issue_767_single_exact_owner_marker(self):
  data=php_eval(r'''
require "src/FactoryOrchestratorEvidenceCollector.php";
use ControlBot\Business\FactoryOrchestratorEvidenceCollector;
function runCase(array $factory):bool{$dir=sys_get_temp_dir()."/cb695-kill-".bin2hex(random_bytes(4));mkdir($dir);$token=$dir."/token";$evidence=$dir."/evidence.json";file_put_contents($token,"read-only");chmod($token,0600);$transport=static function(string $method,string $url,array $headers)use($factory):array{$path=parse_url($url,PHP_URL_PATH)?:"";$json=str_ends_with($path,"/issues/767")?$factory:[];return ["status"=>200,"headers"=>["x-ratelimit-remaining"=>"100"],"bytes"=>strlen(json_encode($json,JSON_THROW_ON_ERROR)),"json"=>$json];};FactoryOrchestratorEvidenceCollector::run(["CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED"=>"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE"=>$token,"CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"=>$evidence],$transport,200);$out=json_decode(file_get_contents($evidence),true,64,JSON_THROW_ON_ERROR);foreach($out["blockers"] as $row)if(($row["id"]??null)==="blocker:factory-767")return true;return false;}
$marker='<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->';$valid=["number"=>767,"user"=>["login"=>"pl0n3r"],"body"=>$marker];$wrongNumber=$valid;$wrongNumber["number"]=766;$wrongAuthor=$valid;$wrongAuthor["user"]["login"]="other";$duplicate=$valid;$duplicate["body"].="\n".$marker;$paused=$valid;$paused["body"]='<!-- factory-unattended-kill-switch {"version":1,"state":"PAUSED","owner":"pl0n3r"} -->';echo json_encode(["valid"=>runCase($valid),"wrong_number"=>runCase($wrongNumber),"wrong_author"=>runCase($wrongAuthor),"duplicate"=>runCase($duplicate),"paused"=>runCase($paused)],JSON_THROW_ON_ERROR),PHP_EOL;
''')
  self.assertFalse(data["valid"])
  for key in ("wrong_number","wrong_author","duplicate","paused"): self.assertTrue(data[key],key)

 def test_work_evidence_preserves_allowlisted_labels_without_parallel_status_derivation(self):
  d=scenario("canonical")
  available=next(row for row in d["evidence"]["work"] if row["id"].endswith("-10"))
  self.assertEqual("pending",available["state"]);self.assertEqual(["estado: disponible"],available["data"]["labels"]);self.assertNotIn("status",available["data"])
  self.assertEqual("unknown",next(row for row in d["snapshot"]["fronts"] if row["issue_ref"].endswith("#10"))["status"])
  self.assertFalse(any(row["id"].endswith("-11") for row in d["evidence"]["work"]))
  self.assertTrue(any(row["id"].endswith("-11") for row in d["evidence"]["blockers"]))
  opened=next(row for row in d["evidence"]["work"] if row["id"].endswith("-pr-13"));self.assertEqual("in_review",opened["data"]["status"])
  source=(ROOT/"src/FactoryOrchestratorEvidenceCollector.php").read_text(encoding="utf-8");self.assertNotIn("function status(",source)

 def test_recent_merged_pr_lookup_is_bounded_and_runbook_matches_guardrails(self):
  d=scenario("full-pull-page");pull_urls=[url for url in d["calls"] if urlparse(url).path.endswith("/pulls")]
  self.assertEqual(7,len(pull_urls))
  for url in pull_urls:
   query=parse_qs(urlparse(url).query);self.assertEqual(["10"],query.get("per_page"));self.assertEqual(["1"],query.get("page"))
  self.assertFalse(any("page=2" in url for url in pull_urls))
  self.assertEqual([],d["evidence"]["releases"])
  merged=[row for row in d["evidence"]["work"] if row["data"].get("status")=="merged"];self.assertEqual(7,len(merged))
  doc=(ROOT/"docs/runbooks/orchestrator-snapshot-cron.md").read_text(encoding="utf-8")
  for phrase in ("PR fusionado", "una página reciente", "signal.state=pending", "data.status=in_review", "releases", "HTTPS-only", "8 MB", "retry 5xx", "Issue #767"): self.assertIn(phrase,doc)

 def test_token_never_appears_in_output_logs_evidence_or_snapshot(self):
  d=scenario("canonical");blob=json.dumps(d);self.assertNotIn("sentinel-read-value",blob);self.assertTrue(all(x["authorized"] for x in d["calls"]))

 def test_error_or_rate_limit_keeps_previous_evidence_and_never_leaves_partial_file(self):
  d=scenario("failure");self.assertTrue(d["failed"]);self.assertEqual('{"old":true}',d["previous"])

if __name__=="__main__":unittest.main()

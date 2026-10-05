import json, os, subprocess, unittest
from pathlib import Path
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
  self.assertLessEqual(d["result"]["download_bytes"],2_000_000);self.assertLessEqual(d["result"]["evidence_bytes"],2_000_000)
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
$tooLarge=static fn(string $method,string $url,array $headers):array=>["status"=>200,"headers"=>["x-ratelimit-remaining"=>"100"],"bytes"=>2_000_001,"json"=>[]];
$failed=false;try{FactoryOrchestratorEvidenceCollector::run(["CONTROLBOT_ORCHESTRATOR_COLLECTOR_ENABLED"=>"1","CONTROLBOT_GITHUB_READ_TOKEN_FILE"=>$token,"CONTROLBOT_ORCHESTRATOR_EVIDENCE_PATH"=>$old],$tooLarge,200);}catch(Throwable){$failed=true;}
echo json_encode(["result"=>$result,"calls"=>$calls,"byte_failed"=>$failed,"previous"=>file_get_contents($old)],JSON_THROW_ON_ERROR),PHP_EOL;
''')
  self.assertEqual(data["calls"],data["result"]["requests"]);self.assertGreater(data["calls"],15)
  self.assertTrue(data["byte_failed"]);self.assertEqual('{"old":true}',data["previous"])

 def test_token_never_appears_in_output_logs_evidence_or_snapshot(self):
  d=scenario("canonical");blob=json.dumps(d);self.assertNotIn("sentinel-read-value",blob);self.assertTrue(all(x["authorized"] for x in d["calls"]))

 def test_error_or_rate_limit_keeps_previous_evidence_and_never_leaves_partial_file(self):
  d=scenario("failure");self.assertTrue(d["failed"]);self.assertEqual('{"old":true}',d["previous"])

if __name__=="__main__":unittest.main()

"""Contrato ejecutable del puente AutoFactory v1, sin red ni datos reales."""
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = ROOT / "src" / "AutoFactoryBridgeEndpoint.php"

SCENARIO = r'''
require 'src/AutoFactoryBridgeEndpoint.php';
use ControlBot\AutoFactory\AutoFactoryBridgeEndpoint;
$now=1900000000;
$token='synthetic-token-opaque-do-not-log';
$instance='77777777-7777-4777-8777-777777777777';
$identity=['instanceId'=>$instance,'profileAlias'=>'test-profile','expiresAt'=>$now+300,'revoked'=>false];
$events=[];
$acks=[];
$verify=function($candidate)use($token,&$identity){return hash_equals($token,$candidate)?$identity:null;};
$store=function($kind,$instanceId,$data)use(&$events,&$acks,$instance){
    if($instanceId!==$instance) throw new \RuntimeException('unexpected identity');
    if($kind==='heartbeat'){$events[]=['kind'=>$kind,'profileAlias'=>$data['profileAlias']];return true;}
    if($kind==='next'){
        $cursor=$data['cursor'];
        if($cursor===null)return null; // no authorized queue, fail closed
        if($cursor==='invalid-command')return ['cursor'=>'cursor-next','command'=>['version'=>1,'kind'=>'command','id'=>'order-1','action'=>'unexpected','target'=>'all','payload'=>null]];
        $commands=[
          'prepared-pause'=>['action'=>'pause','target'=>'all','payload'=>null],
          'prepared-mode'=>['action'=>'set_mode','target'=>1,'payload'=>['mode'=>'work']],
          'prepared-message'=>['action'=>'send_message','target'=>1,'payload'=>['text'=>'Synthetic command text']],
        ];
        if(!isset($commands[$cursor]))return null;
        return ['cursor'=>'cursor-next','command'=>array_merge(
          ['version'=>1,'kind'=>'command','id'=>'order-1'],$commands[$cursor]
        )];
    }
    if($kind==='ack'){
        if($data['id']!=='order-1')return 'missing';
        $key=$instanceId.':'.$data['id'];
        if(!isset($acks[$key])){$acks[$key]=$data;return 'created';}
        return $acks[$key]===$data?'duplicate':'conflict';
    }
    throw new \LogicException('unknown');
};
$make=function($enabled)use($verify,$store,&$now){return AutoFactoryBridgeEndpoint::offline($verify,$store,fn()=>$now,$enabled);};
$bridge=$make(true);
$hb=['version'=>1,'kind'=>'heartbeat','profileAlias'=>'test-profile','accountAlias'=>'test-account',
    'tabs'=>[['tabId'=>1,'enabled'=>true,'state'=>'waiting']],
    'accountState'=>['state'=>'ready','resetAt'=>null],'lastEvent'=>null];
$ack=['version'=>1,'kind'=>'ack','id'=>'order-1','ok'=>true,'code'=>'ok'];
$do=fn($m,$p,$q=[],$b=[],$c='synthetic-token-opaque-do-not-log')=>$bridge->handle($m,$p,$q,$b,$c);
$next=['profileAlias'=>'test-profile','cursor'=>null,'waitMs'=>25000];
$results=[];
$results['gate']=$make(false)->handle('POST','/v1/bridge/heartbeat',[],$hb,$token);
$results['validHeartbeat']=$do('POST','/v1/bridge/heartbeat',[],$hb);
$results['invalidCredential']=$do('POST','/v1/bridge/heartbeat',[],$hb,'bad-key');
$results['missingCredential']=$do('POST','/v1/bridge/heartbeat',[],$hb,null);
$spoofed=$hb;$spoofed['profileAlias']='someone-else';
$results['crossProfile']=$do('POST','/v1/bridge/heartbeat',[],$spoofed);
$badTabs=$hb;$badTabs['tabs'][]=['tabId'=>1,'enabled'=>true,'state'=>'waiting'];
$results['duplicateTab']=$do('POST','/v1/bridge/heartbeat',[],$badTabs);
$badBudget=$hb;$badBudget['messageBudget']=['windowMs'=>60000,'budget'=>1,'sent'=>1,'remaining'=>5,
  'limitEvents'=>0,'highReasoningSends'=>0,'nextAllowedAt'=>null,'source'=>'default'];
$results['wrongBudget']=$do('POST','/v1/bridge/heartbeat',[],$badBudget);
$goodBudget=$badBudget;$goodBudget['messageBudget']['remaining']=0;
$results['validBudget']=$do('POST','/v1/bridge/heartbeat',[],$goodBudget);
$results['nextWithoutQueue']=$do('GET','/v1/bridge/commands/next',$next);
foreach(['prepared-pause'=>'nextPause','prepared-mode'=>'nextMode','prepared-message'=>'nextMessage','invalid-command'=>'nextInvalid'] as $cursor=>$name){
    $results[$name]=$do('GET','/v1/bridge/commands/next',array_merge($next,['cursor'=>$cursor]));
}
$results['malformedCursor']=$do('GET','/v1/bridge/commands/next',array_merge($next,['cursor'=>'../../secret']));
$results['ackFirst']=$do('POST','/v1/bridge/commands/ack',[],$ack);
$results['ackRepeat']=$do('POST','/v1/bridge/commands/ack',[],$ack);
$changed=$ack;$changed['code']='failed';$changed['ok']=false;
$results['ackConflict']=$do('POST','/v1/bridge/commands/ack',[],$changed);
$unknown=$ack;$unknown['id']='unknown-order';
$results['ackUnknown']=$do('POST','/v1/bridge/commands/ack',[],$unknown);
$badCode=$ack;$badCode['ok']=false;
$results['ackInvalid']=$do('POST','/v1/bridge/commands/ack',[],$badCode);
$results['unknownRoute']=$do('DELETE','/v1/bridge/heartbeat',[],$hb);
$identity['revoked']=true;
$results['revoked']=$do('POST','/v1/bridge/heartbeat',[],$hb);
$identity['revoked']=false;$identity['expiresAt']=$now;
$results['expired']=$do('POST','/v1/bridge/heartbeat',[],$hb);
$results['counts']=['heartbeats'=>count($events),'acks'=>count($acks)];
echo json_encode($results,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
'''


class PuenteAutofactoryTests(unittest.TestCase):
    def test_acceptance(self):
        self.assertTrue(ENDPOINT.is_file())
        syntax = subprocess.run(
            ["php", "-l", str(ENDPOINT)], cwd=ROOT, text=True,
            capture_output=True, timeout=10
        )
        self.assertEqual(syntax.returncode, 0, syntax.stdout + syntax.stderr)
        # Use a real PHP entrypoint so the CI Xdebug auto_prepend_file
        # instruments src/AutoFactoryBridgeEndpoint.php (php -r skips it).
        with tempfile.TemporaryDirectory(prefix="cb-bridge-", dir=ROOT / "tests") as directory:
            script = Path(directory) / "scenario.php"
            script.write_text("<?php\n" + SCENARIO, encoding="utf-8")
            result = subprocess.run(
                ["php", str(script)], cwd=ROOT, text=True,
                capture_output=True, timeout=30
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        data = json.loads(result.stdout)
        expected = {
            "gate": 503, "validHeartbeat": 202, "invalidCredential": 401,
            "missingCredential": 401, "crossProfile": 400, "duplicateTab": 400,
            "wrongBudget": 400, "validBudget": 202, "nextWithoutQueue": 503,
            "nextPause": 200, "nextMode": 200, "nextMessage": 200,
            "nextInvalid": 503, "malformedCursor": 400,
            "ackFirst": 200, "ackRepeat": 200, "ackConflict": 409,
            "ackUnknown": 404, "ackInvalid": 400, "unknownRoute": 404,
            "revoked": 401, "expired": 401,
        }
        for key, status in expected.items():
            with self.subTest(case=key):
                response = data[key]
                self.assertEqual(response["status"], status)
                self.assertEqual(response["headers"]["Cache-Control"], "no-store")
                self.assertNotIn("synthetic-token-opaque-do-not-log", json.dumps(response))
        self.assertEqual(data["ackFirst"]["body"], {"accepted": True, "duplicate": False})
        self.assertEqual(data["ackRepeat"]["body"], {"accepted": True, "duplicate": True})
        self.assertEqual(data["counts"], {"heartbeats": 2, "acks": 1})
        self.assertEqual(data["nextWithoutQueue"]["body"], {"error": "not_ready"})
        # No public wiring: this boundary is explicitly pre-live and gated.
        source = ENDPOINT.read_text(encoding="utf-8")
        self.assertIn("private readonly bool $enabled = false", source)
        self.assertNotIn("curl_exec", source)
        self.assertNotIn("file_get_contents(", source)


if __name__ == "__main__":
    unittest.main()

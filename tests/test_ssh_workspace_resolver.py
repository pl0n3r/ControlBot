import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
 r=subprocess.run(["php",str(ROOT/"tests/ssh_workspace_resolver_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=20)
 return json.loads(r.stdout)
class SshWorkspaceResolverTests(unittest.TestCase):
 def test_resolver_uses_broker_generation_and_only_callback_sees_path(self):
  d=scenario("valid"); self.assertEqual(d["seen"],"/srv/apps/brvtal/current"); self.assertTrue(d["out"]["ok"]); self.assertIsNone(d["out"]["path"]); self.assertNotIn("/srv/apps/brvtal/current",json.dumps(d["out"]))
 def test_context_cannot_supply_generation_or_override_scope(self):
  d=scenario("context"); self.assertEqual(d["calls"],0); self.assertTrue(all(not x["ok"] for x in d["out"].values())); self.assertEqual(d["out"]["generation"]["reason"],"workspace_context_invalid")
 def test_unknown_revoked_or_mismatched_workspace_stops_before_consumer(self):
  d=scenario("denies"); self.assertEqual(d["calls"],0); self.assertFalse(d["unknown"]["ok"]); self.assertEqual(d["revoked"]["reason"],"workspace_reference_revoked"); self.assertEqual(d["scope"]["reason"],"workspace_scope_mismatch")
 def test_scope_change_between_surface_and_execute_fails_closed(self):
  d=scenario("drift"); self.assertFalse(d["ok"]); self.assertEqual(d["reason"],"workspace_generation_changed"); self.assertIsNone(d["path"])
 def test_path_never_escapes_result_or_error(self):
  d=scenario("redaction"); raw=json.dumps(d); self.assertNotIn("/srv/apps/brvtal/current",raw); self.assertEqual(d["ok"]["result"]["message"],"workspace=[REDACTED]"); self.assertIn("[REDACTED]",d["fail"]["error"])
 def test_suite_has_no_network_filesystem_write_or_subprocess(self):
  s=(ROOT/"src/SshConnectionWorkspaceResolver.php").read_text(); t=(ROOT/"tests/ssh_workspace_resolver_scenarios.php").read_text()
  for x in ("curl_","file_put_contents","fopen(","proc_open","shell_exec","exec(","system("): self.assertNotIn(x,s+t)
  self.assertIn("RemoteWorkspaceBroker",s); self.assertIn("agentSurface(",s); self.assertIn("->execute(",s)
if __name__=="__main__": unittest.main()

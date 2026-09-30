import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    run=subprocess.run(["php",str(ROOT/"tests/open_ssh_client_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True,timeout=30)
    return json.loads(run.stdout)

class OpenSshClientTests(unittest.TestCase):
    def test_pinned_private_key_request_executes_closed_readonly_probe(self):
        d=scenario("success")
        self.assertEqual(d["result"]["code"],"ssh_readonly_probe_ok"); self.assertEqual(len(d["calls"]),2)
        self.assertEqual([c[0] for c in d["calls"]],["ssh-keyscan","ssh"]); self.assertEqual(d["calls"][1][-1],"true")
    def test_host_identity_failures_stop_before_authenticated_ssh(self):
        for d in scenario("host_failures").values():
            self.assertEqual(len(d["calls"]),1); self.assertNotEqual(d["result"]["status"],"success")
    def test_noncanonical_or_unsupported_requests_fail_closed(self):
        for d in scenario("invalid").values():
            self.assertEqual(d["status"] if "status" in d else d["result"]["status"],"failed")
            if "calls" in d: self.assertEqual(d["calls"],[])
    def test_ephemeral_key_and_known_hosts_are_mode_0600_and_always_removed(self):
        d=scenario("success"); self.assertEqual(d["modes"],[0o600,0o600]); self.assertEqual(d["paths_exist"],[False,False])
        c=scenario("process_failures")["cleanup"]; self.assertEqual(c["result"]["code"],"ssh_cleanup_failed"); self.assertNotEqual(c["result"]["status"],"success")
    def test_runner_only_receives_allowlisted_structured_argv(self):
        d=scenario("success"); self.assertEqual([c[0] for c in d["calls"]],["ssh-keyscan","ssh"])
        self.assertNotIn("command",json.dumps(d["calls"])); self.assertNotIn("PRIVATE KEY",json.dumps(d["calls"]))
    def test_results_never_expose_secret_output_or_temp_paths(self):
        d=scenario("process_failures")
        for case in d.values():
            visible=json.dumps(case["result"]); self.assertNotIn("PRIVATE KEY",visible); self.assertNotIn("sensitive stderr",visible)
            for path in case["paths"]: self.assertNotIn(path,visible)
            self.assertNotEqual(case["result"]["status"],"success")
    def test_suite_uses_fake_runner_without_network(self):
        src=(ROOT/"tests/open_ssh_client_scenarios.php").read_text()
        self.assertIn("$runner=",src); self.assertNotIn("shell_exec(",src); self.assertNotIn("exec(",src)

if __name__=="__main__": unittest.main()

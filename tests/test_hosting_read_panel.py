import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"hosting_read_panel_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode or r.stderr.strip(): raise AssertionError(r.stderr.strip() or f"scenario {name} failed")
    return json.loads(r.stdout)

class HostingReadPanelTests(unittest.TestCase):
    def test_connected_profile_and_read_capability_produce_sanitized_snapshot(self):
        d=scenario("connected"); self.assertEqual((d["project"],d["environment"],d["health"]),("alpha","prod","healthy"))
        self.assertNotIn("secret_ref",json.dumps(d).lower()); self.assertEqual(d["connection_status"],"connected")

    def test_unavailable_revoked_or_changed_identity_fails_closed(self):
        self.assertTrue(all(scenario("identity").values()))

    def test_missing_hostinger_read_capability_denies_access(self):
        self.assertTrue(scenario("capability")["denied"])

    def test_partial_provider_data_remains_unknown_per_field(self):
        d=scenario("partial"); self.assertEqual((d["disk"],d["cron"]),("unknown","unknown")); self.assertNotEqual(d["health"],"healthy")
        invalid=scenario("invalid_aggregate"); self.assertTrue(all(invalid.values()))

    def test_panel_exposes_no_mutating_or_shell_actions(self):
        s=scenario("source")["panel"].lower()
        for word in ("billing.change","shell.arbitrary","dns.write","database.delete","cron.write"): self.assertNotIn(word,s)

    def test_snapshot_is_secret_free(self):
        self.assertTrue(scenario("secret")["rejected"])
        s=json.dumps(scenario("connected")).lower()
        for word in ("password","private_key","secret_ref","token","authorization","bearer"): self.assertNotIn(word,s)

    def test_project_environment_scopes_never_cross(self):
        d=scenario("scopes"); self.assertTrue(d["cross_denied"])
        self.assertEqual([(r["project"],r["environment"]) for r in d["rows"]],[("alpha","prod"),("beta","stage")])

if __name__=="__main__": unittest.main()

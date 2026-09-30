import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"disaster_recovery_policy_scenarios.php"),name],cwd=ROOT,check=True,text=True,capture_output=True)
    return json.loads(r.stdout)

class DisasterRecoveryPolicyTests(unittest.TestCase):
    def test_canonical_policy_requires_rpo_rto_retention_and_separated_components(self):
        p=scenario("valid")["policy"]
        self.assertEqual((p["rpo_seconds"],p["rto_seconds"]),(900,3600))
        self.assertEqual(p["retention"],{"recent":24,"daily":7,"weekly":8,"monthly":12})
        self.assertEqual(p["strategies"],{"database":"consistent_backup","media":"versioned_backup","code":"repository_mirror","secrets":"vault_reference_only"})
        for c in ("bad-rpo","bad-rto","empty-retention","missing-field"): self.assertTrue(scenario(c)["blocked"])

    def test_missing_stale_or_incomplete_evidence_never_becomes_healthy(self):
        e=scenario("evidence")
        self.assertEqual(e,{"missing":"unknown","stale_healthy":"degraded","incomplete_healthy":"degraded","unknown":"unknown","blocked":"blocked","healthy":"healthy"})

    def test_recovery_capabilities_are_explicit_and_reproducible(self):
        d=scenario("valid"); p=d["policy"]
        self.assertEqual(p["capabilities"],{"offsite":True,"versioned_or_immutable":True,"checksum_required":True,"freshness_required":True,"restore_drill_required":True})
        self.assertEqual(p["destinations"],[{"provider":"google_drive","role":"offsite_encrypted_copy"},{"provider":"object_store","role":"versioned_or_immutable_copy"}])
        self.assertEqual(len(d["fingerprint"]),64)

    def test_drive_is_only_encrypted_offsite_and_icloud_is_not_server_primary(self):
        for c in ("drive-primary","drive-server","icloud-primary","icloud-server"): self.assertTrue(scenario(c)["blocked"])
        self.assertIn({"provider":"google_drive","role":"offsite_encrypted_copy"},scenario("valid")["policy"]["destinations"])

    def test_extra_duplicate_invalid_and_sensitive_inputs_fail_closed(self):
        for c in ("extra-field","duplicate-destination","duplicate-provenance","sensitive-ref","bad-secret-strategy","bad-policy-project"):
            self.assertTrue(scenario(c)["blocked"])

    def test_policy_and_fingerprint_are_order_independent(self):
        d=scenario("permuted"); self.assertEqual(d["base"],d["permuted"])

    def test_policy_has_no_external_io_or_actions(self):
        s=(ROOT/"src"/"DisasterRecoveryPolicy.php").read_text().lower()
        for x in ("curl_","file_put_contents","fopen(","mysqli","pdo(","shell_exec","exec(","proc_open","system(","passthru(","googleapis","aws-sdk","restore(","backup("):
            self.assertNotIn(x,s)

if __name__=="__main__": unittest.main()

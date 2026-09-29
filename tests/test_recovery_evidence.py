import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def scenario(n):
 r=subprocess.run(["php",str(ROOT/"tests"/"recovery_evidence_scenarios.php"),n],cwd=ROOT,check=True,text=True,capture_output=True);return json.loads(r.stdout)
class RecoveryEvidenceTests(unittest.TestCase):
 def test_evidence_is_project_scoped_and_bound_to_recovery_profile(self):
  d=scenario("valid");self.assertEqual(d["project_ref"],"controlbot:project/project-controlbot");self.assertEqual(d["profile_ref"],"controlbot:recovery-profile/project-controlbot-v1")
  for c in ("cross-project","producer-project-mismatch","cross-project-profile-ref"):self.assertTrue(scenario(c)["blocked"])
 def test_control_states_preserve_reported_state_and_freshness_fail_closed(self):
  s=scenario("stale-checksum")["controls"]["checksum"];self.assertEqual((s["reported_state"],s["freshness"],s["state"]),("verified","stale","unknown"))
  u=scenario("unknown-checksum")["controls"]["checksum"];self.assertEqual((u["state"],u["evidence_ref"],u["observed_at"]),("unknown",None,None));self.assertEqual(scenario("stale-source")["sources"][2]["state"],"unknown")
 def test_source_evidence_is_closed_deterministic_and_distinguishes_not_applicable(self):
  r=scenario("valid")["sources"];self.assertEqual([x["source"] for x in r],["database","media","repository","configuration"]);self.assertEqual((r[1]["state"],r[1]["reported_state"],r[1]["freshness"]),("not_applicable","not_applicable","unknown"))
  self.assertTrue(scenario("duplicate-source")["blocked"]);self.assertTrue(scenario("required-not-applicable")["blocked"])
 def test_sensitive_payload_digest_provider_url_or_secret_fails_closed_without_echo(self):
  for c,v in {"top-level-digest":"sha256:0123456789abcdef","payload-field":"opaque-backup-payload","provider-url":"bucket.example.invalid","secret-ref":"token-secret-value","unknown-with-provenance":"controlbot:evidence/checksum"}.items():
   with self.subTest(case=c):r=scenario(c);self.assertTrue(r["blocked"]);self.assertNotIn(v,r["message"] or "")
 def test_projection_does_not_compute_health_rpo_rto_authority_or_workitems(self):
  d=scenario("valid");self.assertEqual(set(d),{"version","project_ref","profile_ref","controls","sources"});blob=json.dumps(d).lower()
  for x in ("recovery_health","healthy","degraded","rpo_breached","rto_breached","authority","work_item"):self.assertNotIn(x,blob)
  src=(ROOT/"src"/"RecoveryEvidence.php").read_text();self.assertNotIn("WorkItem",src);self.assertNotIn("recovery_health",src)
 def test_docs_preserve_factory_runner_backup_receipt_and_aegis_boundaries(self):
  d=(ROOT/"docs"/"disaster-recovery-evidence.md").read_text()
  for x in ("Factory #305","FactoryRunner","BackupReceipt","AegisEvidence","no valida el contenido","no calcula Recovery Health"):self.assertIn(x,d)
if __name__=="__main__":unittest.main()

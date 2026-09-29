import json, subprocess, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def scenario(name):
    r=subprocess.run(["php",str(ROOT/"tests"/"incident_lesson_scenarios.php"),name],cwd=ROOT,text=True,capture_output=True)
    if r.returncode or r.stderr.strip(): raise AssertionError(r.stderr.strip() or f"scenario {name} failed")
    return json.loads(r.stdout)

class IncidentLessonTests(unittest.TestCase):
    def test_valid_postmortem_builds_closed_pending_lesson_candidate(self):
        d=scenario("valid")
        self.assertEqual(d["version"],1); self.assertEqual(d["publication_state"],"pending")
        self.assertTrue(d["root_cause_facts"]); self.assertTrue(d["evidence_refs"])
        self.assertRegex(d["candidate_fingerprint"],r"^[0-9a-f]{64}$")
        self.assertEqual(d["dedupe_marker"],"incident-lesson-v1:"+d["candidate_fingerprint"])

    def test_candidate_fingerprint_and_dedupe_marker_are_deterministic(self):
        d=scenario("deterministic")
        self.assertEqual(d["first"],d["permuted"])

    def test_candidate_is_secret_and_direct_pii_free(self):
        d=scenario("secret")
        self.assertTrue(d["token"]); self.assertTrue(d["email"])

    def test_uncertain_evidence_preserves_owner_action_required(self):
        data=scenario("uncertain")
        for state,d in data.items():
            self.assertTrue(d["owner_action_required"], (state,d))
            self.assertNotIn("Billing hard stop mechanism remains unverified.",d["root_cause_facts"], (state,d))

    def test_independent_bug_is_not_promoted_to_root_cause_or_rule(self):
        d=scenario("independent"); bug="Coordination caller lacked checks write permission."
        self.assertIn(bug,d["independent_bugs"])
        self.assertNotIn(bug,d["root_cause_facts"]); self.assertNotIn(bug,d["preventive_rules"])

    def test_incident_78_candidate_is_reusable_without_cross_repo_publication(self):
        d=scenario("incident78")
        self.assertIn("Private Actions capacity was exhausted for the incident period.",d["root_cause_facts"])
        self.assertIn("Production observer cadence moved from one hour to six hours.",d["preventive_rules"])
        self.assertEqual(d["publication_state"],"pending")
        source=(ROOT/"src"/"IncidentLesson.php").read_text(encoding="utf-8").lower()
        for token in ("new pdo","mysqli","curl_","http://","https://","shell_exec","exec(","file_put_contents","factory/lecciones","github"):
            self.assertNotIn(token,source)

if __name__=="__main__": unittest.main()

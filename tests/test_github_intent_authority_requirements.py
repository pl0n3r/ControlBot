import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "github_intent_authority_requirements_scenarios.php"), name],
        cwd=ROOT, capture_output=True, text=True, check=False,
    )
    if run.returncode:
        raise AssertionError(run.stderr.strip() or run.stdout.strip())
    return json.loads(run.stdout)


class GitHubIntentAuthorityRequirementsTests(unittest.TestCase):
    def test_each_known_intent_maps_to_exact_capability_scope_and_approval_class_without_granting(self):
        rows = scenario("catalog")
        capabilities = {
            "issue.create": "github.issue.write", "issue.update": "github.issue.write",
            "issue.close": "github.issue.write", "issue.reserve": "github.issue.write",
            "issue.release": "github.issue.write", "pr.review": "github.pr.review",
            "pr.merge": "github.pr.merge", "workflow.dispatch": "github.workflow.dispatch",
            "release.approve": "github.release.approve",
            "project.freeze": "github.project.write", "project.unfreeze": "github.project.write",
        }
        self.assertEqual(set(rows), set(capabilities))
        for kind, data in rows.items():
            with self.subTest(kind=kind):
                row = data["requirements"]
                is_project = kind.startswith("project.")
                scope = "project" if is_project else "repository"
                scope_ref = "controlbot:project/controlbot" if is_project else "pl0n3r/ControlBot"
                self.assertEqual(row["type"], kind)
                self.assertEqual(row["capability"], capabilities[kind])
                self.assertEqual(row["scope"], scope)
                self.assertEqual(row["scope_ref"], scope_ref)
                self.assertEqual(row["scope_parameters"], {scope + "_ref": scope_ref})
                self.assertEqual(row["approval_class"], "owner_required")
                self.assertEqual(row["state"], "requirements")
                for flag in ("authorized", "grant_issued", "approval_issued",
                             "approval_verified", "execution"):
                    self.assertIs(row[flag], False)
                self.assertEqual(data["policy"]["known"], False)
                self.assertEqual(data["policy"]["decision"], "forbidden")
                self.assertIs(data["policy"]["auto_grant_allowed"], False)

    def test_unknown_type_extra_authority_or_scope_expansion_fails_closed(self):
        rows = scenario("fail_closed")
        self.assertEqual(len(rows), 12)
        for kind, row in rows.items():
            with self.subTest(kind=kind):
                self.assertEqual(row["state"], "unknown")
                self.assertNotIn("capability", row)
                for flag in ("authorized", "grant_issued", "approval_issued",
                             "approval_verified", "execution"):
                    self.assertIs(row[flag], False)

    def test_observation_is_not_a_grant_or_approval(self):
        rows = scenario("boundary")
        self.assertIs(rows["declaration"]["authorized"], False)
        self.assertIs(rows["declaration"]["execution"], False)
        self.assertEqual(rows["different_scope"]["state"], "unknown")
        source = (ROOT / "src" / "GitHubIntentAuthorityRequirements.php").read_text()
        for forbidden in ("curl_init(", "file_get_contents(", "shell_exec(",
                          "CapabilityGrant::issue(", "CapabilityGrant::authorize(",
                          "github_pat_"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

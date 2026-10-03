import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/github_release_provenance_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True, timeout=30,
    )
    return json.loads(result.stdout)


class GitHubReleaseProvenanceTests(unittest.TestCase):
    def test_release_tag_resolves_to_commit_provenance_without_claiming_deployment(self):
        annotated = scenario("annotated")
        evidence = annotated["evidence"]
        release = evidence["release_identity"]
        self.assertEqual((evidence["version"], evidence["repository"], evidence["observed_at"]), (1, "pl0n3r/ControlBot", 300))
        self.assertEqual(release["state"], "KNOWN")
        self.assertEqual((release["release_id"], release["tag_name"]), (42, "v1.2.3"))
        self.assertEqual((release["tag_kind"], release["tag_depth"]), ("annotated", 1))
        self.assertEqual((release["tag_ref_sha"], release["commit_sha"]), ("b" * 40, "c" * 40))
        self.assertTrue(release["source_ref"].startswith("github:pl0n3r/ControlBot#release:42@v1.2.3:"))
        self.assertEqual(evidence["deployed_version"]["state"], "UNKNOWN")
        self.assertEqual(evidence["deployed_version"]["reason"], "deployment_evidence_not_provided")
        self.assertIsNone(evidence["deployed_version"]["commit_sha"])
        for method, url, _headers, body in annotated["calls"]:
            self.assertEqual(method, "GET")
            self.assertIsNone(body)
            self.assertTrue(url.startswith("https://api.github.com/repos/pl0n3r/ControlBot/"))

        lightweight = scenario("lightweight")["evidence"]
        light = lightweight["release_identity"]
        self.assertEqual((light["state"], light["tag_name"]), ("KNOWN", "v2.0.0"))
        self.assertEqual((light["tag_kind"], light["tag_depth"], light["commit_sha"]), ("lightweight", 0, "d" * 40))
        self.assertEqual(lightweight["deployed_version"]["state"], "UNKNOWN")

    def test_missing_tag_or_deployment_evidence_never_becomes_deployed_version(self):
        cases = {
            "missing-tag": "release_tag_absent",
            "no-release": "stable_release_absent",
            "bounded-drafts": "stable_release_not_observed_within_bound",
        }
        for name, reason in cases.items():
            with self.subTest(case=name):
                evidence = scenario(name)["evidence"]
                self.assertEqual(evidence["release_identity"]["state"], "UNKNOWN")
                self.assertEqual(evidence["release_identity"]["reason"], reason)
                self.assertIsNone(evidence["release_identity"]["commit_sha"])
                self.assertEqual(evidence["deployed_version"]["state"], "UNKNOWN")
                self.assertIsNone(evidence["deployed_version"]["commit_sha"])

        source = (ROOT / "src/GitHubReleaseProvenance.php").read_text()
        upper, lower = source.upper(), source.lower()
        for verb in ("'POST'", "'PATCH'", "'PUT'", "'DELETE'"):
            self.assertNotIn(verb, upper)
        for mutator in ("dispatchworkflow", "movetag(", "closeissue(", "commentissue("):
            self.assertNotIn(mutator, lower)


if __name__ == "__main__":
    unittest.main()

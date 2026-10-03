import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/github_release_provenance_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class GitHubReleaseProvenanceTests(unittest.TestCase):
    def test_release_tag_resolves_to_commit_provenance_without_claiming_deployment(self):
        annotated = scenario("annotated")
        evidence = annotated["evidence"]

        self.assertEqual(evidence["version"], 1)
        self.assertEqual(evidence["repository"], "pl0n3r/ControlBot")
        self.assertEqual(evidence["observed_at"], 300)
        self.assertEqual(evidence["release_identity"]["state"], "KNOWN")
        self.assertEqual(evidence["release_identity"]["release_id"], 42)
        self.assertEqual(evidence["release_identity"]["tag_name"], "v1.2.3")
        self.assertEqual(evidence["release_identity"]["tag_kind"], "annotated")
        self.assertEqual(evidence["release_identity"]["tag_depth"], 1)
        self.assertEqual(evidence["release_identity"]["tag_ref_sha"], "b" * 40)
        self.assertEqual(evidence["release_identity"]["commit_sha"], "c" * 40)
        self.assertTrue(
            evidence["release_identity"]["source_ref"].startswith(
                "github:pl0n3r/ControlBot#release:42@v1.2.3:"
            )
        )
        self.assertEqual(evidence["deployed_version"]["state"], "UNKNOWN")
        self.assertEqual(
            evidence["deployed_version"]["reason"],
            "deployment_evidence_not_provided",
        )
        self.assertIsNone(evidence["deployed_version"]["tag_name"])
        self.assertIsNone(evidence["deployed_version"]["commit_sha"])

        for method, url, _headers, body in annotated["calls"]:
            self.assertEqual(method, "GET")
            self.assertIsNone(body)
            self.assertTrue(url.startswith("https://api.github.com/repos/pl0n3r/ControlBot/"))

        lightweight = scenario("lightweight")["evidence"]
        self.assertEqual(lightweight["release_identity"]["state"], "KNOWN")
        self.assertEqual(lightweight["release_identity"]["tag_name"], "v2.0.0")
        self.assertEqual(lightweight["release_identity"]["tag_kind"], "lightweight")
        self.assertEqual(lightweight["release_identity"]["tag_depth"], 0)
        self.assertEqual(lightweight["release_identity"]["commit_sha"], "d" * 40)
        self.assertEqual(lightweight["deployed_version"]["state"], "UNKNOWN")

    def test_missing_tag_or_deployment_evidence_never_becomes_deployed_version(self):
        missing_tag = scenario("missing-tag")["evidence"]
        self.assertEqual(missing_tag["release_identity"]["state"], "UNKNOWN")
        self.assertEqual(missing_tag["release_identity"]["reason"], "release_tag_absent")
        self.assertIsNone(missing_tag["release_identity"]["commit_sha"])
        self.assertEqual(missing_tag["deployed_version"]["state"], "UNKNOWN")
        self.assertIsNone(missing_tag["deployed_version"]["commit_sha"])

        no_release = scenario("no-release")["evidence"]
        self.assertEqual(no_release["release_identity"]["state"], "UNKNOWN")
        self.assertEqual(no_release["release_identity"]["reason"], "stable_release_absent")
        self.assertEqual(no_release["deployed_version"]["state"], "UNKNOWN")

        bounded = scenario("bounded-drafts")["evidence"]
        self.assertEqual(bounded["release_identity"]["state"], "UNKNOWN")
        self.assertEqual(
            bounded["release_identity"]["reason"],
            "stable_release_not_observed_within_bound",
        )
        self.assertEqual(bounded["deployed_version"]["state"], "UNKNOWN")

        source = (ROOT / "src/GitHubReleaseProvenance.php").read_text()
        upper = source.upper()
        lower = source.lower()
        for verb in ("'POST'", "'PATCH'", "'PUT'", "'DELETE'"):
            self.assertNotIn(verb, upper)
        for mutator in ("dispatchworkflow", "movetag(", "closeissue(", "commentissue("):
            self.assertNotIn(mutator, lower)


if __name__ == "__main__":
    unittest.main()

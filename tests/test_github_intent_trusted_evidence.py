import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "github_intent_trusted_evidence_scenarios.php"), name],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode:
        raise AssertionError(result.stderr.strip() or result.stdout.strip())
    return json.loads(result.stdout)


class GitHubIntentTrustedEvidenceTests(unittest.TestCase):
    def test_context_is_derived_from_canonical_snapshot_with_scope_and_observed_at(self):
        data = scenario("canonical")
        self.assertEqual(data["version"], 1)
        self.assertEqual(data["state"], "current")
        self.assertEqual(data["repository_ref"], "pl0n3r/ControlBot")
        self.assertEqual(data["source_ref"], "https://github.com/pl0n3r/ControlBot")
        self.assertEqual(
            data["evidence_ref"],
            "https://github.com/pl0n3r/ControlBot/commit/" + "a" * 40,
        )
        self.assertEqual(data["observed_at"], 1791157800 - 120)
        self.assertEqual(data["freshness"], "current")
        self.assertEqual(data["age_seconds"], 120)
        self.assertIs(data["execution"], False)
        self.assertNotIn("allow", json.dumps(data).lower())

    def test_caller_timestamp_scope_mismatch_stale_or_ambiguous_source_fails_closed(self):
        cases = scenario("closed")
        self.assertEqual(len(cases), 12)
        for name, data in cases.items():
            with self.subTest(name=name):
                self.assertEqual(data["state"], "unknown")
                self.assertEqual(data["reason"], "untrusted_or_stale_snapshot")
                self.assertIs(data["execution"], False)
                self.assertNotIn("evidence_ref", data)
                self.assertNotIn("observed_at", data)
        source = (ROOT / "src" / "GitHubIntentTrustedEvidence.php").read_text(
            encoding="utf-8"
        ).lower()
        for forbidden in (
            "curl_init(", "file_get_contents(", "shell_exec(",
            "proc_open(", "passthru(", "github_pat_",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

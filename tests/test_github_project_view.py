import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def project(snapshot, now=1_000, max_age=300):
    php = r'''
require $argv[1];
$payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$result = \ControlBot\GitHub\GitHubProjectView::project(
    $payload['snapshot'],
    $payload['now'],
    $payload['max_age']
);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
'''
    payload = json.dumps({"snapshot": snapshot, "now": now, "max_age": max_age})
    run = subprocess.run(
        ["php", "-r", php, str(ROOT / "src/GitHubProjectView.php")],
        cwd=ROOT,
        input=payload,
        text=True,
        capture_output=True,
        check=True,
        timeout=30,
    )
    return json.loads(run.stdout)


def canonical_snapshot(observed_at=900):
    sha = "a" * 40
    return {
        "version": 1,
        "project_id": "factory-control",
        "observed_at": observed_at,
        "repositories": [
            {
                "repository_id": "controlbot",
                "repository": "pl0n3r/ControlBot",
                "source_ref": "https://github.com/pl0n3r/ControlBot",
                "observed_at": observed_at,
                "main_sha": sha,
                "checks": {
                    "items": [{"name": "CI", "status": "completed", "conclusion": "success"}],
                    "truncated": False,
                },
                "pull_requests": {
                    "items": [{
                        "number": 7,
                        "title": "Read only projection",
                        "draft": False,
                        "head_sha": "b" * 40,
                        "base_ref": "main",
                    }],
                    "truncated": False,
                },
                "issues": {
                    "items": [{"number": 9, "title": "Owner decision", "labels": ["decision"]}],
                    "truncated": False,
                },
                "latest_release": {"tag_name": "v0.1.20", "draft": False, "prerelease": False},
                "latest_workflow": {
                    "name": "Validate",
                    "status": "completed",
                    "conclusion": "success",
                    "head_sha": sha,
                    "run_number": 123,
                },
            }
        ],
    }


class GitHubProjectViewTests(unittest.TestCase):
    def test_view_model_preserves_source_freshness_and_truncation_without_reclassification(self):
        snapshot = canonical_snapshot()
        view = project(snapshot)

        self.assertEqual(view["project_id"], "factory-control")
        self.assertEqual(view["freshness"], "current")
        self.assertEqual(view["state"], "current")
        self.assertEqual(view["age_seconds"], 100)

        repo = view["repositories"][0]
        self.assertEqual(repo["source_ref"], "https://github.com/pl0n3r/ControlBot")
        self.assertEqual(repo["observed_at"], 900)
        self.assertEqual(repo["freshness"], "current")
        self.assertEqual(repo["state"], "current")
        self.assertFalse(repo["checks"]["truncated"])
        self.assertFalse(repo["pull_requests"]["truncated"])
        self.assertFalse(repo["issues"]["truncated"])
        self.assertEqual(repo["checks"]["items"][0]["conclusion"], "success")
        self.assertNotIn("healthy", json.dumps(view).lower())
        self.assertNotIn("green", json.dumps(view).lower())

        source = (ROOT / "src/GitHubProjectView.php").read_text()
        upper = source.upper()
        lower = source.lower()
        for method in ("'POST'", "'PUT'", "'PATCH'", "'DELETE'"):
            self.assertNotIn(method, upper)
        for token in ("dispatchworkflow", "movetag(", "closeissue(", "commentissue("):
            self.assertNotIn(token, lower)

    def test_ambiguous_partial_or_stale_snapshot_is_unknown_not_green(self):
        stale = project(canonical_snapshot(observed_at=500), now=1_000, max_age=300)
        self.assertEqual(stale["freshness"], "stale")
        self.assertEqual(stale["state"], "unknown")
        self.assertEqual(stale["repositories"][0]["state"], "unknown")

        truncated_snapshot = canonical_snapshot()
        truncated_snapshot["repositories"][0]["issues"]["truncated"] = True
        truncated = project(truncated_snapshot)
        self.assertEqual(truncated["freshness"], "current")
        self.assertEqual(truncated["state"], "unknown")
        self.assertEqual(truncated["repositories"][0]["state"], "unknown")
        self.assertTrue(truncated["repositories"][0]["issues"]["truncated"])

        partial_snapshot = canonical_snapshot()
        del partial_snapshot["repositories"][0]["source_ref"]
        partial = project(partial_snapshot)
        self.assertEqual(partial["state"], "unknown")
        self.assertEqual(partial["freshness"], "unknown")
        self.assertEqual(partial["repositories"], [])
        self.assertEqual(partial["reason"], "snapshot_invalid")

        ambiguous_snapshot = canonical_snapshot()
        ambiguous_snapshot["repositories"][0]["checks"]["truncated"] = "false"
        ambiguous = project(ambiguous_snapshot)
        self.assertEqual(ambiguous["state"], "unknown")
        self.assertEqual(ambiguous["freshness"], "unknown")
        self.assertNotIn("green", json.dumps(ambiguous).lower())


if __name__ == "__main__":
    unittest.main()

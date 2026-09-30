import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(script, name):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / script), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(run.stdout)


def html_scenario(name, active="all"):
    run = subprocess.run(
        ["php", str(ROOT / "tests" / "replay_ui_scenarios.php"), name, active],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return run.stdout


class AgentReplayTests(unittest.TestCase):
    def test_pr_replay_reconstructs_verified_lifecycle(self):
        data = scenario("agent_replay_lifecycle_scenarios.php", "complete")
        self.assertEqual(
            [stage["name"] for stage in data["stages"]],
            ["issue", "reservation", "plan", "commit", "review", "check", "merge", "deploy"],
        )
        self.assertTrue(all(stage["state"] == "observed" for stage in data["stages"]))
        self.assertEqual(
            [stage["events"][0]["event_id"] for stage in data["stages"]],
            [
                "evt-issue",
                "evt-reservation",
                "evt-plan",
                "evt-commit",
                "evt-review",
                "evt-check",
                "evt-merge",
                "evt-deploy",
            ],
        )

    def test_replay_is_stable_and_idempotent_with_timestamp_ties(self):
        data = scenario("agent_replay_core_scenarios.php", "stable")
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(
            [event["event_id"] for event in data["first"]["events"]],
            ["evt-00000001", "evt-00000002"],
        )

    def test_ci_states_remain_distinct_without_reinterpretation(self):
        data = scenario("agent_replay_core_scenarios.php", "states")
        self.assertEqual(
            [event["kind"] for event in data["events"]],
            ["success", "failure", "skipped", "startup_failure"],
        )
        self.assertEqual(data["conflicts"], [])

    def test_default_replay_excludes_transcripts_thoughts_and_secrets(self):
        data = scenario("agent_replay_core_scenarios.php", "sensitive")
        self.assertTrue(all(data["rejected"]))

    def test_handoff_preserves_workitem_identity_without_actor_confusion(self):
        data = scenario("agent_replay_core_scenarios.php", "handoff")
        self.assertEqual(
            {event["work_item_id"] for event in data["events"]},
            {"controlbot-365"},
        )
        self.assertEqual(
            [event["actor_ref"] for event in data["events"]],
            ["agent:alpha", "agent:beta"],
        )
        self.assertEqual(
            [event["kind"] for event in data["events"]],
            ["handoff", "attempted"],
        )

    def test_conflicting_evidence_is_preserved_as_unknown(self):
        data = scenario("agent_replay_core_scenarios.php", "conflict")
        self.assertEqual(len(data["events"]), 2)
        self.assertEqual({event["kind"] for event in data["events"]}, {"success", "failure"})
        self.assertEqual(len(data["conflicts"]), 1)
        conflict = data["conflicts"][0]
        self.assertEqual(conflict["event_id"], "evt-conflict-1")
        self.assertEqual(conflict["state"], "unknown")
        self.assertEqual(conflict["reason"], "conflicting_evidence")
        self.assertEqual(conflict["variant_count"], 2)

    def test_replay_filters_and_evidence_links_render(self):
        html = html_scenario("filters", "ci")
        for category in ("all", "code", "ci", "coordination", "decisions", "production", "security"):
            self.assertIn(f"?category={category}", html)
        self.assertIn("CI check evidence", html)
        self.assertNotIn("Code commit evidence", html)
        self.assertNotIn("Production deploy evidence", html)
        self.assertIn(
            'href="https://github.com/pl0n3r/ControlBot/actions/runs/123"',
            html,
        )

    def test_incident_78_fixture_preserves_success_startup_failure_and_skipped(self):
        incident = scenario("agent_replay_lifecycle_scenarios.php", "incident78")
        check = next(stage for stage in incident["stages"] if stage["name"] == "check")
        self.assertEqual(
            [event["kind"] for event in check["events"]],
            ["success", "startup_failure"],
        )

        draft = scenario("agent_replay_lifecycle_scenarios.php", "pr86")
        draft_check = next(stage for stage in draft["stages"] if stage["name"] == "check")
        self.assertEqual([event["kind"] for event in draft_check["events"]], ["skipped"])
        self.assertNotIn("success", [event["kind"] for event in draft_check["events"]])

        manual = scenario("agent_replay_lifecycle_scenarios.php", "manual")
        manual_check = next(stage for stage in manual["stages"] if stage["name"] == "check")
        self.assertEqual(manual_check["state"], "missing")
        self.assertEqual(
            [(row["stage"], row["event"]["kind"]) for row in manual["auxiliary_evidence"]],
            [("manual", "attempted"), ("documental", "requested")],
        )


if __name__ == "__main__":
    unittest.main()

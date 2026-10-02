import base64
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPOSITORIES = [
    "pl0n3r/Factory",
    "pl0n3r/Condor",
    "pl0n3r/GrindFlow",
    "pl0n3r/brvtal",
    "pl0n3r/ControlBot",
    "pl0n3r/AutoFactory",
    "pl0n3r/FactoryRunner",
]
STATES = [
    "READY",
    "ALL_BLOCKED",
    "NO_WORK",
    "WAITING_DECISION",
    "LIVE_GATED",
    "UNMATERIALIZED_WORK",
    "NO_WORK",
]


def project(repo, state, next_work=None):
    return {
        "repository_ref": repo,
        "state": state,
        "counts": {
            "completed": 0,
            "available": 1 if state == "READY" else 0,
            "reserved": 0,
            "blocked": 1 if state == "ALL_BLOCKED" else 0,
            "unmaterialized": 1 if state == "UNMATERIALIZED_WORK" else 0,
            "decision_required": 1 if state == "WAITING_DECISION" else 0,
            "live_only": 1 if state == "LIVE_GATED" else 0,
            "future_idea": 0,
            "already_materialized": 0,
        },
        "next_work": next_work,
        "unmaterialized_identities": [],
        "parent_progress": [],
    }


def inventory():
    projects = []
    for repo, state in zip(REPOSITORIES, STATES):
        projects.append(project(repo, state, f"{repo}#next" if state == "READY" else None))
    return {
        "version": 1,
        "source_ref": "github:pl0n3r/Factory@b0e531cd",
        "observed_at": 100,
        "freshness": "current",
        "projects": projects,
    }


def php_call(payload):
    encoded = base64.b64encode(json.dumps(payload, separators=(",", ":")).encode()).decode()
    runner = r'''<?php
require __SNAPSHOT__;
require __UI__;
$payload = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
try {
    $snapshot = \ControlBot\Business\FactoryLiveSnapshot::build(
        ["work_inventory" => $payload],
        200
    );
    $html = \ControlBot\Business\FactoryLiveUi::render($snapshot);
    echo json_encode(
        ["ok" => true, "snapshot" => $snapshot, "html" => $html],
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    echo json_encode(
        ["ok" => false, "error" => $e->getMessage()],
        JSON_THROW_ON_ERROR
    );
}
'''
    runner = runner.replace(
        "__SNAPSHOT__",
        json.dumps(str((ROOT / "src" / "FactoryLiveSnapshot.php").resolve())),
    ).replace(
        "__UI__",
        json.dumps(str((ROOT / "src" / "FactoryLiveUi.php").resolve())),
    )
    runner_root = ROOT / "build" / "test-runners"
    runner_root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=runner_root) as tempdir:
        script = Path(tempdir) / "factory_live_work_inventory.php"
        script.write_text(runner, encoding="utf-8")
        result = subprocess.run(
            ["php", str(script), encoded],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=30,
        )
    return json.loads(result.stdout)


class FactoryLiveWorkInventoryTests(unittest.TestCase):
    def test_factory_live_renders_canonical_work_states_without_reclassification(self):
        raw = inventory()
        result = php_call(raw)
        self.assertTrue(result["ok"], result)
        snapshot = result["snapshot"]
        work = snapshot["work_inventory"]
        self.assertEqual(work["source_ref"], raw["source_ref"])
        self.assertEqual(work["observed_at"], 100)
        self.assertEqual(work["freshness"], "current")
        self.assertEqual(work["projects"], raw["projects"])

        html = result["html"]
        self.assertIn('data-section="work_inventory"', html)
        for state in set(STATES):
            self.assertIn(f'data-work-state="{state}"', html)
        self.assertIn(raw["source_ref"], html)
        self.assertIn("freshness: current", html)

        section = html.split('data-section="work_inventory"', 1)[1].split("</section>", 1)[0]
        for reclassified in ("state-healthy", "state-degraded", "state-critical", "state-pending"):
            self.assertNotIn(reclassified, section)

    def test_owner_decision_and_blocked_states_keep_source_and_do_not_become_actions(self):
        raw = inventory()
        result = php_call(raw)
        self.assertTrue(result["ok"], result)
        section = result["html"].split('data-section="work_inventory"', 1)[1].split("</section>", 1)[0]

        self.assertIn("WAITING_DECISION", section)
        self.assertIn("ALL_BLOCKED", section)
        self.assertIn(raw["source_ref"], section)
        self.assertIn("freshness: current", section)
        for forbidden in ("<a ", "<form", "/tomar", "Reservar", "Merge", "Deploy"):
            self.assertNotIn(forbidden, section)

        bad_freshness = inventory()
        bad_freshness["freshness"] = "unknown"
        self.assertFalse(php_call(bad_freshness)["ok"])

        bad_source = inventory()
        bad_source["source_ref"] = ""
        self.assertFalse(php_call(bad_source)["ok"])


if __name__ == "__main__":
    unittest.main()

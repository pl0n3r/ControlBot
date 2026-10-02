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


def project(repo, state="NO_WORK", next_work=None):
    return {
        "repository_ref": repo,
        "state": state,
        "counts": {
            "completed": 0,
            "available": 1 if state == "READY" else 0,
            "reserved": 0,
            "blocked": 0,
            "unmaterialized": 0,
            "decision_required": 0,
            "live_only": 0,
            "future_idea": 0,
            "already_materialized": 0,
        },
        "next_work": next_work,
        "unmaterialized_identities": [],
        "parent_progress": [],
    }


def inventory(source_ref="github:pl0n3r/Factory@abc123", observed_at=100, freshness="current"):
    projects = [project(repo) for repo in REPOSITORIES]
    projects[0] = project("pl0n3r/Factory", "READY", "Factory#next")
    return {
        "version": 1,
        "source_ref": source_ref,
        "observed_at": observed_at,
        "freshness": freshness,
        "projects": projects,
    }


def php_call(payload, expected_source="github:pl0n3r/Factory@abc123", now=120, max_age=60, consumers=None):
    consumers = consumers or ["factory_live", "dashboard_summary"]
    encoded = base64.b64encode(json.dumps(payload, separators=(",", ":")).encode()).decode()
    consumer_json = base64.b64encode(json.dumps(consumers).encode()).decode()
    runner = r'''<?php
require __SOURCE__;
$payload = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$consumers = json_decode(base64_decode($argv[5]), true, 512, JSON_THROW_ON_ERROR);
try {
    $value = \ControlBot\Business\WorkInventoryDriftGuard::evaluate(
        $payload,
        $argv[2],
        (int) $argv[3],
        (int) $argv[4],
        $consumers
    );
    echo json_encode(["ok" => true, "value" => $value], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(["ok" => false, "error" => $e->getMessage()], JSON_THROW_ON_ERROR);
}
'''
    runner = runner.replace(
        "__SOURCE__",
        json.dumps(str((ROOT / "src" / "WorkInventoryDriftGuard.php").resolve())),
    )
    runner_root = ROOT / "build" / "test-runners"
    runner_root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=runner_root) as tempdir:
        script = Path(tempdir) / "work_inventory_drift_guard.php"
        script.write_text(runner, encoding="utf-8")
        result = subprocess.run(
            ["php", str(script), encoded, expected_source, str(now), str(max_age), consumer_json],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=30,
        )
    return json.loads(result.stdout)


class WorkInventoryDriftGuardTests(unittest.TestCase):
    def test_stale_or_source_mismatched_inventory_is_unknown_not_ready(self):
        stale = php_call(inventory(freshness="stale"))
        self.assertTrue(stale["ok"], stale)
        self.assertEqual(stale["value"]["status"], "UNKNOWN")
        self.assertFalse(stale["value"]["ready"])
        self.assertEqual(stale["value"]["reasons"], ["stale"])
        self.assertTrue(all(
            view["status"] == "UNKNOWN"
            and view["ready"] is False
            and view["projection"] is None
            for view in stale["value"]["consumers"].values()
        ))

        old = php_call(inventory(observed_at=1), now=120, max_age=60)
        self.assertTrue(old["ok"], old)
        self.assertEqual(old["value"]["status"], "UNKNOWN")
        self.assertEqual(old["value"]["reasons"], ["stale"])

        mismatch = php_call(
            inventory(source_ref="github:pl0n3r/Factory@other"),
            expected_source="github:pl0n3r/Factory@abc123",
        )
        self.assertTrue(mismatch["ok"], mismatch)
        self.assertEqual(mismatch["value"]["status"], "UNKNOWN")
        self.assertFalse(mismatch["value"]["ready"])
        self.assertEqual(mismatch["value"]["reasons"], ["source_mismatch"])

        invalid = inventory(freshness="unknown")
        self.assertFalse(php_call(invalid)["ok"])
        future = inventory(observed_at=121)
        self.assertFalse(php_call(future, now=120)["ok"])

    def test_same_canonical_projection_is_reused_across_dashboard_consumers(self):
        raw = inventory()
        result = php_call(
            raw,
            consumers=["factory_live", "dashboard_summary", "project_overview"],
        )
        self.assertTrue(result["ok"], result)
        guard = result["value"]
        self.assertEqual(guard["status"], "READY")
        self.assertTrue(guard["ready"])
        self.assertEqual(guard["reasons"], [])

        views = guard["consumers"]
        fingerprints = {view["projection_fingerprint"] for view in views.values()}
        self.assertEqual(fingerprints, {guard["projection_fingerprint"]})
        for view in views.values():
            self.assertEqual(view["projection"], raw)
            self.assertEqual(view["status"], "READY")
            self.assertTrue(view["ready"])

        source = (ROOT / "src" / "WorkInventoryDriftGuard.php").read_text(encoding="utf-8").lower()
        for forbidden in ("select_next", "curl_", "file_get_contents", "pdo", "'post'", "'patch'", "'delete'"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

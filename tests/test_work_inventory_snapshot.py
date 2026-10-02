import base64
import json
import subprocess
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


def inventory():
    projects = [project(repo) for repo in REPOSITORIES]
    projects[0] = project("pl0n3r/Factory", "READY", "Factory#next")
    projects[1]["parent_progress"] = [
        {"parent_ref": "Condor#parent", "completed": 1, "total": 2, "percent": 50}
    ]
    return {"version": 1, "projects": projects}


def php_call(payload, source_ref="github:pl0n3r/Factory@abc123", observed_at=123, freshness="current"):
    encoded = base64.b64encode(json.dumps(payload, separators=(",", ":")).encode()).decode()
    code = r'''
require "src/WorkInventorySnapshot.php";
$payload = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
try {
    $value = \ControlBot\Business\WorkInventorySnapshot::fromCanonical(
        $payload,
        $argv[2],
        (int) $argv[3],
        $argv[4]
    );
    echo json_encode(["ok" => true, "value" => $value], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(["ok" => false, "error" => $e->getMessage()], JSON_THROW_ON_ERROR);
}
'''
    result = subprocess.run(
        ["php", "-r", code, encoded, source_ref, str(observed_at), freshness],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return json.loads(result.stdout)


class WorkInventorySnapshotTests(unittest.TestCase):
    def test_canonical_projection_preserves_project_identity_state_source_and_freshness(self):
        raw = inventory()
        result = php_call(
            raw,
            source_ref="github:pl0n3r/Factory@deadbeef",
            observed_at=456,
            freshness="stale",
        )
        self.assertTrue(result["ok"], result)
        snapshot = result["value"]
        self.assertEqual(snapshot["source_ref"], "github:pl0n3r/Factory@deadbeef")
        self.assertEqual(snapshot["observed_at"], 456)
        self.assertEqual(snapshot["freshness"], "stale")
        self.assertEqual(
            [row["repository_ref"] for row in snapshot["projects"]],
            REPOSITORIES,
        )
        self.assertEqual(snapshot["projects"][0]["state"], "READY")
        self.assertEqual(snapshot["projects"][0]["next_work"], "Factory#next")
        self.assertEqual(
            snapshot["projects"][1]["parent_progress"],
            [{"parent_ref": "Condor#parent", "completed": 1, "total": 2, "percent": 50}],
        )
        self.assertEqual(snapshot["projects"], raw["projects"])

    def test_unknown_invalid_or_incomplete_projection_fails_closed(self):
        cases = []

        missing_repo = inventory()
        missing_repo["projects"] = missing_repo["projects"][:-1]
        cases.append((missing_repo, "current"))

        wrong_order = inventory()
        wrong_order["projects"][0], wrong_order["projects"][1] = (
            wrong_order["projects"][1],
            wrong_order["projects"][0],
        )
        cases.append((wrong_order, "current"))

        unknown_state = inventory()
        unknown_state["projects"][0]["state"] = "UNKNOWN"
        cases.append((unknown_state, "current"))

        extra_field = inventory()
        extra_field["projects"][0]["priority"] = "critical"
        cases.append((extra_field, "current"))

        incoherent_ready = inventory()
        incoherent_ready["projects"][0]["next_work"] = None
        cases.append((incoherent_ready, "current"))

        for payload, freshness in cases:
            with self.subTest(payload=payload):
                self.assertFalse(php_call(payload, freshness=freshness)["ok"])

        self.assertFalse(php_call(inventory(), freshness="unknown")["ok"])
        self.assertFalse(php_call(inventory(), source_ref="")["ok"])
        self.assertFalse(php_call(inventory(), observed_at=0)["ok"])

        source = (ROOT / "src" / "WorkInventorySnapshot.php").read_text()
        lowered = source.lower()
        for forbidden in (
            "apiclient",
            "apitransport",
            "curl_",
            "file_get_contents",
            "fsockopen",
            "entitymanager",
            "pdo",
            "'post'",
            "'patch'",
            "'put'",
            "'delete'",
        ):
            self.assertNotIn(forbidden, lowered)


if __name__ == "__main__":
    unittest.main()

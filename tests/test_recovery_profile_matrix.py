"""Offline Recovery profile matrix: policies are not operational recovery evidence."""

from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIG_DIR = ROOT / "config" / "recovery"
DOC = ROOT / "docs" / "disaster-recovery-contract.md"

# Factory's group inventory is broader than the profiles currently configured.
GROUP = frozenset({
    "autofactory", "brvtal", "condor", "controlbot",
    "factory", "factoryrunner", "grindflow",
})
DECLARED = frozenset({"autofactory", "brvtal", "condor", "controlbot", "factory", "grindflow"})

PHP_VALIDATE = r"""
require 'src/InfrastructureProvider.php';
require 'src/RecoveryProfile.php';
$cases = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$output = [];
foreach ($cases as $name => $raw) {
    try {
        if ($raw === null) {
            $output[$name] = ['accepted' => false,
                'status' => \ControlBot\Infrastructure\RecoveryProfile::effectiveStatus(null)];
            continue;
        }
        $p = \ControlBot\Infrastructure\RecoveryProfile::normalize($raw);
        $output[$name] = ['accepted' => true,
            'status' => \ControlBot\Infrastructure\RecoveryProfile::effectiveStatus($raw),
            'profile' => $p];
    } catch (\Throwable $e) {
        $output[$name] = ['accepted' => false, 'status' => 'unknown'];
    }
}
echo json_encode($output, JSON_THROW_ON_ERROR);
"""


def profiles():
    return {
        path.stem: json.loads(path.read_text(encoding="utf-8"))
        for path in sorted(CONFIG_DIR.glob("*.json"))
    }


def validate(cases):
    run = subprocess.run(
        ["php", "-r", PHP_VALIDATE], input=json.dumps(cases), text=True,
        capture_output=True, cwd=ROOT, timeout=30, check=False,
    )
    if run.returncode:
        raise AssertionError(run.stderr or run.stdout)
    return json.loads(run.stdout)


class RecoveryProfileMatrixTests(unittest.TestCase):
    def test_versioned_profiles_are_valid_unique_and_explicit(self):
        declared = profiles()
        self.assertEqual(set(declared), DECLARED)
        checks = validate(declared)
        seen = set()
        for name, check in checks.items():
            with self.subTest(project=name):
                self.assertTrue(check["accepted"], name)
                self.assertEqual(check["status"], "configured")
                profile = check["profile"]
                self.assertEqual(profile["project_ref"], f"controlbot:project/project-{name}")
                self.assertNotIn(profile["project_ref"], seen)
                seen.add(profile["project_ref"])
                self.assertGreater(profile["targets"]["rpo_minutes"], 0)
                self.assertGreater(profile["targets"]["rto_minutes"], 0)
                self.assertGreater(sum(profile["retention"].values()), 0)
                self.assertTrue(profile["encryption_required"])
                self.assertTrue(profile["strategy"]["offsite_required"])
                self.assertTrue(profile["strategy"]["immutable_required"])

    def test_missing_projects_remain_unknown_not_healthy(self):
        declared = profiles()
        absent = GROUP - set(declared)
        self.assertEqual(absent, {"factoryrunner"})
        stale = copy.deepcopy(declared["controlbot"])
        stale["freshness"] = "stale"
        checked = validate({**{k: None for k in sorted(absent)}, "stale": stale})
        for name in absent:
            self.assertFalse(checked[name]["accepted"])
            self.assertEqual(checked[name]["status"], "unknown")
        self.assertTrue(checked["stale"]["accepted"])
        self.assertEqual(checked["stale"]["status"], "unknown")
        # Even a configured policy is never a usable backup or HEALTHY evidence.
        self.assertNotIn("HEALTHY", [v["status"] for v in checked.values()])

    def test_condor_manifest_policy_is_configured_not_healthy(self):
        # Factory #305: DECLARED_NOT_MEASURED, not a backup/restore receipt.
        condor = profiles()["condor"]
        result = validate({"condor": condor})["condor"]
        self.assertTrue(result["accepted"])
        self.assertEqual(result["status"], "configured")
        profile = result["profile"]
        self.assertEqual(profile["targets"], {"rpo_minutes": 15, "rto_minutes": 60})
        self.assertEqual(profile["retention"], {
            "hourly": 24, "daily": 7, "weekly": 8, "monthly": 12,
        })
        self.assertEqual(profile["sources"], {
            "database": "required", "media": "required",
            "repository": "required", "configuration": "not_applicable",
        })
        self.assertEqual(profile["restore_drill_cadence_hours"], 168)
        self.assertEqual(profile["source_ref"], "https://github.com/pl0n3r/Factory/issues/305")
        self.assertTrue(profile["strategy"]["offsite_required"])
        self.assertTrue(profile["strategy"]["immutable_required"])
        self.assertTrue(profile["encryption_required"])
        for forbidden in ("HEALTHY", "backup_receipt", "observed_rpo", "observed_rto",
                          "credential", "api_key", "object_lock_verified"):
            self.assertNotIn(forbidden, condor)
        self.assertEqual(
            validate({"factoryrunner": None})["factoryrunner"]["status"], "unknown",
        )

    def test_invalid_or_sensitive_profile_fails_closed(self):
        base = profiles()["controlbot"]
        cases = {}
        missing = copy.deepcopy(base)
        del missing["targets"]
        cases["missing-targets"] = missing
        empty = copy.deepcopy(base)
        empty["retention"] = {k: 0 for k in empty["retention"]}
        cases["empty-retention"] = empty
        secret = copy.deepcopy(base)
        secret["api_key"] = "forbidden-value"
        cases["secret-injection"] = secret
        provenance = copy.deepcopy(base)
        provenance["source_ref"] = None
        cases["fresh-without-provenance"] = provenance
        wrong_ref = copy.deepcopy(base)
        wrong_ref["project_ref"] = "untrusted:project/controlbot"
        cases["wrong-project-ref"] = wrong_ref
        for name, result in validate(cases).items():
            with self.subTest(case=name):
                self.assertFalse(result["accepted"])
                self.assertEqual(result["status"], "unknown")

    def test_documented_scope_does_not_promise_live_backups(self):
        doc = DOC.read_text(encoding="utf-8")
        for name in GROUP:
            self.assertIn(f"`{name}`", doc)
        for expected in (
            "FactoryRunner", "UNKNOWN", "Backblaze B2",
            "#577", "#858", "No confirma backups", "no autoriza provisión",
        ):
            self.assertIn(expected, doc)


if __name__ == "__main__":
    unittest.main()

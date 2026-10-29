"""#1194: exercise real RecoveryProfile policy without operational claims.
This file never reads the coverage matrix or contacts providers/production.
"""
from __future__ import annotations

from copy import deepcopy
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / "config" / "recovery"
DECLARED = frozenset(("autofactory", "brvtal", "condor", "controlbot", "factory", "grindflow"))
GROUP = DECLARED | {"factoryrunner"}
STRATEGY = {
    "copies_required": 3, "media_types_required": 2,
    "offsite_required": True, "immutable_required": True,
    "undetected_restore_failures_target": 0,
}
# These statuses/receipts cannot be established by declarative policy.
NOT_RECEIPTS = frozenset((
    "backup_receipt", "backup_verified", "restore_receipt", "restore_drill",
    "object_lock_verified", "checksum_verified", "observed_rpo", "observed_rto",
    "restorable", "healthy", "provider_credentials", "api_key", "secret",
))
PHP_PROBE = r'''
require 'src/InfrastructureProvider.php';
require 'src/RecoveryProfile.php';
$cases = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$out = [];
foreach ($cases as $name => $raw) {
    try {
        if ($raw === null) {
            $out[$name] = ['accepted' => false,
                'status' => \ControlBot\Infrastructure\RecoveryProfile::effectiveStatus(null)];
            continue;
        }
        $value = \ControlBot\Infrastructure\RecoveryProfile::normalize($raw);
        $out[$name] = ['accepted' => true, 'profile' => $value,
            'status' => \ControlBot\Infrastructure\RecoveryProfile::effectiveStatus($raw)];
    } catch (\Throwable $e) {
        $out[$name] = ['accepted' => false, 'status' => 'unknown'];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
'''


def local_profiles() -> dict[str, dict]:
    """Only versioned local metadata, not provider receipts."""
    return {p.stem: json.loads(p.read_text(encoding="utf-8"))
            for p in sorted(CONFIG.glob("*.json"))}


def exercise_php(cases: dict[str, dict | None]) -> dict:
    """Call the PHP product class, fail closed if PHP or result is absent."""
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("PHP CLI required; cannot attest PHP behavior")
    result = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        input=json.dumps(cases), capture_output=True, text=True, cwd=ROOT,
        timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError("PHP product probe failed: " + result.stderr[-400:])
    data = json.loads(result.stdout)
    if not isinstance(data, dict) or set(data) != set(cases):
        raise AssertionError("Incomplete PHP product probe")
    return data


class CoberturaControlbot11Tests(unittest.TestCase):
    """CB-0142..0148/0151..0156 offline; no operational receipt authority."""

    def test_contrato_principal(self) -> None:
        configs = local_profiles()
        self.assertEqual(set(configs), DECLARED)
        observations = exercise_php({**configs, "factoryrunner": None})
        self.assertEqual(set(GROUP), set(observations))
        self.assertEqual(observations["factoryrunner"],
                         {"accepted": False, "status": "unknown"})
        seen_projects, seen_manifests = set(), set()
        for name in sorted(DECLARED):
            with self.subTest(project=name):
                result = observations[name]
                self.assertTrue(result["accepted"])
                self.assertEqual(result["status"], "configured")
                value = result["profile"]
                self.assertEqual(value["version"], 1)
                self.assertEqual(value["project_ref"],
                                 f"controlbot:project/project-{name}")
                self.assertTrue(value["manifest_ref"].startswith(
                    "controlbot:recovery-manifest/"))
                self.assertNotIn(value["project_ref"], seen_projects)
                self.assertNotIn(value["manifest_ref"], seen_manifests)
                seen_projects.add(value["project_ref"])
                seen_manifests.add(value["manifest_ref"])
                self.assertEqual(value["strategy"], STRATEGY)
                self.assertTrue(value["encryption_required"])
                self.assertGreater(value["targets"]["rpo_minutes"], 0)
                self.assertGreater(value["targets"]["rto_minutes"], 0)
                self.assertGreater(sum(value["retention"].values()), 0)
                self.assertEqual(set(value["retention"]),
                                 {"hourly", "daily", "weekly", "monthly"})
                self.assertEqual(set(value["sources"]),
                                 {"database", "media", "repository", "configuration"})
                self.assertIn("required", value["sources"].values())
                self.assertTrue(set(value["sources"].values()) <=
                                {"required", "not_applicable"})
                self.assertGreater(value["restore_drill_cadence_hours"], 0)
                self.assertEqual(value["freshness"], "fresh")
                self.assertIsInstance(value["observed_at"], int)
                self.assertTrue(value["source_ref"].startswith("https://github.com/"))
                self.assertFalse(NOT_RECEIPTS & set(value))
                self.assertNotIn("HEALTHY", json.dumps(value))
        condor = observations["condor"]["profile"]
        self.assertEqual(condor["targets"], {"rpo_minutes": 15, "rto_minutes": 60})
        self.assertEqual(condor["retention"],
                         {"hourly": 24, "daily": 7, "weekly": 8, "monthly": 12})
        self.assertEqual(condor["sources"]["configuration"], "not_applicable")
        self.assertEqual(condor["source_ref"],
                         "https://github.com/pl0n3r/Factory/issues/305")
        self.assertEqual(observations["factory"]["profile"]["sources"]["repository"],
                         "required")

    def test_falla_cerrado(self) -> None:
        base = local_profiles()["controlbot"]
        cases: dict[str, dict | None] = {"missing": None}
        for key in ("manifest_ref", "sources", "targets", "retention",
                    "strategy", "encryption_required", "freshness"):
            invalid = deepcopy(base)
            invalid.pop(key)
            cases["missing-" + key] = invalid
        for name, edits in {
            "wrong-version": {"version": 2},
            "wrong-project": {"project_ref": "other:project/false"},
            "blank-manifest": {"manifest_ref": "controlbot:recovery-manifest/"},
            "unsafe-manifest": {"manifest_ref": "controlbot:recovery-manifest/token-secret"},
            "bad-rpo": {"targets": {"rpo_minutes": 0}},
            "bad-rto": {"targets": {"rto_minutes": "60"}},
            "no-retention": {"retention": {
                "hourly": 0, "daily": 0, "weekly": 0, "monthly": 0}},
            "bad-source": {"sources": {"database": "optional"}},
            "weak-copies": {"strategy": {"copies_required": 2}},
            "weak-media": {"strategy": {"media_types_required": 1}},
            "weak-offsite": {"strategy": {"offsite_required": False}},
            "weak-immutability": {"strategy": {"immutable_required": False}},
            "weak-restore": {"strategy": {"undetected_restore_failures_target": 1}},
            "no-encryption": {"encryption_required": False},
            "unknown-freshness": {"freshness": "healthy"},
            "unknown-with-source": {"freshness": "unknown", "observed_at": None},
            "stale-without-source": {"freshness": "stale", "source_ref": None},
        }.items():
            invalid = deepcopy(base)
            for key, value in edits.items():
                if isinstance(value, dict):
                    invalid[key].update(value)
                else:
                    invalid[key] = value
            cases[name] = invalid
        secret = deepcopy(base)
        secret["api_key"] = "synthetic-invalid-value"
        cases["extra-secret-field"] = secret
        empty = deepcopy(base)
        empty["sources"] = dict.fromkeys(base["sources"], "not_applicable")
        cases["all-not-applicable"] = empty
        for name, result in exercise_php(cases).items():
            with self.subTest(case=name):
                self.assertEqual(result, {"accepted": False, "status": "unknown"})
        stale = deepcopy(base)
        stale["freshness"] = "stale"
        unknown = deepcopy(base)
        unknown.update({"freshness": "unknown", "source_ref": None,
                        "observed_at": None})
        checks = exercise_php({"stale": stale, "unknown": unknown})
        for name, result in checks.items():
            with self.subTest(status=name):
                self.assertTrue(result["accepted"])
                self.assertEqual(result["status"], "unknown")
        self.assertEqual(checks["stale"]["profile"]["source_ref"], base["source_ref"])
        self.assertIsNone(checks["unknown"]["profile"]["source_ref"])
        self.assertIsNone(checks["unknown"]["profile"]["observed_at"])

    def test_sin_datos_reales(self) -> None:
        # Local manifests and the resulting projection contain metadata only.
        profiles = local_profiles()
        self.assertEqual(set(profiles), DECLARED)
        for name, result in exercise_php(profiles).items():
            with self.subTest(project=name):
                self.assertTrue(result["accepted"])
                self.assertFalse(NOT_RECEIPTS & set(result["profile"]))
                self.assertTrue(set(result["profile"]) <= {
                    "version", "project_ref", "manifest_ref", "targets",
                    "retention", "sources", "strategy", "encryption_required",
                    "restore_drill_cadence_hours", "source_ref", "observed_at",
                    "freshness",
                })
                self.assertNotIn("healthy", result["status"].lower())
        self.assertNotIn("curl_", PHP_PROBE)
        self.assertNotIn("file_get_contents('http", PHP_PROBE)
        self.assertNotIn("BackupGate::", PHP_PROBE)
        self.assertNotIn("BackupReceipt::", PHP_PROBE)

    def test_gate_validar_presente(self) -> None:
        # The exact-SHA Validar check is externally enforced by CI/branch protection.
        result = exercise_php({"controlbot": local_profiles()["controlbot"],
                               "factoryrunner": None})
        self.assertEqual(result["controlbot"]["status"], "configured")
        self.assertEqual(result["factoryrunner"]["status"], "unknown")
        self.assertNotIn("HEALTHY", json.dumps(result))


if __name__ == "__main__":
    unittest.main()

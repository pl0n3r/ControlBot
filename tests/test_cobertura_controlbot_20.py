#!/usr/bin/env python3
"""#1203: tests of real ControlBot code, with synthetic offline inputs only."""
from __future__ import annotations

from hashlib import sha256
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
ROWS = frozenset(f"CB-{n:04d}" for n in range(311, 329))
# B: real-world evidence, C: non-executable scope/policy, D: missing code.
CLASSIFIED = {
    "CB-0314": ("C", "Inventory of earlier acceptance tests, not new runtime behavior."),
    "CB-0317": ("C", "Unchanged permissions/tokens/limits are a scope constraint."),
    "CB-0321": ("D", "Real backup, deploy, rollback and restore proof are not implemented."),
    "CB-0322": ("B", "Owner-only health probing requires genuine production hosting."),
    "CB-0327": ("B", "Terminal exact-HEAD Validar is observable only on GitHub."),
    "CB-0328": ("C", "Fixture and revert guidance do not constitute runtime behavior."),
}
OFFLINE = ROWS - CLASSIFIED.keys()
BLOCKERS = "tests/factory_blockers_panel_scenarios.php"
PLANNED = "tests/factory_planned_state_scenarios.php"


def php_case(script: str, mode: str) -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI is unavailable")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(ROOT / script), mode],
        cwd=ROOT, capture_output=True, text=True, timeout=40, check=False,
    )
    if result.returncode:
        raise AssertionError(f"PHP {mode}: {result.stderr[-500:]}")
    return json.loads(result.stdout)


def git(directory: Path, *args: str) -> str:
    run = subprocess.run(["git", *args], cwd=directory, check=True,
                         capture_output=True, text=True, timeout=15)
    return run.stdout.strip()


def candidate_fixture(root: Path) -> tuple[Path, str]:
    # Fresh synthetic Git repository; never accesses the real checkout's data.
    files = {
        "index.php": "<?php // synthetic entrypoint\n",
        ".htaccess": "Require valid-user\n",
        "public/index.php": "<?php // dashboard\n",
        "public/health.php": "<?php // owner-only fixture\n",
        "src/HealthIdentityHttpEndpoint.php": "<?php // health fixture\n",
        "src/Synthetic.php": "<?php // synthetic runtime\n",
        "config/version.json": '{"version":"0.1.24"}\n',
        ".env": "SYNTHETIC_NOT_FOR_ARCHIVE\n",
        "tests/not-runtime.txt": "not runtime\n",
    }
    for name, value in files.items():
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(value, encoding="utf-8")
    adapter = root / "ops/factory/build"
    adapter.parent.mkdir(parents=True, exist_ok=True)
    adapter.write_bytes((ROOT / "ops/factory/build").read_bytes())
    adapter.chmod(0o755)
    git(root, "init", "-q")
    git(root, "config", "user.email", "offline@example.invalid")
    git(root, "config", "user.name", "Synthetic Fixture")
    git(root, "add", "-A")
    git(root, "commit", "-qm", "fixture")
    sha = git(root, "rev-parse", "HEAD")
    (root / "untracked-secret.txt").write_text("NOT_PACKAGED", encoding="utf-8")
    return adapter, sha


def build(adapter: Path, sha: str, phase: str = "construccion") -> subprocess.CompletedProcess:
    return subprocess.run(
        [str(adapter)], cwd=adapter.parents[2],
        env={**os.environ, "PHASE": phase, "GITHUB_SHA": sha},
        capture_output=True, text=True, timeout=30, check=False,
    )


class CoberturaControlbot20Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(ROWS), 18)
        self.assertEqual(len(OFFLINE), 12)
        self.assertEqual(OFFLINE | CLASSIFIED.keys(), ROWS)

        # CB-0311 / CB-0312: exercise genuine PHP collector, parser and UI.
        grounded = php_case(BLOCKERS, "grounded")
        self.assertFalse(grounded["failed"], grounded)
        first = grounded["blockers"][0]
        self.assertEqual(first["actor"], "agent")
        self.assertEqual(first["cause"], "Pruebas CI sin terminar")
        self.assertEqual(first["next_action"], "Reparar verificacion exacta")
        self.assertEqual(first["evidence_status"], "DECLARED")
        self.assertGreater(first["age_seconds"], 0)
        self.assertIn("DECLARED", grounded["html"])
        self.assertIn("UNKNOWN", grounded["html"])
        # CB-0313: bounded fake HTTPS GET transport, no writes or unescaped UI.
        self.assertTrue(grounded["calls"])
        self.assertTrue(all(
            c["method"] == "GET" and c["url"].startswith("/repos/pl0n3r/")
            for c in grounded["calls"]
        ))
        self.assertNotIn("<script", grounded["html"].lower())
        self.assertNotIn("<form", grounded["html"].lower())

        # CB-0315 / CB-0316: deterministic top-K and invalid evidence ranking.
        late = php_case(BLOCKERS, "topk-late")
        self.assertFalse(late["failed"], late)
        self.assertEqual(len(late["blockers"]), 50)
        self.assertEqual(sum(r["mvp_impact"] is True for r in late["blockers"]), 7)
        self.assertEqual(
            [r["issue_ref"] for r in late["blockers"]],
            [r["issue_ref"] for r in php_case(BLOCKERS, "topk-reversed")["blockers"]],
        )
        self.assertFalse(any(
            r["issue_ref"].endswith("#997") and r["mvp_impact"] is True
            for r in late["blockers"]
        ))
        self.assertEqual(late["summary"]["omitted"]["blockers"], 321)
        # CB-0318: global stop remains UNKNOWN with no invented impact.
        stopped = php_case(BLOCKERS, "topk-stop")
        self.assertFalse(stopped["failed"], stopped)
        self.assertEqual(stopped["blockers"][0]["issue_ref"], "github:pl0n3r/Factory#767")
        self.assertEqual(stopped["blockers"][0]["evidence_status"], "UNKNOWN")
        self.assertIsNone(stopped["blockers"][0]["mvp_impact"])

        # CB-0319 / CB-0320: invoke real adapter on a fully synthetic Git tree.
        with TemporaryDirectory(prefix="cb20-") as temp:
            adapter, source_sha = candidate_fixture(Path(temp))
            initial = build(adapter, source_sha)
            self.assertEqual(initial.returncode, 0, initial.stderr)
            artifact = Path(temp) / "var/factory/build.tar.gz"
            digest = sha256(artifact.read_bytes()).hexdigest()
            repeat = build(adapter, source_sha)
            self.assertEqual(repeat.returncode, 0, repeat.stderr)
            self.assertEqual(sha256(artifact.read_bytes()).hexdigest(), digest)
            with tarfile.open(artifact, "r:gz") as archive:
                members = archive.getnames()
                self.assertEqual(
                    members, sorted(members[:-1]) + ["factory-build-manifest.json"]
                )
                for excluded in (".env", "tests/not-runtime.txt", "untracked-secret.txt"):
                    self.assertNotIn(excluded, members)
                manifest = json.load(archive.extractfile("factory-build-manifest.json"))
                self.assertEqual(manifest["source_sha"], source_sha)
                self.assertEqual(manifest["release_version"], "0.1.24")
                self.assertEqual(manifest["phase"], "construccion")
                for member in archive.getmembers():
                    self.assertEqual((member.mtime, member.uid, member.gid), (0, 0, 0))
                for entry in manifest["files"]:
                    content = archive.extractfile(entry["path"]).read()
                    self.assertEqual(sha256(content).hexdigest(), entry["sha256"])

        # CB-0323 through CB-0326: real collector, read-only projection and UI.
        planned = php_case(PLANNED, "valid")
        self.assertFalse(planned["failed"], planned)
        self.assertIs(planned["view"]["read_only"], True)
        self.assertEqual(len(planned["view"]["projects"]), 7)
        self.assertEqual(len(planned["evidence"]["blockers"]), 7)
        self.assertEqual(len(planned["evidence"]["work"]), 14)
        self.assertTrue(planned["calls"])
        self.assertTrue(all(
            c["method"] == "GET" and c["path"].startswith("/repos/pl0n3r/")
            for c in planned["calls"]
        ))
        self.assertNotIn("<script", planned["html"].lower())
        self.assertNotIn("<form", planned["html"].lower())
        for project in planned["view"]["projects"]:
            self.assertEqual(project["planned"], 2)
            self.assertEqual(project["blocked"], 1)
            self.assertEqual(project["available"], 1)
            self.assertIsNone(project["planned_unlockable"])
        self.assertIsNone(planned["view"]["queue_empty"])

    def test_falla_cerrado(self) -> None:
        for mode in ("no-provenance", "invalid-time", "unsafe", "duplicate"):
            with self.subTest(mode=mode):
                result = php_case(BLOCKERS, mode)
                self.assertFalse(result["failed"], result)
                row = next(
                    r for r in result["blockers"]
                    if r["issue_ref"] == "github:pl0n3r/Factory#10"
                )
                self.assertEqual(row["evidence_status"], "UNKNOWN")
                self.assertIsNone(row["mvp_impact"])
                self.assertIsNone(row["age_seconds"])
        self.assertTrue(php_case(BLOCKERS, "invalid-page")["failed"])
        for mode in ("available-planned", "available-blocked", "blocked-planned"):
            with self.subTest(mode=mode):
                result = php_case(PLANNED, mode)
                self.assertFalse(result["failed"], result)
                first = result["view"]["projects"][0]
                self.assertEqual(first["activity_state"], "UNKNOWN")
                self.assertIsNone(first["available"])
                self.assertIsNone(first["planned"])
                self.assertIsNone(result["view"]["queue_empty"])
        self.assertTrue(php_case(PLANNED, "bad-page")["failed"])
        self.assertTrue(php_case(PLANNED, "bad-label")["failed"])
        # Invalid SHA or non-construction phase must not produce an artifact.
        with TemporaryDirectory(prefix="cb20-invalid-") as temp:
            adapter, source_sha = candidate_fixture(Path(temp))
            for sha, phase in (
                ("invalid", "construccion"),
                ("a" * 40, "construccion"),
                (source_sha, "live"),
            ):
                with self.subTest(sha=sha[:8], phase=phase):
                    result = build(adapter, sha, phase)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertFalse((Path(temp) / "var/factory/build.tar.gz").exists())

    def test_sin_datos_reales(self) -> None:
        observed = php_case(PLANNED, "legacy")
        self.assertFalse(observed["failed"], observed)
        self.assertTrue(all(c["method"] == "GET" for c in observed["calls"]))
        self.assertNotIn("<button", observed["html"].lower())
        self.assertNotIn("token=", observed["html"].lower())
        self.assertTrue(all(
            category in {"B", "C", "D"} and bool(reason)
            for category, reason in CLASSIFIED.values()
        ))
        self.assertFalse({"requests", "urllib", "socket", "httpx"} & set(globals()))

    def test_validar_exigido(self) -> None:
        # A static gate regression does NOT replace terminal CI on exact HEAD.
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("    name: Validar", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertIn("phase: construccion", workflow)


if __name__ == "__main__":
    unittest.main()

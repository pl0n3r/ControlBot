"""Deterministic offline tests of Factory@v1 ControlBot build-stage adapter."""
from __future__ import annotations

from hashlib import sha256
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tarfile
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "ops/factory/build"
RUNBOOK = ROOT / "docs/factory-deploy-build-adapter.md"


class FactoryDeployBuildAdapterTests(unittest.TestCase):
    def setUp(self):
        self.temp = TemporaryDirectory(prefix="cb-factory-build-")
        self.addCleanup(self.temp.cleanup)
        self.checkout = Path(self.temp.name)
        for relative, data in {
            "index.php": "<?php // owner-only bootstrap\n",
            ".htaccess": "Require valid-user\n",
            "public/index.php": "<?php // dashboard\n",
            "public/health.php": "<?php // owner-only health\n",
            "public/theme.css": "body { color: black; }\n",
            "src/HealthIdentityHttpEndpoint.php": "<?php // signed receipt\n",
            "src/Example.php": "<?php // business logic\n",
            "config/version.json": '{"version":"0.1.24"}\n',
            "ops/factory/build": ADAPTER.read_text(encoding="utf-8"),
            ".env": "PRIVATE_TEST_SECRET_DO_NOT_PACKAGE\n",
            "tests/extra.txt": "not runtime\n",
        }.items():
            path = self.checkout / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(data, encoding="utf-8")
        (self.checkout / "ops/factory/build").chmod(0o755)
        self.git("init", "-q")
        self.git("config", "user.email", "offline@example.invalid")
        self.git("config", "user.name", "Offline Fixture")
        self.commit()
        (self.checkout / "untracked-secret.txt").write_text("NOT_IN_ARCHIVE", encoding="utf-8")

    def git(self, *args):
        return subprocess.run(["git", *args], cwd=self.checkout, check=True,
                              capture_output=True, text=True, timeout=15).stdout.strip()

    def commit(self):
        self.git("add", "-A")
        self.git("commit", "-qm", "fixture")
        self.sha = self.git("rev-parse", "HEAD")

    def run_build(self, *, phase="construccion", sha=None, extra_env=None):
        env = {**os.environ, "PHASE": phase, "GITHUB_SHA": self.sha if sha is None else sha}
        env.update(extra_env or {})
        return subprocess.run([str(self.checkout / "ops/factory/build")],
                              cwd=self.checkout, env=env, capture_output=True,
                              text=True, timeout=20, check=False)

    def archive(self):
        return self.checkout / "var/factory/build.tar.gz"

    def test_acceptance_packages_only_committed_allowlisted_runtime(self):
        process = self.run_build()
        self.assertEqual(process.returncode, 0, process.stderr)
        with tarfile.open(self.archive(), "r:gz") as archive:
            names = archive.getnames()
            self.assertEqual(names, sorted(names[:-1]) + ["factory-build-manifest.json"])
            self.assertIn("src/Example.php", names)
            self.assertIn("public/index.php", names)
            self.assertIn(".htaccess", names)
            self.assertNotIn(".env", names)
            self.assertNotIn("tests/extra.txt", names)
            self.assertNotIn("untracked-secret.txt", names)
            self.assertNotIn("ops/factory/build", names)
            self.assertTrue(all(m.isfile() and not m.issym() for m in archive.getmembers()))
            manifest = json.load(archive.extractfile("factory-build-manifest.json"))
            self.assertEqual(manifest["source_sha"], self.sha)
            self.assertEqual(manifest["release_version"], "0.1.24")
            self.assertEqual(manifest["phase"], "construccion")
            self.assertEqual(manifest["version"], 1)
            self.assertEqual(manifest["files"][0]["path"], ".htaccess")

    def test_reproducible_archive_and_exact_sha_manifest(self):
        self.assertEqual(self.run_build().returncode, 0)
        first = sha256(self.archive().read_bytes()).digest()
        self.assertEqual(self.run_build().returncode, 0)
        self.assertEqual(sha256(self.archive().read_bytes()).digest(), first)
        self.assertEqual(self.archive().stat().st_mode & 0o777, 0o600)
        with tarfile.open(self.archive(), "r:gz") as archive:
            record = json.load(archive.extractfile("factory-build-manifest.json"))
            for part in record["files"]:
                content = archive.extractfile(part["path"]).read()
                self.assertEqual(part["sha256"], sha256(content).hexdigest())
                self.assertEqual(part["bytes"], len(content))
                member = archive.getmember(part["path"])
                self.assertEqual((member.mtime, member.uid, member.gid), (0, 0, 0))

    def test_archive_uses_authenticated_commit_blobs_even_if_index_hides_change(self):
        source = self.checkout / "src/Example.php"
        committed_bytes = source.read_bytes()
        source.write_bytes(b"<?php // altered but hidden by index\\n")
        self.git("update-index", "--assume-unchanged", "src/Example.php")
        try:
            # Malicious caller Git environment must not change repository identity.
            fake_env = {
                "GIT_DIR": str(self.checkout / "nonexistent-git-dir"),
                "GIT_WORK_TREE": str(self.checkout.parent),
                "GIT_INDEX_FILE": str(self.checkout / "nonexistent-index"),
                "GIT_OBJECT_DIRECTORY": str(self.checkout / "nonexistent-objects"),
            }
            process = self.run_build(extra_env=fake_env)
            self.assertEqual(process.returncode, 0, process.stderr)
            with tarfile.open(self.archive(), "r:gz") as archive:
                self.assertEqual(archive.extractfile("src/Example.php").read(), committed_bytes)
                record = json.load(archive.extractfile("factory-build-manifest.json"))
                actual = next(row for row in record["files"] if row["path"] == "src/Example.php")
                self.assertEqual(actual["sha256"], sha256(committed_bytes).hexdigest())
        finally:
            self.git("update-index", "--no-assume-unchanged", "src/Example.php")

    def test_refuses_symlink_in_artifact_parent(self):
        with TemporaryDirectory(prefix="cb-build-external-") as external:
            outside = Path(external)
            for parent in ("var", "var/factory"):
                with self.subTest(parent=parent):
                    factory_dir = self.checkout / "var/factory"
                    if factory_dir.is_symlink():
                        factory_dir.unlink()
                    elif factory_dir.is_dir():
                        factory_dir.rmdir()
                    var_dir = self.checkout / "var"
                    if var_dir.is_symlink():
                        var_dir.unlink()
                    elif var_dir.is_dir():
                        var_dir.rmdir()
                    if parent == "var":
                        var_dir.symlink_to(outside, target_is_directory=True)
                    else:
                        var_dir.mkdir()
                        factory_dir.symlink_to(outside, target_is_directory=True)
                    process = self.run_build()
                    self.assertNotEqual(process.returncode, 0)
                    self.assertFalse((outside / "build.tar.gz").exists())
                    self.assertIn("artifact parent", process.stderr)

    def test_rejects_missing_provenance_or_nonconstruction_phase(self):
        for phase, commit in (("live", self.sha), ("", self.sha),
                              ("construccion", "invalid"),
                              ("construccion", "a" * 40)):
            with self.subTest(phase=phase, sha=commit):
                result = self.run_build(phase=phase, sha=commit)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.archive().exists())
        # Exact HEAD alone is insufficient if a tracked file is modified or staged.
        source = self.checkout / "src/Example.php"
        source.write_text("<?php // forged runtime\n", encoding="utf-8")
        self.assertNotEqual(self.run_build().returncode, 0)
        self.git("add", "src/Example.php")
        self.assertNotEqual(self.run_build().returncode, 0)
        self.assertFalse(self.archive().exists())

    def test_refuses_symlink_or_missing_runtime_entrypoint(self):
        sensitive = self.checkout / "secret-private.txt"
        sensitive.write_text("SENSITIVE_FIXTURE", encoding="utf-8")
        (self.checkout / "src/link.php").symlink_to(sensitive)
        self.commit()
        failure = self.run_build()
        self.assertNotEqual(failure.returncode, 0)
        self.assertFalse(self.archive().exists())
        (self.checkout / "src/link.php").unlink()
        (self.checkout / "public/index.php").unlink()
        self.commit()
        missing = self.run_build()
        self.assertNotEqual(missing.returncode, 0)
        self.assertFalse(self.archive().exists())

    def test_no_live_activation_secrets_or_remote_execution(self):
        source = ADAPTER.read_text(encoding="utf-8")
        docs = RUNBOOK.read_text(encoding="utf-8")
        self.assertTrue(ADAPTER.stat().st_mode & stat.S_IXUSR)
        self.assertNotIn("requests.", source)
        self.assertNotIn("urlopen(", source)
        self.assertNotIn("ssh ", source)
        self.assertNotIn("rsync", source)
        self.assertNotIn("HOSTINGER_SSH_HOST", source)
        self.assertNotIn("DEPLOY_SSH_KEY", source)
        self.assertIn("PHASE", source)
        self.assertIn("GITHUB_SHA", source)
        self.assertIn("git", source)
        self.assertIn("Factory@v1", docs)
        self.assertIn("D-059", docs)
        self.assertIn("owner-only", docs)
        self.assertIn("DEPLOY_ENABLED", docs)
        self.assertIn("No despliega", docs)
        self.assertIn("backup", docs)
        self.assertIn("rollback", docs)


if __name__ == "__main__":
    unittest.main()

import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HTACCESS = (ROOT / ".htaccess").read_text(encoding="utf-8")


def read_secret(env: dict, repo_root: str = str(ROOT)) -> str:
    code = (
        "require 'src/SecretSource.php';"
        "$v = ControlBot\\Security\\SecretSource::read('CONTROLBOT_TEST_SECRET', $argv[1]);"
        "echo $v === null ? 'NULL' : $v;"
    )
    merged = {k: v for k, v in os.environ.items() if not k.startswith("CONTROLBOT_TEST_SECRET")}
    merged.update(env)
    out = subprocess.run(["php", "-r", code, repo_root], cwd=ROOT, env=merged,
                         capture_output=True, text=True, timeout=20)
    if out.returncode != 0 or not out.stdout:
        raise AssertionError(f"php falló o no devolvió salida (código {out.returncode}): {out.stderr.strip()[:300]}")
    return out.stdout


class SecretSourceTests(unittest.TestCase):
    def test_environment_variable_wins(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            f = Path(d) / "also-configured.key"
            f.write_text("from-file", encoding="utf-8")
            self.assertEqual("from-env", read_secret({
                "CONTROLBOT_TEST_SECRET": "from-env",
                "CONTROLBOT_TEST_SECRET_FILE": str(f),
            }))

    def test_private_file_outside_the_repo_is_read_and_trimmed(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            f = Path(d) / "s.key"
            f.write_text("  from-file\n", encoding="utf-8")
            self.assertEqual("from-file", read_secret({"CONTROLBOT_TEST_SECRET_FILE": str(f)}))

    def test_file_inside_the_deployed_tree_is_refused(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            f = Path(d) / "s.key"
            f.write_text("leak", encoding="utf-8")
            self.assertEqual("NULL", read_secret({"CONTROLBOT_TEST_SECRET_FILE": str(f)}, repo_root=d))

    def test_missing_root_and_implicit_root_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            outside = Path(d) / "safe.key"
            outside.write_text("synthetic", encoding="utf-8")
            self.assertEqual("NULL", read_secret(
                {"CONTROLBOT_TEST_SECRET_FILE": str(outside)}, repo_root=str(Path(d) / "missing")))

        # Omitting the root argument must still protect files shipped with this repository.
        code = (
            "require 'src/SecretSource.php';"
            "$v = ControlBot\\Security\\SecretSource::read('CONTROLBOT_TEST_SECRET');"
            "echo $v === null ? 'NULL' : $v;"
        )
        env = {k: v for k, v in os.environ.items() if not k.startswith("CONTROLBOT_TEST_SECRET")}
        env["CONTROLBOT_TEST_SECRET_FILE"] = str(ROOT / "src/SecretSource.php")
        result = subprocess.run(["php", "-r", code], cwd=ROOT, env=env,
                                capture_output=True, text=True, timeout=20)
        self.assertEqual(0, result.returncode, result.stderr[:300])
        self.assertEqual("NULL", result.stdout.strip())

    def test_missing_relative_empty_or_oversized_files_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            empty = Path(d) / "e"; empty.write_text("", encoding="utf-8")
            exactly_max = Path(d) / "at-limit"
            exactly_max.write_bytes(b"x" * 4096)
            just_over = Path(d) / "over-limit"
            just_over.write_bytes(b"x" * 4097)
            big = Path(d) / "b"; big.write_text("x" * 5000, encoding="utf-8")
            self.assertEqual("x" * 4096, read_secret({"CONTROLBOT_TEST_SECRET_FILE": str(exactly_max)}))
            for value in (str(Path(d) / "nope"), "relative/path", str(empty), str(just_over), str(big), ""):
                self.assertEqual("NULL", read_secret({"CONTROLBOT_TEST_SECRET_FILE": value}), value)

        # Filesize is only a precheck: the read must also be bounded and length-checked.
        source = (ROOT / "src/SecretSource.php").read_text(encoding="utf-8")
        self.assertIn("file_get_contents($real, false, null, 0, self::MAX_BYTES + 1)", source)
        self.assertIn("strlen($content) > self::MAX_BYTES", source)

    def test_file_changed_after_size_check_still_fails_closed(self) -> None:
        # Simulate a concurrent grow after a successful filesize() precheck.
        # The namespaced PHP functions replace only the calls from SecretSource.
        with tempfile.TemporaryDirectory() as d:
            f = Path(d) / "changed.key"
            f.write_text("x", encoding="utf-8")
            code = (
                "namespace ControlBot\\Security {"
                "function filesize($path) { return 1; }"
                "function file_get_contents($path, $use_include_path = false, "
                "$context = null, $offset = 0, $length = null) {"
                "if ($length !== 4097) { throw new \\RuntimeException('unbounded read'); }"
                "return str_repeat('x', 4097);"
                "}"
                "}"
                "namespace {"
                "require 'src/SecretSource.php';"
                "$v = ControlBot\\Security\\SecretSource::read('CONTROLBOT_TEST_SECRET', $argv[1]);"
                "echo $v === null ? 'NULL' : $v;"
                "}"
            )
            env = {k: v for k, v in os.environ.items() if not k.startswith("CONTROLBOT_TEST_SECRET")}
            env["CONTROLBOT_TEST_SECRET_FILE"] = str(f)
            result = subprocess.run(["php", "-r", code, str(ROOT)], cwd=ROOT, env=env,
                                    capture_output=True, text=True, timeout=20)
            self.assertEqual(0, result.returncode, result.stderr[:300])
            self.assertEqual("NULL", result.stdout)


    def _read_named(self, name: str, root: str, env: dict | None = None) -> str:
        code = (
            "require 'src/SecretSource.php';"
            "$v = ControlBot\\Security\\SecretSource::read($argv[1], $argv[2]);"
            "echo $v === null ? 'NULL' : $v;"
        )
        merged = {k: v for k, v in os.environ.items() if not k.startswith("CONTROLBOT_")}
        merged.update(env or {})
        out = subprocess.run(["php", "-r", code, name, root], cwd=ROOT, env=merged,
                             capture_output=True, text=True, timeout=20)
        self.assertEqual(0, out.returncode, out.stderr[:300])
        return out.stdout

    def test_conventional_private_file_is_used_as_fallback(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            site = Path(d) / "public_html"; site.mkdir()
            private = Path(d) / "private"; private.mkdir()
            (private / "vault.key").write_text("  key-from-private\n", encoding="utf-8")
            (private / "owner-password.hash").write_text("hash-from-private", encoding="utf-8")
            (private / "other.key").write_text("nope", encoding="utf-8")
            self.assertEqual("key-from-private", self._read_named("CONTROLBOT_VAULT_KEY", str(site)))
            self.assertEqual("hash-from-private", self._read_named("CONTROLBOT_OWNER_PASSWORD_HASH", str(site)))
            self.assertEqual("NULL", self._read_named("CONTROLBOT_OTHER_SECRET", str(site)))
            self.assertEqual("NULL", self._read_named("CONTROLBOT_OWNER_LOGIN", str(site)))

    def test_fallback_keeps_priority_and_containment(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            site = Path(d) / "public_html"; site.mkdir()
            private = Path(d) / "private"; private.mkdir()
            (private / "vault.key").write_text("conventional", encoding="utf-8")
            explicit = Path(d) / "explicit.key"; explicit.write_text("explicit", encoding="utf-8")
            self.assertEqual("explicit", self._read_named(
                "CONTROLBOT_VAULT_KEY", str(site), {"CONTROLBOT_VAULT_KEY_FILE": str(explicit)}))
            self.assertEqual("direct", self._read_named(
                "CONTROLBOT_VAULT_KEY", str(site), {"CONTROLBOT_VAULT_KEY": "direct"}))
            (private / "vault.key").write_text("", encoding="utf-8")
            self.assertEqual("NULL", self._read_named("CONTROLBOT_VAULT_KEY", str(site)))
            (private / "vault.key").write_bytes(b"x" * 4097)
            self.assertEqual("NULL", self._read_named("CONTROLBOT_VAULT_KEY", str(site)))
        with tempfile.TemporaryDirectory() as d:
            # private/ is only honoured as a sibling of the deployed root, never inside it.
            root = Path(d) / "app"; root.mkdir()
            (root / "private").mkdir()
            (root / "private" / "vault.key").write_text("inside-tree", encoding="utf-8")
            self.assertEqual("NULL", self._read_named("CONTROLBOT_VAULT_KEY", str(root)))

    def test_htaccess_names_only_private_paths_never_secret_values(self) -> None:
        for name in ("CONTROLBOT_VAULT_KEY_FILE", "CONTROLBOT_OWNER_PASSWORD_HASH_FILE"):
            line = next(x for x in HTACCESS.splitlines() if x.startswith("SetEnv " + name))
            self.assertIn("/private/", line)
            self.assertNotIn("public_html", line)
        self.assertNotRegex(HTACCESS, r"(?m)^SetEnv CONTROLBOT_(VAULT_KEY|OWNER_PASSWORD_HASH)\s")

    def test_entrypoints_use_the_helper(self) -> None:
        self.assertIn("SecretSource::read('CONTROLBOT_VAULT_KEY'", (ROOT / "public/index.php").read_text(encoding="utf-8"))
        self.assertIn("SecretSource::read('CONTROLBOT_OWNER_PASSWORD_HASH'", (ROOT / "public/login.php").read_text(encoding="utf-8"))


    def test_runbook_is_in_claimed_path_and_not_legacy_hostinger_document(self) -> None:
        guide_path = ROOT / "docs/secretos-en-archivo-hostinger.md"
        guide = guide_path.read_text(encoding="utf-8")
        for required in ("CONTROLBOT_VAULT_KEY_FILE", "CONTROLBOT_OWNER_PASSWORD_HASH_FILE",
                         "4096", "D-059", "0600", "public_html"):
            self.assertIn(required, guide)
        legacy = (ROOT / "docs/hostinger-root-publication.md").read_text(encoding="utf-8")
        self.assertNotIn("## Secretos en archivo privado", legacy)


if __name__ == "__main__":
    unittest.main()

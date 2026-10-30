import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HTACCESS = (ROOT / ".htaccess").read_text(encoding="utf-8")


def read_secret(env: dict, repo_root: str = "/nonexistent") -> str:
    code = (
        "require 'src/SecretSource.php';"
        "$v = ControlBot\\Security\\SecretSource::read('CONTROLBOT_TEST_SECRET', $argv[1]);"
        "echo $v === null ? 'NULL' : $v;"
    )
    merged = {k: v for k, v in os.environ.items() if not k.startswith("CONTROLBOT_TEST_SECRET")}
    merged.update(env)
    out = subprocess.run(["php", "-r", code, repo_root], cwd=ROOT, env=merged,
                         capture_output=True, text=True, timeout=20)
    if not out.stdout.strip():
        raise AssertionError(f"php sin salida (código {out.returncode}): {out.stderr.strip()[:300]}")
    return out.stdout.strip()


class SecretSourceTests(unittest.TestCase):
    def test_environment_variable_wins(self) -> None:
        self.assertEqual("from-env", read_secret({"CONTROLBOT_TEST_SECRET": "from-env"}))

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

    def test_missing_relative_empty_or_oversized_files_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as d:
            empty = Path(d) / "e"; empty.write_text("", encoding="utf-8")
            big = Path(d) / "b"; big.write_text("x" * 5000, encoding="utf-8")
            for value in (str(Path(d) / "nope"), "relative/path", str(empty), str(big), ""):
                self.assertEqual("NULL", read_secret({"CONTROLBOT_TEST_SECRET_FILE": value}), value)

    def test_htaccess_names_only_private_paths_never_secret_values(self) -> None:
        for name in ("CONTROLBOT_VAULT_KEY_FILE", "CONTROLBOT_OWNER_PASSWORD_HASH_FILE"):
            line = next(x for x in HTACCESS.splitlines() if x.startswith("SetEnv " + name))
            self.assertIn("/private/", line)
            self.assertNotIn("public_html", line)
        self.assertNotRegex(HTACCESS, r"(?m)^SetEnv CONTROLBOT_(VAULT_KEY|OWNER_PASSWORD_HASH)\s")

    def test_entrypoints_use_the_helper(self) -> None:
        self.assertIn("SecretSource::read('CONTROLBOT_VAULT_KEY'", (ROOT / "public/index.php").read_text(encoding="utf-8"))
        self.assertIn("SecretSource::read('CONTROLBOT_OWNER_PASSWORD_HASH'", (ROOT / "public/login.php").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()

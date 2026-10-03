import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INDEX = ROOT / "index.php"
HTACCESS = ROOT / ".htaccess"
RUNBOOK = ROOT / "docs/hostinger-root-publication.md"


class HostingerRootPublicationTests(unittest.TestCase):
    def test_root_index_is_minimal_bootstrap_to_authenticated_public_entrypoint(self):
        source = INDEX.read_text()
        self.assertIn("public/index.php", source)
        self.assertIn("realpath", source)
        self.assertIn("is_file", source)
        self.assertIn("http_response_code(503)", source)
        self.assertIn("require $publicEntrypoint", source)
        self.assertNotIn("FactoryOrchestratorLiveEndpoint", source)

        missing = subprocess.run(
            ["php", str(INDEX)], cwd=ROOT, text=True, capture_output=True, check=True, timeout=10
        )
        self.assertEqual(missing.stdout, "Service unavailable.\n")
        self.assertEqual(missing.stderr, "")

    def test_htaccess_denies_internal_paths_dotfiles_markdown_and_yaml_by_default(self):
        source = HTACCESS.read_text()
        lower = source.lower()
        self.assertIn("options -indexes", lower)
        self.assertIn("directoryindex index.php", lower)
        for internal in ("src", "config", "docs", "scripts", "tests", "vendor", "lecciones", "openapi", "readme"):
            self.assertIn(internal, lower)
        self.assertIn(".git", lower)
        self.assertRegex(lower, r"md\|ya\?ml\|json")
        self.assertIn("public/index\\.php", source)
        self.assertNotIn("Require all granted", source)

    def test_public_surface_remains_reachable_without_exposing_internal_sources(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            shutil.copy2(INDEX, root / "index.php")
            public = root / "public"
            public.mkdir()
            (public / "index.php").write_text('<?php echo "public-entrypoint\\n";')
            run = subprocess.run(
                ["php", str(root / "index.php")], cwd=root, text=True, capture_output=True, check=True, timeout=10
            )
            self.assertEqual(run.stdout, "public-entrypoint\n")
            self.assertEqual(run.stderr, "")

        htaccess = HTACCESS.read_text().lower()
        self.assertNotRegex(htaccess, r"rewriterule\s+\^public.*\-\s+\[f")
        self.assertRegex(htaccess, r"\^\(\?:src\|config\|docs\|scripts\|tests")
        self.assertIn("rewriterule ^(?:index\\.php$|public(?:/|$)) - [l,nc]", htaccess)
        self.assertNotIn("rewritecond %{request_filename} !-f", htaccess)
        self.assertNotIn("rewritecond %{request_filename} !-d", htaccess)

    def test_runbook_records_hostinger_root_mapping_and_keeps_live_verification_human_gated(self):
        text = RUNBOOK.read_text().lower()
        for required in ("public_html", "raíz del repositorio", "public/index.php", "503", "#624"):
            self.assertIn(required, text)
        for boundary in ("no activa deploy", "no se prueba hostinger real", "no se declara el sistema live"):
            self.assertIn(boundary, text)
        self.assertIn("aunque exista físicamente en la raíz", text)
        self.assertIn("fuera de `public/`", text)


if __name__ == "__main__":
    unittest.main()

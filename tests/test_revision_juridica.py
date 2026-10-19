"""Regresiones del paquete jurídico offline y su sincronización."""
import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "generar_revision_juridica", ROOT / "scripts/generar_revision_juridica.py"
)
module = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(module)


class RevisionJuridicaTests(unittest.TestCase):
    def test_generated_bundle_matches_sources(self):
        self.assertEqual([], module.check_bundle(ROOT))
        stamp, sha = module.stamp_from_existing(ROOT)
        generated = module.expected_files(ROOT, stamp, sha)
        self.assertEqual(len(module.SOURCES) + 2, len(generated))
        for source in module.SOURCES:
            self.assertEqual(
                (ROOT / source).read_bytes(),
                generated["revision-juridica/" + Path(source).name],
            )
        workflow = (ROOT / ".github/workflows/revision-juridica-sync.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("permissions:\n  contents: read\n  issues: write\n", workflow)
        self.assertIn("schedule:", workflow)
        self.assertIn("branches: [main]", workflow)
        self.assertIn("revision-juridica-desfase", workflow)
        self.assertIn("generar_revision_juridica.py --check", workflow)
        self.assertNotIn("pull-requests: write", workflow)
        self.assertNotIn("gh pr merge", workflow)

    def test_read_only_check_rejects_tampered_or_missing_copies(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for path in (*module.SOURCES, "datos.yml", "config/version.json"):
                (root / path).parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT / path, root / path)
            stamp, sha = module.stamp_from_existing(ROOT)
            for path, content in module.expected_files(root, stamp, sha).items():
                (root / path).parent.mkdir(parents=True, exist_ok=True)
                (root / path).write_bytes(content)
            self.assertEqual([], module.check_bundle(root))
            altered = root / "revision-juridica/aviso-privacidad.md"
            altered.write_bytes(b"alterado\n")
            before = altered.read_bytes()
            self.assertTrue(module.check_bundle(root))
            self.assertEqual(before, altered.read_bytes())
            altered.write_bytes((root / module.SOURCES[0]).read_bytes())
            (root / "revision-juridica/retencion.md").unlink()
            self.assertTrue(module.check_bundle(root))
            (root / "revision-juridica/paquete-completo.md").write_bytes(b"viejo")
            self.assertTrue(module.check_bundle(root))

    def test_invalid_stamp_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            target = root / "revision-juridica/00-LEEME.md"
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("approved\n", encoding="utf-8")
            self.assertTrue(module.check_bundle(root))


if __name__ == "__main__":
    unittest.main()

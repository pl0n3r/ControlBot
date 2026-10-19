"""Regresiones del paquete jurídico offline y su sincronización."""
import hashlib
import importlib.util
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest import mock

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
        # Validar que los issues nuevos son aceptables por el coordinador.
        issue_template = workflow.split("cat > \"$body\" <<'ISSUE'", 1)[1].split(
            "\n          ISSUE", 1
        )[0]
        for heading in (
            "### Contexto", "### Alcance", "### Fuera de alcance",
            "### Criterios de aceptación", "### Contrato ejecutable",
            "### Rutas reclamadas",
        ):
            with self.subTest(heading=heading):
                self.assertIn(heading, issue_template)
        self.assertEqual(issue_template.count("<!-- factory-acceptance "), 1)
        self.assertEqual(issue_template.count("<!-- factory-plan-task "), 1)
        self.assertIn("generar_revision_juridica.py --check", workflow)
        self.assertNotIn("pull-requests: write", workflow)
        self.assertNotIn("gh pr merge", workflow)

    def test_sha256_fingerprints_match_sources(self):
        self.assertEqual(
            module.source_sha256(b"abc"),
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
        )
        index = (ROOT / "revision-juridica/00-LEEME.md").read_text("utf-8")
        self.assertIn("Huellas SHA-256 de las fuentes incluidas:", index)
        for source in module.SOURCES:
            expected = hashlib.sha256((ROOT / source).read_bytes()).hexdigest()
            self.assertIn(f"- {source}: {expected}", index)

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
            # Rollback offline: restaurar las copias y el paquete de las fuentes.
            (root / "revision-juridica/retencion.md").write_bytes(
                (root / "docs/privacidad/retencion.md").read_bytes()
            )
            bundle = module.expected_files(root, stamp, sha)
            (root / "revision-juridica/paquete-completo.md").write_bytes(
                bundle["revision-juridica/paquete-completo.md"]
            )
            self.assertEqual([], module.check_bundle(root))
            # Un cambio de versión también invalida el índice, sin escribir.
            (root / "config/version.json").write_text(
                '{"version":"99.0.0"}\n', encoding="utf-8"
            )
            self.assertTrue(module.check_bundle(root))

    def test_dirty_sources_are_denied_before_generation(self):
        with mock.patch.object(module.subprocess, "run") as run:
            run.return_value.returncode = 1
            with self.assertRaisesRegex(ValueError, "sin confirmar"):
                module.generate(ROOT)
            self.assertEqual(run.call_count, 1)

    def test_invalid_stamp_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            target = root / "revision-juridica/00-LEEME.md"
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("approved\n", encoding="utf-8")
            self.assertTrue(module.check_bundle(root))


    def test_invalid_inputs_fail_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "config").mkdir()
            config = root / "config/version.json"
            data = root / "datos.yml"
            sha = "f32a96e355c874f13ce557e8ac4167f32fce271b"
            config.write_text('{"version":"0.1.24"}', encoding="utf-8")
            data.write_text('{"treatments":[{"providers":[]}]}', encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "SHA de origen inválido"):
                module.expected_files(root, "2026-10-19", "bad-sha")
            with self.assertRaises(ValueError):
                module.expected_files(root, "2026-02-31", sha)
            for invalid in ('{"version":42}', '{"version":"version-one"}'):
                with self.subTest(version=invalid):
                    config.write_text(invalid, encoding="utf-8")
                    with self.assertRaisesRegex(ValueError, "Versión no verificable"):
                        module.expected_files(root, "2026-10-19", sha)
            config.write_text('{"version":"0.1.24"}', encoding="utf-8")
            for invalid, reason in (
                ('{"treatments":[]}', "Inventario"),
                ('{"treatments":"bad"}', "Inventario"),
                ('{"treatments":["bad"]}', "Proveedores no verificables"),
                ('{"treatments":[{"providers":"bad"}]}', "Proveedores no verificables"),
                ('{"treatments":[{"providers":[15]}]}', "Proveedor inválido"),
                ('{"treatments":[{"providers":[" "]}]}', "Proveedor inválido"),
            ):
                with self.subTest(treatments=invalid):
                    data.write_text(invalid, encoding="utf-8")
                    with self.assertRaisesRegex(ValueError, reason):
                        module.expected_files(root, "2026-10-19", sha)

    def test_generate_clean_sources_and_cli_modes(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            for path in (*module.SOURCES, "datos.yml", "config/version.json"):
                (root / path).parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(ROOT / path, root / path)
            (root / "datos.yml").write_text(
                '{"treatments":[{"providers":["Proveedor de prueba"]}]}',
                encoding="utf-8",
            )
            source_sha = "f32a96e355c874f13ce557e8ac4167f32fce271b"
            with mock.patch.object(module.subprocess, "run") as clean:
                clean.return_value.returncode = 0
                with mock.patch.object(
                    module.subprocess, "check_output", return_value=source_sha
                ):
                    module.generate(root)
                self.assertEqual(1, clean.call_count)
            self.assertEqual([], module.check_bundle(root))
            index = (root / "revision-juridica/00-LEEME.md").read_text("utf-8")
            self.assertIn("- Declarado en datos.yml: Proveedor de prueba", index)
            with mock.patch.object(module, "ROOT", root):
                with mock.patch("sys.argv", ["generar_revision_juridica.py", "--check"]):
                    self.assertEqual(0, module.main())
                (root / "revision-juridica/retencion.md").write_bytes(b"desfase")
                before = (root / "revision-juridica/retencion.md").read_bytes()
                with mock.patch("sys.argv", ["generar_revision_juridica.py", "--check"]):
                    self.assertEqual(1, module.main())
                self.assertEqual(
                    before, (root / "revision-juridica/retencion.md").read_bytes()
                )
                with mock.patch("sys.argv", ["generar_revision_juridica.py"]):
                    with mock.patch.object(module, "generate") as generate:
                        self.assertEqual(0, module.main())
                        generate.assert_called_once_with(root)


if __name__ == "__main__":
    unittest.main()

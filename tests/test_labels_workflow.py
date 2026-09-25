import os
import re
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path
from urllib.parse import quote

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "etiquetas.yml"
SCRIPT = ROOT / "scripts" / "cleanup-legacy-label.sh"

ALIASES = {
    "prioridad: normal": "prioridad: media",
    "calidad": "tipo: calidad",
    "seguridad": "tipo: seguridad",
    "deuda técnica": "tipo: deuda técnica",
    "accesibilidad": "tipo: accesibilidad",
}


class LabelsWorkflowTests(unittest.TestCase):
    def run_cleanup(self, scenario: str):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            log = tmp_path / "gh.log"
            stub = tmp_path / "gh"
            stub.write_text(
                textwrap.dedent(
                    """                    #!/usr/bin/env python3
                    import json
                    import os
                    import sys
                    from urllib.parse import quote

                    aliases = {
                        "prioridad: normal": "prioridad: media",
                        "calidad": "tipo: calidad",
                        "seguridad": "tipo: seguridad",
                        "deuda técnica": "tipo: deuda técnica",
                        "accesibilidad": "tipo: accesibilidad",
                    }
                    args = sys.argv[1:]
                    rendered = " ".join(args)
                    with open(os.environ["GH_STUB_LOG"], "a", encoding="utf-8") as handle:
                        handle.write(rendered + "\n")

                    scenario = os.environ["GH_STUB_SCENARIO"]
                    url = args[-1] if args else ""

                    if "/issues?state=all&labels=" in url:
                        if scenario == "used":
                            print(json.dumps([[{"number": 1}]]))
                        else:
                            print(json.dumps([[]]))
                        raise SystemExit(0)

                    if "--method" in args and "DELETE" in args:
                        raise SystemExit(0)

                    if "/labels/" in url:
                        label = url.rsplit("/labels/", 1)[1]
                        legacy = {quote(name, safe="") for name in aliases}
                        canonical = {quote(name, safe="") for name in aliases.values()}
                        if scenario == "aliases-absent" and label in legacy:
                            print("gh: Not Found (HTTP 404)", file=sys.stderr)
                            raise SystemExit(1)
                        if scenario == "targets-missing" and label in canonical:
                            print("gh: Not Found (HTTP 404)", file=sys.stderr)
                            raise SystemExit(1)
                        print("{}")
                        raise SystemExit(0)

                    print("unexpected gh invocation: " + rendered, file=sys.stderr)
                    raise SystemExit(9)
                    """
                ),
                encoding="utf-8",
            )
            stub.chmod(0o755)
            env = os.environ.copy()
            env.update(
                {
                    "GH_BIN": str(stub),
                    "GH_STUB_LOG": str(log),
                    "GH_STUB_SCENARIO": scenario,
                    "REPOSITORIO": "pl0n3r/ControlBot",
                }
            )
            result = subprocess.run(
                ["bash", str(SCRIPT)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
            )
            calls = log.read_text(encoding="utf-8") if log.exists() else ""
            return result, calls

    def test_cleanup_covers_exact_factory_legacy_aliases(self):
        script = SCRIPT.read_text(encoding="utf-8")
        match = re.search(r"^ALIASES=\$'(.*?)'$", script, re.MULTILINE)
        self.assertIsNotNone(match)
        decoded = match.group(1).replace("\\n", "\n").replace("\\t", "\t")
        pairs = dict(line.split("\t", 1) for line in decoded.splitlines())
        self.assertEqual(pairs, ALIASES)

    def test_cleanup_fails_closed_when_legacy_alias_is_used(self):
        result, calls = self.run_cleanup("used")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("todavía tiene 1 uso(s)", result.stderr)
        self.assertNotIn("--method DELETE", calls)

    def test_cleanup_deletes_only_unused_alias_when_target_exists(self):
        result, calls = self.run_cleanup("unused")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(calls.count("--method DELETE"), len(ALIASES))
        for legacy in ALIASES:
            encoded = quote(legacy, safe="")
            self.assertIn(
                f"issues?state=all&labels={encoded}&per_page=100",
                calls,
            )
            self.assertIn(
                f"--method DELETE repos/pl0n3r/ControlBot/labels/{encoded}",
                calls,
            )
        for canonical in ALIASES.values():
            encoded = quote(canonical, safe="")
            self.assertNotIn(
                f"--method DELETE repos/pl0n3r/ControlBot/labels/{encoded}",
                calls,
            )

    def test_cleanup_preserves_alias_when_target_is_missing(self):
        result, calls = self.run_cleanup("targets-missing")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("--method DELETE", calls)
        self.assertNotIn("/issues?state=all&labels=", calls)
        self.assertIn("se conserva", result.stdout)

    def test_cleanup_is_idempotent_when_aliases_are_absent(self):
        result, calls = self.run_cleanup("aliases-absent")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("--method DELETE", calls)
        self.assertNotIn("/issues?state=all&labels=", calls)

    def test_sync_depends_on_cleanup_and_keeps_factory_reusable(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        cleanup_at = workflow.index("  limpiar_etiqueta_legacy:")
        sync_at = workflow.index("  sync:")
        validate_at = workflow.index("  validar-issue:")
        cleanup = workflow[cleanup_at:sync_at]
        sync = workflow[sync_at:validate_at]

        self.assertIn("run: bash scripts/cleanup-legacy-label.sh", cleanup)
        self.assertIn("needs: limpiar_etiqueta_legacy", sync)
        self.assertIn("needs.limpiar_etiqueta_legacy.result == 'success'", sync)
        self.assertIn("uses: pl0n3r/factory/.github/workflows/etiquetas.yml@v1", sync)

    def test_cleanup_job_has_minimum_permissions(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        cleanup_at = workflow.index("  limpiar_etiqueta_legacy:")
        sync_at = workflow.index("  sync:")
        cleanup = workflow[cleanup_at:sync_at]

        self.assertTrue(workflow.startswith("name: Etiquetas\n"))
        self.assertIn("permissions:\n  contents: read\n", workflow)
        self.assertIn("permissions:\n      contents: read\n      issues: write\n", cleanup)
        self.assertNotIn("pull-requests: write", cleanup)
        self.assertIn("persist-credentials: false", cleanup)
        self.assertIn(
            "actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1",
            cleanup,
        )


if __name__ == "__main__":
    unittest.main()

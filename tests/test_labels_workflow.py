import os
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "etiquetas.yml"
SCRIPT = ROOT / "scripts" / "cleanup-legacy-label.sh"


class LabelsWorkflowTests(unittest.TestCase):
    def run_cleanup(self, scenario: str):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            log = tmp_path / "gh.log"
            stub = tmp_path / "gh"
            stub.write_text(
                textwrap.dedent(
                    """                    #!/usr/bin/env bash
                    set -euo pipefail
                    printf '%s\n' "$*" >> "$GH_STUB_LOG"
                    args="$*"

                    if [[ "$args" == *"/issues?state=all&labels="* ]]; then
                      if [[ "$GH_STUB_SCENARIO" == "used" ]]; then
                        printf '%s\n' '[[{"number":1}]]'
                      else
                        printf '%s\n' '[[]]'
                      fi
                      exit 0
                    fi

                    if [[ "$args" == *"--method DELETE"* ]]; then
                      exit 0
                    fi

                    if [[ "$args" == *"/labels/"* ]]; then
                      if [[ "$GH_STUB_SCENARIO" == "absent" ]]; then
                        echo "gh: Not Found (HTTP 404)" >&2
                        exit 1
                      fi
                      printf '%s\n' '{}'
                      exit 0
                    fi

                    echo "unexpected gh invocation: $args" >&2
                    exit 9
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

    def test_legacy_cleanup_fails_closed_when_label_is_used(self):
        result, calls = self.run_cleanup("used")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("todavía tiene 1 uso(s)", result.stderr)
        self.assertNotIn("--method DELETE", calls)

    def test_legacy_cleanup_is_idempotent_and_bounded(self):
        absent, absent_calls = self.run_cleanup("absent")
        self.assertEqual(absent.returncode, 0, absent.stderr)
        self.assertNotIn("--method DELETE", absent_calls)

        unused, unused_calls = self.run_cleanup("unused")
        self.assertEqual(unused.returncode, 0, unused.stderr)
        self.assertIn("issues?state=all&labels=prioridad%3A%20normal&per_page=100", unused_calls)
        self.assertEqual(unused_calls.count("--method DELETE"), 1)

    def test_sync_depends_on_cleanup_and_keeps_factory_reusable(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        cleanup_at = workflow.index("  limpiar-etiqueta-legacy:")
        sync_at = workflow.index("  sync:")
        validate_at = workflow.index("  validar-issue:")
        cleanup = workflow[cleanup_at:sync_at]
        sync = workflow[sync_at:validate_at]

        self.assertIn("run: bash scripts/cleanup-legacy-label.sh", cleanup)
        self.assertIn("needs: limpiar-etiqueta-legacy", sync)
        self.assertIn("needs.limpiar-etiqueta-legacy.result == 'success'", sync)
        self.assertIn("uses: pl0n3r/factory/.github/workflows/etiquetas.yml@v1", sync)

    def test_cleanup_job_has_minimum_permissions(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        cleanup_at = workflow.index("  limpiar-etiqueta-legacy:")
        sync_at = workflow.index("  sync:")
        cleanup = workflow[cleanup_at:sync_at]

        self.assertTrue(workflow.startswith("name: Etiquetas\n"))
        self.assertIn("permissions:\n  contents: read\n", workflow)
        self.assertIn("permissions:\n      contents: read\n      issues: write\n", cleanup)
        self.assertNotIn("pull-requests: write", cleanup)
        self.assertIn("persist-credentials: false", cleanup)
        self.assertIn("actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1", cleanup)


if __name__ == "__main__":
    unittest.main()

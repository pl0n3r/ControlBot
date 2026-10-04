import os
import re
import subprocess
import tempfile
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
    def run_cleanup(self, scenario: str, repeats: int = 1):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            log = tmp_path / "gh.log"
            stub = tmp_path / "gh"
            stub_lines = [
                "#!/usr/bin/env python3",
                "import json",
                "import os",
                "import sys",
                "from urllib.parse import quote",
                "",
                "aliases = {",
                "    'prioridad: normal': 'prioridad: media',",
                "    'calidad': 'tipo: calidad',",
                "    'seguridad': 'tipo: seguridad',",
                "    'deuda técnica': 'tipo: deuda técnica',",
                "    'accesibilidad': 'tipo: accesibilidad',",
                "}",
                "args = sys.argv[1:]",
                "rendered = ' '.join(args)",
                "with open(os.environ['GH_STUB_LOG'], 'a', encoding='utf-8') as handle:",
                "    handle.write(rendered + '\\n')",
                "scenario = os.environ['GH_STUB_SCENARIO']",
                "url = next((item for item in args if item.startswith('repos/')), '')",
                "state_path = os.environ['GH_STUB_STATE']",
                "deleted = set()",
                "if os.path.exists(state_path):",
                "    with open(state_path, encoding='utf-8') as handle:",
                "        deleted = {line.strip() for line in handle if line.strip()}",
                "",
                "if '/issues?state=all&labels=' in url:",
                "    used = scenario == 'used'",
                "    if scenario.startswith('used:'):",
                "        selected = scenario.split(':', 1)[1]",
                "        used = f'labels={selected}&' in url",
                "    print(json.dumps([[{'number': 1}]] if used else [[]]))",
                "    raise SystemExit(0)",
                "",
                "if '--method' in args and 'DELETE' in args:",
                "    if '/labels/' in url:",
                "        label = url.rsplit('/labels/', 1)[1]",
                "        with open(state_path, 'a', encoding='utf-8') as handle:",
                "            handle.write(label + '\\n')",
                "    raise SystemExit(0)",
                "",
                "if '/labels/' in url:",
                "    label = url.rsplit('/labels/', 1)[1]",
                "    legacy = {quote(name, safe='') for name in aliases}",
                "    canonical = {quote(name, safe='') for name in aliases.values()}",
                "    if label in deleted:",
                "        print('gh: Not Found (HTTP 404)', file=sys.stderr)",
                "        raise SystemExit(1)",
                "    if scenario == 'aliases-absent' and label in legacy:",
                "        print('gh: Not Found (HTTP 404)', file=sys.stderr)",
                "        raise SystemExit(1)",
                "    if scenario == 'targets-missing' and label in canonical:",
                "        print('gh: Not Found (HTTP 404)', file=sys.stderr)",
                "        raise SystemExit(1)",
                "    print('{}')",
                "    raise SystemExit(0)",
                "",
                "print('unexpected gh invocation: ' + rendered, file=sys.stderr)",
                "raise SystemExit(9)",
            ]
            stub.write_text("\n".join(stub_lines) + "\n", encoding="utf-8")
            stub.chmod(0o755)
            env = os.environ.copy()
            env.update(
                {
                    "GH_BIN": str(stub),
                    "GH_STUB_LOG": str(log),
                    "GH_STUB_SCENARIO": scenario,
                    "GH_STUB_STATE": str(tmp_path / "gh.state"),
                    "REPOSITORIO": "pl0n3r/ControlBot",
                }
            )
            results = []
            call_batches = []
            for _ in range(repeats):
                if log.exists():
                    log.unlink()
                result = subprocess.run(
                    ["bash", str(SCRIPT)],
                    cwd=ROOT,
                    env=env,
                    text=True,
                    capture_output=True,
                )
                results.append(result)
                call_batches.append(log.read_text(encoding="utf-8") if log.exists() else "")
            if repeats == 1:
                return results[0], call_batches[0]
            return results, call_batches

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
            self.assertIn(f"issues?state=all&labels={encoded}&per_page=100", calls)
            self.assertIn(f"--method DELETE repos/pl0n3r/ControlBot/labels/{encoded}", calls)
        for canonical in ALIASES.values():
            encoded = quote(canonical, safe="")
            self.assertNotIn(f"--method DELETE repos/pl0n3r/ControlBot/labels/{encoded}", calls)

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

    def test_cleanup_supports_multiple_legacy_pairs(self):
        self.test_cleanup_covers_exact_factory_legacy_aliases()

    def test_legacy_cleanup_fails_closed_when_any_label_is_used(self):
        for legacy in ALIASES:
            with self.subTest(legacy=legacy):
                encoded = quote(legacy, safe="")
                result, calls = self.run_cleanup(f"used:{encoded}")
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("todavía tiene 1 uso(s)", result.stderr)
                self.assertNotIn(f"--method DELETE repos/pl0n3r/ControlBot/labels/{encoded}", calls)

    def test_legacy_cleanup_is_idempotent_for_absent_or_orphaned_labels(self):
        absent, absent_calls = self.run_cleanup("aliases-absent")
        results, call_batches = self.run_cleanup("unused", repeats=2)
        first, second = results
        first_calls, second_calls = call_batches
        self.assertEqual(absent.returncode, 0, absent.stderr)
        self.assertNotIn("--method DELETE", absent_calls)
        self.assertNotIn("/issues?state=all&labels=", absent_calls)
        self.assertEqual(first.returncode, 0, first.stderr)
        self.assertEqual(first_calls.count("--method DELETE"), len(ALIASES))
        self.assertEqual(second.returncode, 0, second.stderr)
        self.assertNotIn("--method DELETE", second_calls)
        self.assertNotIn("/issues?state=all&labels=", second_calls)
        self.assertIn("ausente; no-op", second.stdout)

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

    def test_candidate_check_runs_on_synchronize(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("types: [opened, edited, reopened, synchronize, ready_for_review, labeled, unlabeled]", workflow)
        candidate_at = workflow.index("  candidate:")
        cleanup_at = workflow.index("  limpiar_etiqueta_legacy:")
        candidate = workflow[candidate_at:cleanup_at]
        self.assertIn("name: Etiquetas", candidate)
        self.assertIn("if: github.event_name == 'pull_request'", candidate)
        self.assertIn("permissions:\n      contents: read\n", candidate)
        self.assertNotIn("issues: write", candidate)
        self.assertNotIn("pull-requests: write", candidate)
        self.assertIn("actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1", candidate)
        self.assertIn("tests.test_labels_workflow.LabelsWorkflowTests.test_cleanup_covers_exact_factory_legacy_aliases", candidate)

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
        self.assertIn("actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1", cleanup)

    def test_pr_validation_has_minimum_write_authority(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        start = workflow.index("  validar-pr:")
        end = workflow.index("  sweep:")
        block = workflow[start:end]
        self.assertIn("permissions:\n      contents: read\n      issues: write\n      pull-requests: write\n", block)
        self.assertNotIn("actions: write", block)
        self.assertNotIn("contents: write", block)
        self.assertNotIn("issues: read", block)

    def test_non_pr_reusable_jobs_remain_pull_request_read_only(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        starts = [
            (workflow.index("  sync:"), workflow.index("  validar-issue:")),
            (workflow.index("  validar-issue:"), workflow.index("  validar-pr:")),
            (workflow.index("  sweep:"), len(workflow)),
        ]
        for start, end in starts:
            block = workflow[start:end]
            self.assertIn("pull-requests: read", block)
            self.assertNotIn("pull-requests: write", block)

    def test_candidate_and_cleanup_keep_minimum_permissions(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        candidate = workflow[workflow.index("  candidate:"):workflow.index("  limpiar_etiqueta_legacy:")]
        cleanup = workflow[workflow.index("  limpiar_etiqueta_legacy:"):workflow.index("  sync:")]
        self.assertIn("permissions:\n      contents: read\n", candidate)
        self.assertNotIn("issues: write", candidate)
        self.assertNotIn("pull-requests:", candidate)
        self.assertIn("permissions:\n      contents: read\n      issues: write\n", cleanup)
        self.assertNotIn("pull-requests:", cleanup)

    def test_reusable_permission_distribution_is_exact(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertEqual(workflow.count("pull-requests: write"), 1)
        self.assertEqual(workflow.count("pull-requests: read"), 3)
        self.assertEqual(workflow.count("uses: pl0n3r/factory/.github/workflows/etiquetas.yml@v1"), 3)
        self.assertEqual(
            workflow.count(
                "uses: pl0n3r/factory/.github/workflows/etiquetas-pr.yml@a2a2350b8ce686fda5aa06f49cd0e9accaa9ed98"
            ),
            1,
        )
        self.assertNotIn("pull-requests: admin", workflow)
        self.assertNotIn("@main", workflow)

    def test_pr_validation_uses_exact_factory_split_sha(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        start = workflow.index("  validar-pr:")
        end = workflow.index("  sweep:")
        block = workflow[start:end]
        split = (
            "uses: pl0n3r/factory/.github/workflows/etiquetas-pr.yml@"
            "a2a2350b8ce686fda5aa06f49cd0e9accaa9ed98"
        )
        self.assertIn(split, block)
        self.assertIn("pull-requests: write", block)
        self.assertNotIn(
            "uses: pl0n3r/factory/.github/workflows/etiquetas.yml@v1",
            block,
        )
        self.assertEqual(workflow.count(split), 1)

    def test_non_pr_jobs_stay_on_factory_v1_read_only(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        general = "uses: pl0n3r/factory/.github/workflows/etiquetas.yml@v1"
        spans = (
            ("sync", "validar-issue"),
            ("validar-issue", "validar-pr"),
            ("sweep", None),
        )
        for name, next_name in spans:
            with self.subTest(job=name):
                start = workflow.index(f"  {name}:")
                end = workflow.index(f"  {next_name}:") if next_name else len(workflow)
                block = workflow[start:end]
                self.assertIn(general, block)
                self.assertIn("pull-requests: read", block)
                self.assertNotIn("pull-requests: write", block)
        self.assertEqual(workflow.count(general), 3)


if __name__ == "__main__":
    unittest.main()

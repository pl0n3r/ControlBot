"""Runbook offline del operador (#1361); no valida Hostinger ni datos reales."""
from __future__ import annotations

import json
import re
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "operator-runbook.md"
CLI = ROOT / "scripts" / "hostinger_go_live_plan.py"
SHA = "a" * 40
EXPECTED_STEPS = ("preflight", "arranque", "salud", "fallos", "mantenimiento")
SENSITIVE = (
    r"ghp_[A-Za-z0-9]{12,}",
    r"github_pat_[A-Za-z0-9_]{12,}",
    r"sk-[A-Za-z0-9]{12,}",
    r"-----BEGIN [A-Z ]*PRIVATE KEY-----",
    r"(?i)\b(?:password|passwd|api_key|auth_key)\s*[:=]\s*[^\s<>]{8,}",
    r"(?i)\bauthorization\s*:\s*(?:bearer|basic)\s+\S+",
    r"(?i)\b(?:cookie|set-cookie)\s*:\s*\S+",
    r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b",
    r"/home/u\d{6,}/",
)
CODE_BLOCKS = re.compile(r"(?m)^[ \t]*~~~sh[ \t]*\r?\n(.*?)^[ \t]*~~~[ \t]*(?:\r?\n|\Z)", re.S)
FILE_PATHS = re.compile(
    r"(?<![A-Za-z0-9_./-])((?:scripts|tests)/[a-zA-Z0-9_.-]+\.py)"
)
WORKFLOWS = re.compile(r"--workflow\s+([a-zA-Z0-9_.-]+\.yml)")


def inspect_document(text: str) -> list[str]:
    """Check command destinations without executing anything external."""
    failures: list[str] = []
    if not text.startswith("# ControlBot · Runbook del operador"):
        failures.append("documento incorrecto")
    for heading in ("## Arranque", "## Lectura de health", "## Fallos conocidos",
                    "## Mantenimiento semanal", "## Ensayo local reproducible"):
        if heading not in text:
            failures.append(f"sección ausente: {heading}")
    for step in EXPECTED_STEPS:
        if f"| {step} |" not in text:
            failures.append(f"registro ausente: {step}")
    if "NO ENSAYADO" not in text or "NO-GO" not in text:
        failures.append("falta límite de producción")
    if "VALIDATED_IN_PRODUCTION" not in text:
        failures.append("falta identidad de estado terminal")
    blocks = CODE_BLOCKS.findall(text)
    if len(blocks) < 5:
        failures.append("faltan comandos reproducibles")
    paths_found: set[str] = set()
    for block in blocks:
        if not block.strip():
            failures.append("bloque de comandos vacío")
        for line in block.splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if not (line.startswith("python3 ") or line.startswith("printf ")
                    or line.startswith("gh run list ")):
                failures.append(f"comando no permitido: {line[:45]}")
            if re.search(r"(?i)\b(?:rm|sudo|ssh|mysql|curl|wget|deploy)\s+-", line):
                failures.append("comando potencialmente destructivo")
        for path in FILE_PATHS.findall(block):
            paths_found.add(path)
            if not (ROOT / path).is_file():
                failures.append(f"comando apunta a archivo ausente: {path}")
        for workflow in WORKFLOWS.findall(block):
            path = ROOT / ".github" / "workflows" / workflow
            if not path.is_file():
                failures.append(f"workflow no existe: {workflow}")
    if not paths_found or "scripts/hostinger_go_live_plan.py" not in paths_found:
        failures.append("sin CLI local del repositorio")
    for pattern in SENSITIVE:
        if re.search(pattern, text):
            failures.append("contenido sensible detectado")
    if re.search(r"(?im)^\s*(?:estado|resultado productivo)\s*:\s*(?:ENSAYADO|GO|GREEN|VALIDATED_IN_PRODUCTION)\s*$", text):
        failures.append("declara estado live sin evidencia")
    return failures


def test_criterio_principal(self: unittest.TestCase) -> None:
    """AC-01: documentación y comandos del repo; simulación ejecutable NO_GO."""
    self.assertTrue(DOC.is_file())
    text = DOC.read_text(encoding="utf-8")
    self.assertEqual(inspect_document(text), [])
    payload = {
        "version": 1, "target": "control.condorapp.com.co",
        "sha": SHA, "phase": "construccion", "evidence": [],
    }
    proc = subprocess.run(
        [sys.executable, str(CLI)], cwd=ROOT,
        input=json.dumps(payload), capture_output=True,
        text=True, timeout=15, check=False,
    )
    self.assertEqual(proc.returncode, 1, proc.stderr)
    report = json.loads(proc.stdout)
    self.assertEqual(report["decision"], "NO_GO")
    self.assertEqual(len(report["missing_gates"]), 8)
    self.assertFalse(report["go_live_authorized"])
    self.assertFalse(report["deploy_authorized"])
    self.assertFalse(report["runtime_authorized"])


def test_rechaza_cambios_inseguros(self: unittest.TestCase) -> None:
    text = DOC.read_text(encoding="utf-8")
    for injection in (
        "\n~~~sh\npython3 scripts/inexistente.py\n~~~\n",
        "\n~~~sh\nsudo rm -rf /\n~~~\n",
        "\nghp_0123456789abcdef0123456789\n",
        "\nalice@example.com\n",
        "\nEstado: VALIDATED_IN_PRODUCTION\n",
    ):
        with self.subTest(injection=injection[:45]):
            self.assertNotEqual(inspect_document(text + injection), [])


# La clase pública conserva literalmente el identificador requerido por #1361.
# unittest descubre TestCase mediante atributos del módulo, sin exigir que el
# nombre generado sea un identificador válido en código Python.
globals()["Operator-runbookTests"] = type(
    "Operator-runbookTests",
    (unittest.TestCase,),
    {
        "test_criterio_principal": test_criterio_principal,
        "test_rechaza_cambios_inseguros": test_rechaza_cambios_inseguros,
    },
)

if __name__ == "__main__":
    unittest.main()

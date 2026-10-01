#!/usr/bin/env python3
"""Ejecuta la suite existente y emite cobertura Python XML + PHP Clover con Xdebug."""

from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
BUILD = ROOT / "build" / "coverage"
PHP_FRAGMENTS = BUILD / "php-fragments"
WRAPPER_DIR = BUILD / "bin"
PYTHON_XML = BUILD / "python.xml"
PHP_CLOVER = BUILD / "php-clover.xml"
PHP_BOOTSTRAP = ROOT / "scripts" / "php_coverage_bootstrap.php"


def run(command: list[str], *, env: dict[str, str] | None = None) -> None:
    subprocess.run(command, cwd=ROOT, env=env, check=True)


def coverage_environment() -> dict[str, str]:
    real_php = shutil.which("php")
    if real_php is None:
        raise RuntimeError("php no está disponible en PATH")

    BUILD.mkdir(parents=True, exist_ok=True)
    if PHP_FRAGMENTS.exists():
        shutil.rmtree(PHP_FRAGMENTS)
    PHP_FRAGMENTS.mkdir(parents=True)
    WRAPPER_DIR.mkdir(parents=True, exist_ok=True)

    wrapper = WRAPPER_DIR / "php"
    wrapper.write_text(
        "#!/usr/bin/env bash\n"
        "set -euo pipefail\n"
        "exec \"$CONTROLBOT_REAL_PHP\" "
        "-d xdebug.mode=coverage "
        "-d auto_prepend_file=\"$CONTROLBOT_PHP_COVERAGE_BOOTSTRAP\" \"$@\"\n",
        encoding="utf-8",
    )
    wrapper.chmod(0o755)

    env = os.environ.copy()
    env["PATH"] = str(WRAPPER_DIR) + os.pathsep + env.get("PATH", "")
    env["CONTROLBOT_REAL_PHP"] = real_php
    env["CONTROLBOT_PHP_COVERAGE_BOOTSTRAP"] = str(PHP_BOOTSTRAP)
    env["CONTROLBOT_PHP_COVERAGE_DIR"] = str(PHP_FRAGMENTS)
    env["CONTROLBOT_REPO_ROOT"] = str(ROOT)
    env["XDEBUG_MODE"] = "coverage"
    return env


def aggregate_php() -> dict[Path, dict[int, int]]:
    fragments = sorted(PHP_FRAGMENTS.glob("*.json"))
    if not fragments:
        raise RuntimeError(
            "No se generaron fragmentos de cobertura PHP; verifica Xdebug y el wrapper de php."
        )

    aggregate: dict[Path, dict[int, int]] = {}
    for fragment in fragments:
        payload = json.loads(fragment.read_text(encoding="utf-8"))
        if not isinstance(payload, dict):
            raise RuntimeError(f"Fragmento PHP inválido: {fragment}")

        for raw_path, raw_lines in payload.items():
            if not isinstance(raw_path, str) or not isinstance(raw_lines, dict):
                continue
            path = Path(raw_path).resolve()
            try:
                relative = path.relative_to(ROOT)
            except ValueError:
                continue
            if path.suffix.lower() != ".php":
                continue
            if relative.parts and relative.parts[0] in {"tests", "vendor", "build"}:
                continue

            lines = aggregate.setdefault(path, {})
            for raw_line, raw_state in raw_lines.items():
                try:
                    line = int(raw_line)
                    state = int(raw_state)
                except (TypeError, ValueError):
                    continue
                current = lines.get(line)
                if current is None or state > current:
                    lines[line] = state

    aggregate = {path: lines for path, lines in aggregate.items() if lines}
    if not aggregate:
        raise RuntimeError("La cobertura PHP no contiene archivos fuente del repositorio.")
    return aggregate


def metrics_attributes(statements: int, covered: int) -> dict[str, str]:
    return {
        "loc": str(statements),
        "ncloc": str(statements),
        "classes": "0",
        "methods": "0",
        "coveredmethods": "0",
        "conditionals": "0",
        "coveredconditionals": "0",
        "statements": str(statements),
        "coveredstatements": str(covered),
        "elements": str(statements),
        "coveredelements": str(covered),
    }


def write_php_clover(coverage: dict[Path, dict[int, int]]) -> None:
    timestamp = str(int(time.time()))
    root = ET.Element("coverage", {"generated": timestamp})
    project = ET.SubElement(
        root,
        "project",
        {"timestamp": timestamp, "name": "ControlBot"},
    )
    package = ET.SubElement(project, "package", {"name": "ControlBot"})

    total = 0
    covered = 0
    for path in sorted(coverage):
        line_states = coverage[path]
        executable = sorted(
            (line, state) for line, state in line_states.items() if state != 0
        )
        if not executable:
            continue
        relative_name = str(path.relative_to(ROOT))
        source_path = (ROOT / relative_name).resolve()
        file_node = ET.SubElement(package, "file", {"name": str(source_path)})
        file_covered = 0
        for line, state in executable:
            count = 1 if state > 0 else 0
            file_covered += count
            ET.SubElement(
                file_node,
                "line",
                {"num": str(line), "type": "stmt", "count": str(count)},
            )
        ET.SubElement(
            file_node,
            "metrics",
            metrics_attributes(len(executable), file_covered),
        )
        total += len(executable)
        covered += file_covered

    if total == 0:
        raise RuntimeError("Clover PHP quedó sin statements ejecutables.")

    ET.SubElement(package, "metrics", metrics_attributes(total, covered))
    ET.SubElement(project, "metrics", metrics_attributes(total, covered))
    ET.indent(root, space="  ")
    PHP_CLOVER.write_text(
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        + ET.tostring(root, encoding="unicode")
        + "\n",
        encoding="utf-8",
    )


def main() -> int:
    BUILD.mkdir(parents=True, exist_ok=True)
    run([sys.executable, "-m", "coverage", "--version"])
    run([sys.executable, "-m", "coverage", "erase"])

    env = coverage_environment()
    run(
        [
            sys.executable,
            "-m",
            "coverage",
            "run",
            "--branch",
            "-m",
            "unittest",
            "discover",
            "-s",
            "tests",
            "-p",
            "test_*.py",
        ],
        env=env,
    )
    run([sys.executable, "-m", "coverage", "xml", "-o", str(PYTHON_XML)])
    write_php_clover(aggregate_php())

    if not PYTHON_XML.is_file() or not PHP_CLOVER.is_file():
        raise RuntimeError("Faltan reportes de cobertura esperados.")
    print(f"Python coverage: {PYTHON_XML.relative_to(ROOT)}")
    print(f"PHP coverage: {PHP_CLOVER.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

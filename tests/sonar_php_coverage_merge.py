#!/usr/bin/env python3
from __future__ import annotations

import json
import sys
import time
import xml.etree.ElementTree as ET
from pathlib import Path


def fail(message: str) -> None:
    raise SystemExit(message)


def merge_fragments(fragment_dir: Path, output: Path) -> None:
    root = Path.cwd().resolve()
    src = (root / "src").resolve()
    merged: dict[Path, dict[int, int]] = {}

    fragments = sorted(fragment_dir.glob("coverage-*.json"))
    if not fragments:
        fail("No se generaron fragmentos de cobertura PHP.")

    for fragment in fragments:
        raw = json.loads(fragment.read_text(encoding="utf-8"))
        if not isinstance(raw, dict):
            fail(f"Fragmento inválido: {fragment}")

        for filename, lines in raw.items():
            path = Path(filename).resolve()
            try:
                path.relative_to(src)
            except ValueError:
                continue

            if not isinstance(lines, dict):
                fail(f"Líneas inválidas en {fragment}: {filename}")

            target = merged.setdefault(path, {})
            for line, status in lines.items():
                number = int(line)
                value = int(status)
                previous = target.get(number)
                if previous is None:
                    target[number] = value
                elif previous > 0 or value > 0:
                    target[number] = 1
                else:
                    target[number] = min(previous, value)

    statements = 0
    covered = 0
    now = str(int(time.time()))
    coverage = ET.Element("coverage", generated=now)
    project = ET.SubElement(coverage, "project", timestamp=now)

    for path in sorted(merged):
        executable = {line: status for line, status in merged[path].items() if line > 0}
        if not executable:
            continue

        file_node = ET.SubElement(project, "file", name=str(path.relative_to(root)))
        file_statements = 0
        file_covered = 0

        for line, status in sorted(executable.items()):
            count = 1 if status > 0 else 0
            ET.SubElement(file_node, "line", num=str(line), type="stmt", count=str(count))
            file_statements += 1
            file_covered += count

        ET.SubElement(
            file_node,
            "metrics",
            statements=str(file_statements),
            coveredstatements=str(file_covered),
            conditionals="0",
            coveredconditionals="0",
            methods="0",
            coveredmethods="0",
            elements=str(file_statements),
            coveredelements=str(file_covered),
        )
        statements += file_statements
        covered += file_covered

    if statements == 0:
        fail("La cobertura PHP no contiene líneas ejecutables de src/.")

    ET.SubElement(
        project,
        "metrics",
        statements=str(statements),
        coveredstatements=str(covered),
        conditionals="0",
        coveredconditionals="0",
        methods="0",
        coveredmethods="0",
        elements=str(statements),
        coveredelements=str(covered),
    )
    ET.ElementTree(coverage).write(output, encoding="utf-8", xml_declaration=True)


if __name__ == "__main__":
    if len(sys.argv) != 3:
        fail("Uso: sonar_php_coverage_merge.py <fragment_dir> <output.xml>")
    merge_fragments(Path(sys.argv[1]), Path(sys.argv[2]))

#!/usr/bin/env python3
"""Genera y comprueba el paquete jurídico sin alterar fuentes legales."""
from __future__ import annotations

import argparse
import hashlib
from datetime import date, datetime, timezone
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
SOURCES = (
    "docs/privacidad/aviso-privacidad.md",
    "docs/privacidad/canal-derechos.md",
    "docs/privacidad/politica-tratamiento.md",
    "docs/privacidad/registro-tratamientos.md",
    "docs/privacidad/retencion.md",
    "docs/privacidad/terminos-condiciones.md",
)
DESCRIPTIONS = (
    "Revisar información inicial, consentimiento y responsable",
    "Revisar derechos, procedimiento y plazos",
    "Revisar bases, finalidades y consentimiento",
    "Revisar categorías y operaciones de tratamiento",
    "Revisar períodos y reglas de conservación",
    "Revisar condiciones contractuales, garantías y jurisdicción",
)
HEADER = (
    "# Paquete completo para revisión jurídica — ControlBot\n\n"
    "Estado: documented_not_legally_approved\n\n"
)
STAMP_RE = re.compile(
    r"^Última actualización automática \(UTC\): (\d{4}-\d{2}-\d{2}) "
    r"\| SHA de origen: ([0-9a-f]{40})$"
)


def stamp_from_existing(root: Path) -> tuple[str, str]:
    first_line = (root / "revision-juridica/00-LEEME.md").read_text(
        encoding="utf-8"
    ).splitlines()[0]
    match = STAMP_RE.fullmatch(first_line)
    if match is None:
        raise ValueError("Cabecera de fecha y SHA no canónica")
    date.fromisoformat(match.group(1))
    return match.group(1), match.group(2)


def source_sha256(content: bytes) -> str:
    """Huella SHA-256 de contenido para comprobación de integridad."""
    return hashlib.sha256(content).hexdigest()


def expected_files(root: Path, stamp: str, source_sha: str) -> dict[str, bytes]:
    date.fromisoformat(stamp)
    if re.fullmatch(r"[0-9a-f]{40}", source_sha) is None:
        raise ValueError("SHA de origen inválido")
    config = json.loads((root / "config/version.json").read_text("utf-8"))
    data = json.loads((root / "datos.yml").read_text("utf-8"))
    version = config["version"]
    if not isinstance(version, str) or not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise ValueError("Versión no verificable")
    treatments = data["treatments"]
    if not isinstance(treatments, list) or not treatments:
        raise ValueError("Inventario de tratamientos ausente")
    providers = set()
    for treatment in treatments:
        if not isinstance(treatment, dict) or not isinstance(treatment.get("providers"), list):
            raise ValueError("Proveedores no verificables")
        for provider in treatment["providers"]:
            if not isinstance(provider, str) or not provider.strip():
                raise ValueError("Proveedor inválido")
            providers.add(provider.strip())

    lines = [
        f"Última actualización automática (UTC): {stamp} | SHA de origen: {source_sha}",
        "",
        "# Paquete para revisión jurídica — ControlBot",
        "",
        "Estado: documented_not_legally_approved",
        f"Versión del repositorio: {version}",
        f"SHA de origen de los documentos: {source_sha}",
        "",
        "Huellas SHA-256 de las fuentes incluidas:",
        *(f"- {source}: {source_sha256((root / source).read_bytes())}" for source in SOURCES),
        "",
        "Este directorio agrupa borradores técnicos para entregar al abogado. No supone aprobación jurídica, consentimiento, publicación ni entrada en producción.",
        "",
        "## Hechos del responsable pendientes de confirmar",
        "",
        "- [ ] Razón social o nombre del responsable: [COMPLETAR POR EL DUEÑO]",
        "- [ ] Identificación del responsable: [COMPLETAR POR EL DUEÑO]",
        "- [ ] Dirección: [COMPLETAR POR EL DUEÑO]",
        "- [ ] Contacto/canal para ejercer derechos: [COMPLETAR POR EL DUEÑO]",
        "- [ ] Plazos, jurisdicción y bases jurídicas aplicables: [REVISAR CON ABOGADO]",
        "",
        "## Proveedores y subprocesadores",
        "",
    ]
    if providers:
        lines.extend(f"- Declarado en datos.yml: {p}" for p in sorted(providers))
    else:
        lines.append(
            "- Ningún proveedor o subprocesador está declarado por tratamiento en datos.yml (todos los campos providers están vacíos). La ausencia de declaración no acredita que no existan terceros."
        )
    lines.extend([
        "- Infraestructura mencionada en el repositorio: GitHub/GitHub Actions y Hostinger (hosting previsto); Sentry aparece como integración sujeta a configuración. El abogado debe confirmar uso efectivo, contratos y transferencias; no se presumen tratamientos activos.",
        "",
        "## Archivos para entregar y revisión requerida",
        "",
    ])
    for source, description in zip(SOURCES, DESCRIPTIONS):
        lines.append(
            f"- {Path(source).name} — copia exacta de {source}. {description}."
        )
    lines.extend([
        "- paquete-completo.md — los seis documentos íntegros concatenados y separados por encabezados para entregar un único archivo.",
        "",
        "## Instrucción de revisión",
        "",
        "Confirmar o corregir cada borrador y sus hechos pendientes. No publicar ni declarar approved o legally_approved sin revisión y decisión del responsable.",
        "",
    ])
    result = {"revision-juridica/00-LEEME.md": "\n".join(lines).encode("utf-8")}
    package = bytearray(HEADER.encode("utf-8"))
    for source in SOURCES:
        content = (root / source).read_bytes()
        result["revision-juridica/" + Path(source).name] = content
        package.extend(f"## Documento: {source}\n\n".encode("utf-8"))
        package.extend(content)
        if not content.endswith(b"\n"):
            package.extend(b"\n")
        package.extend(b"\n---\n\n")
    result["revision-juridica/paquete-completo.md"] = bytes(package)
    return result


def check_bundle(root: Path) -> list[str]:
    try:
        stamp, source_sha = stamp_from_existing(root)
        wanted = expected_files(root, stamp, source_sha)
    except (OSError, ValueError, KeyError, TypeError, IndexError) as exc:
        return [f"Paquete o fuentes inválidos: {exc}"]
    stale = []
    for path, content in wanted.items():
        try:
            actual = (root / path).read_bytes()
        except OSError:
            stale.append(path + ": falta")
            continue
        if actual != content:
            stale.append(path + ": desactualizado")
    return stale


def generate(root: Path) -> None:
    # La identidad de HEAD sólo certifica fuentes confirmadas, nunca borradores.
    clean = subprocess.run(
        ["git", "diff", "--quiet", "HEAD", "--", *SOURCES],
        cwd=root, capture_output=True, check=False,
    )
    if clean.returncode != 0:
        raise ValueError("Fuentes modificadas sin confirmar: no generar procedencia falsa")
    sha = subprocess.check_output(
        ["git", "rev-parse", "HEAD"], cwd=root, text=True
    ).strip().lower()
    stamp = datetime.now(timezone.utc).date().isoformat()
    for relative, content in expected_files(root, stamp, sha).items():
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="solo lectura")
    args = parser.parse_args()
    if args.check:
        stale = check_bundle(ROOT)
        for finding in stale:
            print(f"ERROR: {finding}", file=sys.stderr)
        return 1 if stale else 0
    generate(ROOT)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

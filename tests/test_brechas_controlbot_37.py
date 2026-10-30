"""ControlBot #1044: CB-0549..CB-0563, pruebas offline sin certificar Hostinger.

Una comprobación estática es CONTRATO_LOCAL, nunca evidencia de HTTP real,
LiteSpeed, servidor autenticado, backup D-059 o despliegue.
"""
from __future__ import annotations

from test_matriz_cobertura import credible_offline_coverage_row

import ast
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{i:04d}" for i in range(549, 564))
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
FILES = (
    "docs/matriz-cobertura.md",
    "docs/matriz-cobertura-parte-02.md",
    "docs/hostinger-root-publication.md",
    "docs/hostinger-ssh-deploy-binding.md",
    "index.php", ".htaccess", "public/index.php",
    ".github/workflows/deploy.yml",
)
ROOT_DOC = "docs/hostinger-root-publication.md"
SSH_DOC = "docs/hostinger-ssh-deploy-binding.md"
MATRIX = FILES[:2]
LIVE_ONLY = frozenset((
    "CB-0552", "CB-0553", "CB-0554", "CB-0555",
    "CB-0556", "CB-0559", "CB-0563",
))


def sources() -> dict[str, str]:
    """Read only committed files: no HTTP, no host keys, no environment secrets."""
    return {name: (ROOT / name).read_text(encoding="utf-8") for name in FILES}


def row_for(clause: str, data: dict[str, str]) -> tuple[str, ...] | None:
    """Require exactly one complete, unpromoted canonical row across partitions."""
    if clause not in IDS or any(not data.get(name) for name in MATRIX):
        return None
    found = []
    for path in MATRIX:
        for line in data[path].splitlines():
            if not line.lstrip().startswith("|"):
                continue
            cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
            if cells and cells[0] == clause:
                found.append(cells)
    if len(found) != 1 or len(found[0]) < 6:
        return None
    row = found[0]
    if not credible_offline_coverage_row(row):
        return None
    if not row[1].startswith("[docs/hostinger-"):
        return None
    return row


def classify(clause: str, data: dict[str, str]) -> str:
    """Never convert an offline contract into live or operational readiness."""
    if (clause not in IDS or any(not data.get(name) for name in FILES)
            or row_for(clause, data) is None):
        return UNKNOWN
    root, ssh = data[ROOT_DOC], data[SSH_DOC]
    index, access = data["index.php"], data[".htaccess"]
    public, deploy = data["public/index.php"], data[".github/workflows/deploy.yml"]
    if clause in LIVE_ONLY:
        return UNKNOWN
    checks = {
        "CB-0549": all(x in index for x in (
            "realpath(__DIR__ . '/public/index.php')", "is_file($publicEntrypoint)",
            "dirname($publicEntrypoint) !== $expectedPublicDir",
            "http_response_code(503)", "require $publicEntrypoint",
        )) and "no carga código alternativo" in root,
        "CB-0550": all(x in access for x in (
            "Options -Indexes", "DirectoryIndex index.php",
            "RewriteRule ^(?:src|config|docs|scripts|tests|vendor",
            "RewriteRule ^(?:\\.git|\\.github)",
            "Require all denied",
        )) and "Require all granted" not in access,
        "CB-0551": (
            "SetEnv CONTROLBOT_OWNER_LOGIN USUARIO" in access
            and "AuthType Basic" in access
            and "Require valid-user" in access
            and bool(re.search(r"(?m)^AuthUserFile /[^\s]+$", access))
            and not any(
                "public_html" in line or "password" in line.lower()
                for line in access.splitlines() if line.startswith("AuthUserFile ")
            )
            and "contraseña y su hash permanecen únicamente en el servidor" in root
        ),
        "CB-0557": (
            "Registrar en ControlBot #625 únicamente códigos HTTP, SHA y URL" in root
            and "nunca usuario+contraseña" in root
        ),
        "CB-0558": (
            "revertir el commit de `.htaccess`" in root
            and "http_response_code(503)" in index
            and "REMOTE_USER" in root
        ),
        "CB-0560": (
            "pl0n3r/factory/.github/workflows/deploy.yml@v1" in deploy
            and "transport: ${{ vars.DEPLOY_TRANSPORT || 'none' }}" in deploy
            and "binding de configuración" in ssh
        ),
        "CB-0561": (
            "on:\n  workflow_dispatch:" in deploy
            and "\n  push:" not in deploy
            and "if: vars.DEPLOY_ENABLED == 'true'" in deploy
            and "**NO despliega**" in ssh
        ),
        "CB-0562": (
            "transport: ${{ vars.DEPLOY_TRANSPORT || 'none' }}" in deploy
            and "fail-closed es `none`" in ssh
            and "transport: hostinger-ssh" not in deploy
        ),
    }
    return LOCAL if checks.get(clause, False) else UNKNOWN


def simulated_bootstrap(mode: str) -> tuple[int, str, str]:
    """Run isolated PHP CLI with synthetic root, no HTTP or owner credentials."""
    if mode not in ("missing", "inside", "outside"):
        raise ValueError("UNKNOWN: invalid fixture")
    with tempfile.TemporaryDirectory(prefix="cb1044-offline-") as temporary:
        directory = Path(temporary)
        shutil.copyfile(ROOT / "index.php", directory / "index.php")
        if mode != "missing":
            public = directory / "public"
            public.mkdir()
            if mode == "inside":
                (public / "index.php").write_text(
                    '<?php echo "synthetic-public-only\\n";', encoding="utf-8")
            else:
                outside = directory / "outside.php"
                outside.write_text('<?php echo "outside-invalid\\n";',
                                   encoding="utf-8")
                (public / "index.php").symlink_to(outside)
        result = subprocess.run(
            ["php", str(directory / "index.php")], cwd=directory,
            text=True, capture_output=True, timeout=10, check=False,
        )
        return result.returncode, result.stdout, result.stderr


class BrechasControlbot37Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 15 IDs y predicados locales o UNKNOWN explícito."""
        data = sources()
        self.assertEqual(len(IDS), 15)
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertIsNotNone(row_for(clause, data))
                expected = UNKNOWN if clause in LIVE_ONLY else LOCAL
                self.assertEqual(classify(clause, data), expected)
        self.assertEqual(simulated_bootstrap("missing"), (
            0, "Service unavailable.\n", ""))
        self.assertEqual(simulated_bootstrap("inside"), (
            0, "synthetic-public-only\n", ""))
        self.assertEqual(simulated_bootstrap("outside"), (
            0, "Service unavailable.\n", ""))
        # CB-0552: la documentación pide REMOTE_USER, pero el entrypoint
        # actual exige OwnerSession; nunca anunciar compatibilidad LiteSpeed.
        self.assertIn("REMOTE_USER", data[ROOT_DOC])
        self.assertIn("OwnerSessionService", data["public/index.php"])
        self.assertIn("REMOTE_USER is never an authority", data["public/index.php"])
        self.assertEqual(classify("CB-0552", data), UNKNOWN)
        # CB-0553..56: el HTTP de LiteSpeed real no se simula como 200/403.
        for clause, fragment in (
            ("CB-0553", "LiteSpeed entrega `REMOTE_USER`"),
            ("CB-0554", "`REDIRECT_REMOTE_USER`"),
            ("CB-0555", "raíz autenticada: `200`"),
            ("CB-0556", "`/src/` y `/config/`: `403`"),
            ("CB-0559", "No se prueba Hostinger real"),
            ("CB-0563", "Solo se aceptará `hostinger-ssh`"),
        ):
            with self.subTest(boundary=clause):
                document = data[SSH_DOC] if clause == "CB-0563" else data[ROOT_DOC]
                self.assertIn(fragment, document)
                self.assertEqual(classify(clause, data), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: evidencia ausente, duplicada y relajaciones retornan UNKNOWN."""
        data = sources()
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(classify(clause, {}), UNKNOWN)
                rows = [line for path in MATRIX for line in data[path].splitlines()
                        if line.startswith("| " + clause + " |")]
                self.assertEqual(len(rows), 1)
                owner = next(path for path in MATRIX if rows[0] in data[path])
                duplicated = dict(data)
                duplicated[owner] += "\n" + rows[0]
                self.assertEqual(classify(clause, duplicated), UNKNOWN)
                promoted = dict(data)
                promoted[owner] = promoted[owner].replace(
                    rows[0], (rows[0].replace("MERGED", "PENDING", 1)
                             if "CUBIERTO" in rows[0] else
                             rows[0].replace("NO_VERIFICABLE", "CUBIERTO", 1)), 1)
                self.assertEqual(classify(clause, promoted), UNKNOWN)
        edits = (
            ("index.php", "http_response_code(503)", "http_response_code(200)", "CB-0549"),
            (".htaccess", "Options -Indexes", "Options +Indexes", "CB-0550"),
            (".htaccess", "Require valid-user", "Require all granted", "CB-0551"),
            (ROOT_DOC, "Registrar en ControlBot #625 únicamente códigos HTTP, SHA y URL",
             "Registrar secretos del propietario", "CB-0557"),
            (ROOT_DOC, "revertir el commit de `.htaccess`",
             "dejar deploy activo", "CB-0558"),
            (".github/workflows/deploy.yml",
             "transport: ${{ vars.DEPLOY_TRANSPORT || 'none' }}",
             "transport: hostinger-ssh", "CB-0560"),
            (".github/workflows/deploy.yml", "on:\n  workflow_dispatch:",
             "on:\n  push:", "CB-0561"),
            (SSH_DOC, "fail-closed es `none`", "fail-open es ssh",
             "CB-0562"),
        )
        for path, existing, replacement, clause in edits:
            with self.subTest(path=path, clause=clause):
                self.assertIn(existing, data[path])
                candidate = dict(data)
                candidate[path] = data[path].replace(existing, replacement, 1)
                self.assertEqual(classify(clause, candidate), UNKNOWN)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            simulated_bootstrap("real-hostinger")

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures aislados sin acceso de red, credenciales ni PII."""
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {
            "socket", "urllib", "http", "requests", "httpx", "paramiko", "mysql",
        })
        self.assertEqual(set(FILES), {
            "docs/matriz-cobertura.md", "docs/matriz-cobertura-parte-02.md",
            ROOT_DOC, SSH_DOC, "index.php", ".htaccess", "public/index.php",
            ".github/workflows/deploy.yml",
        })
        for mode in ("missing", "inside", "outside"):
            _, output, error = simulated_bootstrap(mode)
            self.assertNotIn("password", (output + error).lower())
            self.assertNotIn("Authorization", output + error)
        self.assertIn("credenciales", sources()[ROOT_DOC].lower())

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: NO_VERIFICABLE por fila; no confundir CI con go-live."""
        data = sources()
        for clause in IDS:
            with self.subTest(clause=clause):
                row = row_for(clause, data)
                self.assertIsNotNone(row)
                self.assertTrue(credible_offline_coverage_row(row))
                for status in ("offline=unknown", "live=unknown", "operando=unknown"):
                    self.assertIn(status, row[4].lower())
                self.assertNotEqual(classify(clause, data), "LIVE")
        for clause in LIVE_ONLY:
            self.assertEqual(classify(clause, data), UNKNOWN)
        self.assertIn("no se declara el sistema live", data[ROOT_DOC].lower())
        self.assertIn("no habilita despliegues", data[SSH_DOC].lower())
        self.assertIn("vars.DEPLOY_ENABLED == 'true'",
                      data[".github/workflows/deploy.yml"])


if __name__ == "__main__":
    unittest.main()

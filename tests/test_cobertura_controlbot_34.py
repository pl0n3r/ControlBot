"""#1217: ejerce PHP de ControlBot con datos sintéticos, sin Hostinger real.

La disponibilidad de Apache/LiteSpeed, Basic Auth externo, HTTP productivo y
rollback después de deploy no se infiere de estas pruebas.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

from tests.test_authenticated_router import PHP_SCENARIO

ROOT = Path(__file__).resolve().parents[1]
INDEX = ROOT / "index.php"
HTACCESS = ROOT / ".htaccess"
PUBLIC = ROOT / "public/index.php"
SCENARIO = ROOT / "tests/factory_orchestrator_web_entrypoint_scenarios.php"


def run_php(*args: str, cwd: Path = ROOT) -> str:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("PHP CLI necesario para probar comportamiento real")
    run = subprocess.run([binary, *args], cwd=cwd, text=True,
                         capture_output=True, timeout=35, check=False)
    if run.returncode:
        raise AssertionError(f"PHP falló: {run.stderr[-1200:]}")
    if run.stderr:
        raise AssertionError(f"PHP stderr: {run.stderr[-1200:]}")
    return run.stdout


def scenario(name: str) -> dict:
    response = json.loads(run_php(str(SCENARIO), name))
    if not isinstance(response, dict):
        raise AssertionError("Contrato PHP inválido")
    return response


def session_flow() -> dict:
    """Ejecuta OAuth+TOTP fake del router real, no headers de cliente."""
    return json.loads(run_php("-r", PHP_SCENARIO))


def bootstrap(mode: str) -> str:
    """Ejecuta index.php real en una raíz privada con contenido ficticio."""
    with tempfile.TemporaryDirectory(prefix="cb-1217-") as directory:
        root = Path(directory)
        shutil.copyfile(INDEX, root / "index.php")
        public = root / "public"
        public.mkdir()
        entry = public / "index.php"
        if mode == "valid":
            entry.write_text('<?php echo "fixture-entrypoint\n";', encoding="utf-8")
        elif mode == "directory":
            entry.mkdir()
        elif mode == "escape":
            outside = root / "not-public.php"
            outside.write_text('<?php echo "LEAK-NEVER\n";', encoding="utf-8")
            entry.symlink_to(outside)
        elif mode != "missing":
            raise ValueError("Modo de fixture inválido")
        return run_php(str(root / "index.php"), cwd=root)


class CoberturaControlbot34Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: subcontratos offline CB-0549/0552/0555 ejecutan PHP real."""
        # CB-0549: bootstrap real sirve únicamente el entrypoint de public/.
        self.assertEqual(bootstrap("valid"), "fixture-entrypoint\n")

        # CB-0552: el controlador legado exige dos señales del servidor;
        # el front controller vigente además exige sesión OAuth+TOTP auténtica.
        allowed = scenario("owner")
        self.assertEqual(allowed["status"], 200)
        self.assertIn("Orquestador en vivo", allowed["body"])
        auth = scenario("auth")
        self.assertEqual(auth["missing"]["status"], 503)
        self.assertEqual(auth["wrong"]["status"], 403)
        self.assertEqual(auth["config"]["status"], 503)

        # CB-0555: aplicación PHP devuelve vista/JSON sólo para owner
        # incluso con snapshot UNKNOWN. No demuestra un 200 de Hostinger.
        for name, response in scenario("unknown").items():
            with self.subTest(snapshot=name):
                self.assertEqual(response["status"], 200)
                payload = json.loads(response["body"])
                self.assertTrue(payload["read_only"])
                self.assertEqual(payload["snapshot"]["central"]["activity_state"], "UNKNOWN")
                self.assertEqual(payload["snapshot"]["fronts"], [])

        modern = session_flow()
        self.assertEqual(modern["valid"]["status"], 200)
        self.assertEqual(modern["untrusted_header"]["status"], 200)
        for path, result in modern["routes"].items():
            with self.subTest(route=path):
                self.assertEqual(result["allowed"], 200)
                self.assertIn(result["denied"], (401, 403))
                self.assertEqual(result["denied_cache"], "no-store")

    def test_falla_cerrado(self) -> None:
        """AC-02: ausencia, carpeta, symlink fuera de public y auth falsa."""
        for mode in ("missing", "directory", "escape"):
            with self.subTest(root=mode):
                result = bootstrap(mode)
                self.assertEqual(result, "Service unavailable.\n")
                self.assertNotIn("LEAK-NEVER", result)
        legacy = scenario("auth")
        self.assertEqual([legacy[key]["status"] for key in ("missing", "wrong", "config")],
                         [503, 403, 503])
        for response in legacy.values():
            self.assertNotIn("reserved", response["body"].lower())
        modern = session_flow()
        for key in ("missing", "not_otp", "expired", "forged"):
            with self.subTest(session=key):
                self.assertIn(modern[key]["status"], (401, 403))
                self.assertEqual(modern[key]["headers"]["Cache-Control"], "no-store")
        self.assertNotIn("gho_fixture_only", modern["forged"]["body"])
        # El header HTTP del cliente no es autenticación; el runtime vigente
        # solo permite al owner tras verificar la sesión/TOTP server-side.
        self.assertEqual(modern["untrusted_header"]["status"], 200)
        self.assertIn("handleAuthenticated(", PUBLIC.read_text(encoding="utf-8"))
        self.assertNotIn("ControlBotWebEntrypoint::handle(", PUBLIC.read_text(encoding="utf-8"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: fase construcción, privacidad y reglas públicas sin credenciales."""
        datos = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(datos["phase"], "construccion")
        self.assertTrue(datos["d063_attestation"]["nothing_live"])
        self.assertTrue(datos["d063_attestation"]["no_real_customer_data"])
        htaccess = HTACCESS.read_text(encoding="utf-8")
        self.assertIn("Options -Indexes", htaccess)
        self.assertIn("AuthType Basic", htaccess)
        self.assertIn("Require valid-user", htaccess)
        self.assertIn("Require all denied", htaccess)
        self.assertIn("SetEnv CONTROLBOT_OWNER_LOGIN", htaccess)
        self.assertRegex(htaccess, r"RewriteRule\s+\^\(\?:src\|config\|docs\|")
        matched = re.search(r"(?m)^AuthUserFile\s+(\S+)\s*$", htaccess)
        self.assertIsNotNone(matched)
        self.assertTrue(matched.group(1).startswith("/home/"))
        self.assertNotIn("public_html", matched.group(1))
        for pattern in (r"\$apr1\$", r"\$2[aby]\$", r"\$argon2",
                        r"(?i)authorization:\s*basic\s+\S+"):
            self.assertIsNone(re.search(pattern, htaccess))
        self.assertNotIn("X-Remote-User", htaccess)
        self.assertEqual(bootstrap("valid"), "fixture-entrypoint\n")


if __name__ == "__main__":
    unittest.main()

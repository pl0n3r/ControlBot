import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class OwnerAccessHtaccessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.htaccess = (ROOT / ".htaccess").read_text(encoding="utf-8")
        cls.runbook = (ROOT / "docs/hostinger-root-publication.md").read_text(encoding="utf-8")
        cls.public_index = (ROOT / "public/index.php").read_text(encoding="utf-8")
        cls.entrypoint = (ROOT / "src/FactoryOrchestratorWebEntrypoint.php").read_text(encoding="utf-8")

    def test_basic_auth_points_outside_public_html_and_contains_no_secret(self) -> None:
        match = re.search(r"(?m)^AuthUserFile\s+(\S+)\s*$", self.htaccess)
        self.assertIsNotNone(match)
        auth_file = match.group(1)
        self.assertEqual("/home/u151692719/.htpasswds/controlbot", auth_file)
        self.assertTrue(auth_file.startswith("/home/"))
        self.assertNotIn("public_html", auth_file)

        combined = self.htaccess + "\n" + self.runbook
        for secret_pattern in (
            r"\$apr1\$",
            r"\$2[aby]\$",
            r"\$argon2",
            r"(?i)authorization:\s*basic\s+[A-Za-z0-9+/=]{12,}",
            r"(?m)^USUARIO:\$",
        ):
            self.assertIsNone(re.search(secret_pattern, combined))

        self.assertIn("curl -u USUARIO", self.runbook)
        self.assertIn("solicita la contraseña de forma interactiva", self.runbook)
        self.assertIn("`403` tanto sin credenciales como con credenciales", self.runbook)

    def test_existing_deny_rules_remain_and_site_is_fail_closed_without_auth_module(self) -> None:
        for expected in (
            "Options -Indexes",
            "RewriteRule ^(?:src|config|docs|scripts|tests|vendor|lecciones|openapi|readme)(?:/|$) - [F,L,NC]",
            "RewriteRule ^(?:\\.git|\\.github)(?:/|$) - [F,L,NC]",
            "Require all denied",
        ):
            self.assertIn(expected, self.htaccess)

        auth_block = re.search(
            r"<IfModule mod_auth_basic\.c>.*?AuthType Basic.*?AuthName \"ControlBot\".*?"
            r"AuthUserFile /home/u151692719/\.htpasswds/controlbot.*?Require valid-user.*?</IfModule>",
            self.htaccess,
            re.DOTALL,
        )
        self.assertIsNotNone(auth_block)

        php = r"""
require 'src/FactoryOrchestratorWebEntrypoint.php';
$response = \ControlBot\Business\FactoryOrchestratorWebEntrypoint::handle(
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
    ['CONTROLBOT_OWNER_LOGIN' => 'USUARIO'],
    1,
    '/definitely/missing-controlbot-snapshot.json',
    sys_get_temp_dir() . '/controlbot-owner-access-test-cache.json'
);
echo $response['status'];
"""
        result = subprocess.run(
            ["php", "-r", php],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
            timeout=30,
        )
        self.assertEqual("503", result.stdout.strip())

    def test_owner_login_env_matches_the_login_expected_by_the_entrypoint(self) -> None:
        self.assertRegex(
            self.htaccess,
            r"(?m)^SetEnv\s+CONTROLBOT_OWNER_LOGIN\s+USUARIO\s*$",
        )
        self.assertIn("getenv('CONTROLBOT_OWNER_LOGIN')", self.public_index)
        self.assertIn("$server['REMOTE_USER']", self.entrypoint)
        self.assertIn("REMOTE_USER", self.runbook)
        self.assertIn("CONTROLBOT_OWNER_LOGIN", self.runbook)
        self.assertNotIn("X-Remote-User", self.htaccess)


if __name__ == "__main__":
    unittest.main()

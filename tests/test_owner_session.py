import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/owner_session_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class OwnerSessionTests(unittest.TestCase):
    def test_totp_reauthentication_is_time_bounded(self):
        data = scenario("totp")
        self.assertEqual(data["login"], "pl0n3r")
        self.assertIsInstance(data["reauthenticated_at"], int)
        self.assertIn("TOTP inválido", data["invalid_error"])
        self.assertFalse(data["invalid_has_reauth"])

    def test_github_token_is_encrypted_server_side(self):
        data = scenario("token")
        self.assertTrue(data["roundtrip_ok"])
        self.assertFalse(data["sealed_is_plaintext"])
        self.assertFalse(data["sealed_contains_plaintext"])
        self.assertGreater(len(data["sealed"]), 40)

    def test_csrf_fails_closed(self):
        data = scenario("csrf")
        self.assertIn("CSRF inválido", data["error"])

    def test_request_flags_cannot_elevate_owner(self):
        data = scenario("elevate")
        self.assertIn("Sesión del dueño inválida", data["error"])
        wrong = scenario("wrong-owner")
        self.assertIn("no corresponde al dueño", wrong["error"])
        self.assertTrue(wrong["session_empty"])


if __name__ == "__main__":
    unittest.main()

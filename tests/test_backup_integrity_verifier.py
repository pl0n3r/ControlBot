import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    p = subprocess.run(
        ["php", str(ROOT / "tests" / "backup_integrity_verifier_scenarios.php"), name],
        cwd=ROOT, check=True, capture_output=True, text=True,
    )
    return json.loads(p.stdout)


class Cb182IntegridadBackupTests(unittest.TestCase):
    def test_contrato_principal(self):
        result = scenario("principal")
        expected_ok = {"status": "OK", "reason": "synthetic_integrity_only"}
        self.assertEqual(result["ok"], expected_ok)
        self.assertEqual(result["uppercase"], expected_ok)
        self.assertEqual(result["tamper"], {"status": "CORRUPTO", "reason": "checksum_mismatch"})
        self.assertEqual(result["truncated"], {"status": "CORRUPTO", "reason": "size_mismatch"})

    def test_falla_cerrado(self):
        result = scenario("falla_cerrado")
        for key in ("empty", "missing", "null", "empty_sample"):
            self.assertEqual(result[key]["status"], "INCOMPLETO", key)
        for key in ("extra", "wrong_version", "wrong_environment", "bad_digest", "zero_size"):
            self.assertEqual(result[key]["status"], "UNKNOWN", key)
        self.assertNotIn("OK", [item["status"] for item in result.values()])

    def test_sin_datos_reales(self):
        results = scenario("privacidad")
        self.assertEqual(set(results["probe"]), {"status", "reason"})
        self.assertNotIn("SENSITIVE_FAKE_FIXTURE_ONLY", json.dumps(results))
        self.assertEqual(results["source"], "BackupIntegrityVerifier.php")
        source = (ROOT / "src" / "BackupIntegrityVerifier.php").read_text()
        for forbidden in ("file_get_contents", "fopen(", "curl_", "shell_exec", "exec(",
                          "PDO(", "mysqli", "file_put_contents", "echo ", "print "):
            self.assertNotIn(forbidden, source)
        self.assertIn("synthetic_integrity_only", source)

    def test_estados_unknown_explicitos(self):
        result = scenario("unknown")
        for key in ("future", "reversed", "stale", "invalid_now", "string_timestamp",
                    "oversized", "bad_ref"):
            self.assertEqual(result[key]["status"], "UNKNOWN", key)
        self.assertEqual(result["boundary_fresh"]["status"], "OK")


if __name__ == "__main__":
    unittest.main()

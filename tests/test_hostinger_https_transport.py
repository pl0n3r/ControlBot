"""CB751: actual PHP 8.x offline Hostinger HTTPS simulation; no mocks, no network."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tests/hostinger_https_transport_scenarios.php"
CLASS = ROOT / "src/HostingerHttpsTransportSimulator.php"


def scenario(name):
    command = [
        "php", "-d", "allow_url_fopen=0",
        str(SCRIPT), name,
    ]
    run = subprocess.run(
        command, cwd=ROOT, capture_output=True, text=True,
        check=False, timeout=15,
    )
    if run.returncode:
        raise AssertionError(run.stderr.strip() or run.stdout.strip())
    return json.loads(run.stdout)


class Cb751HttpsSimTests(unittest.TestCase):
    def test_contrato_principal(self):
        data = scenario("contract")
        self.assertEqual(data["attempts"], 2)
        self.assertEqual(data["first"], data["again"])
        self.assertEqual(data["first"]["status"], "UNKNOWN")
        self.assertEqual(
            data["first"]["reason"], "http_2xx_not_proof_of_mutation")
        self.assertEqual(data["first"]["attempt_count"], 1)
        self.assertFalse(data["first"]["retry_allowed"])
        self.assertEqual(data["collision"]["status"], "REJECTED")
        self.assertEqual(data["collision"]["reason"], "idempotency_conflict")
        self.assertEqual(data["collision"]["attempt_count"], 0)
        self.assertEqual(data["foreign"]["status"], "REJECTED")
        self.assertNotEqual(data["first"]["tenant_ref"], data["bravo"]["tenant_ref"])
        self.assertEqual(data["bravo"]["status"], "UNKNOWN")
        self.assertNotIn("fixture:tenant/alpha", json.dumps(data["bravo"]))

    def test_falla_cerrado(self):
        data = scenario("closed")
        self.assertEqual(len(data["receipts"]), 13)
        self.assertEqual(data["attempts"], 13)
        reasons = {
            "timeout": "timeout_uncertain",
            "network": "network_uncertain",
            "ambiguous": "ambiguous_response",
            "certificate": "certificate_invalid",
            "redirect": "redirect_blocked",
            "redirect_count": "redirect_blocked",
            "client": "http_4xx",
            "server": "http_5xx_uncertain",
            "unverified_tls": "invalid_transport_evidence",
            "missing_tls": "invalid_transport_evidence",
            "not_http": "invalid_transport_evidence",
            "unknown_kind": "invalid_transport_evidence",
            "body_injection": "invalid_transport_evidence",
        }
        for name, expected in reasons.items():
            with self.subTest(case=name):
                row = data["receipts"][name]
                self.assertEqual(row["reason"], expected)
                self.assertIn(row["status"], {"UNKNOWN", "REJECTED"})
                self.assertFalse(row["approved"])
                self.assertFalse(row["closed"])
                self.assertFalse(row["retry_allowed"])
                self.assertIsNone(row["evidence_ref"])

    def test_sin_datos_reales(self):
        code = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in (
            "curl_exec(", "file_get_contents(", "pdo(", "mysqli(",
            "shell_exec(", "stream_socket_client(", "fsockopen(",
        ):
            self.assertNotIn(forbidden, code)
        data = scenario("privacy")
        self.assertEqual(data["attempts"], 0)
        self.assertEqual(len(data["receipts"]), 8)
        serialized = json.dumps(data)
        for forbidden in (
            "person@example.com", "+573001234567", "1234567890",
            "ghp_example-secret", "Jane Doe", "real.example.net",
        ):
            self.assertNotIn(forbidden, serialized)
        for row in data["receipts"].values():
            self.assertEqual(row["status"], "UNKNOWN")
            self.assertEqual(row["reason"], "invalid_fixture_input")
            self.assertIsNone(row["tenant_ref"])
            self.assertFalse(row["approved"])
            self.assertFalse(row["retry_allowed"])

    def test_estados_unknown_explicitos(self):
        data = scenario("states")
        self.assertEqual(data["initial"], data["retry"])
        self.assertEqual(data["attempts"], 1)
        self.assertEqual(data["initial"]["status"], "UNKNOWN")
        self.assertEqual(data["initial"]["reason"], "timeout_uncertain")
        self.assertEqual(data["success_http"]["status"], "UNKNOWN")
        self.assertEqual(
            data["success_http"]["reason"], "http_2xx_not_proof_of_mutation")
        self.assertEqual(data["attempts_after"], 2)
        for receipt in (data["initial"], data["retry"], data["success_http"]):
            self.assertFalse(receipt["approved"])
            self.assertFalse(receipt["closed"])
            self.assertFalse(receipt["official"])
            self.assertFalse(receipt["retry_allowed"])
            self.assertIsNone(receipt["evidence_ref"])


if __name__ == "__main__":
    unittest.main()

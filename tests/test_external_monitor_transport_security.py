import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNNER = (ROOT / "scripts" / "external-monitor.php").read_text(encoding="utf-8")


def closure(name: str, next_name: str | None = None) -> str:
    start = RUNNER.index(f"${name}=static function")
    end = RUNNER.index(f"${next_name}=static function", start) if next_name else RUNNER.index("try{", start)
    return RUNNER[start:end]


class ExternalMonitorTransportSecurityTests(unittest.TestCase):
    def test_probe_restricts_initial_and_redirect_protocols_to_https(self):
        probe = closure("http", "alert")
        self.assertIn("CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS", probe)
        self.assertIn("CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS", probe)
        self.assertIn("CURLOPT_FOLLOWLOCATION=>$d['max_redirects']>0", probe)

    def test_webhook_transport_is_https_only_without_redirects(self):
        webhook = closure("alert")
        self.assertIn("CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS", webhook)
        self.assertIn("CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS", webhook)
        self.assertIn("CURLOPT_FOLLOWLOCATION=>false", webhook)

    def test_protocol_guard_is_exact_and_regression_safe(self):
        self.assertEqual(RUNNER.count("CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS"), 2)
        self.assertEqual(RUNNER.count("CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS"), 2)
        self.assertNotRegex(RUNNER, r"CURLPROTO_(?:HTTP|FTP|FTPS|FILE|ALL)\b")

    def test_streaming_bounds_and_returntransfer_guard_remain_intact(self):
        probe = closure("http", "alert")
        webhook = closure("alert")
        self.assertIn("CURLOPT_WRITEFUNCTION", probe)
        self.assertIn("max_body_bytes", probe)
        self.assertIn("CURLOPT_WRITEFUNCTION", webhook)
        self.assertNotIn("CURLOPT_RETURNTRANSFER", RUNNER)
        self.assertIn("CURLOPT_TIMEOUT_MS", probe)
        self.assertIn("CURLOPT_TIMEOUT_MS", webhook)


if __name__ == "__main__":
    unittest.main()

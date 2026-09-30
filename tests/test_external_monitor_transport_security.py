import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNNER = (ROOT / "scripts" / "external-monitor.php").read_text(encoding="utf-8")
HTTPS_PROTOCOL = "CURLPROTO_HTTPS"
PROTOCOLS_OPTION = "CURLOPT_PROTOCOLS"
REDIRECT_PROTOCOLS_OPTION = "CURLOPT_REDIR_PROTOCOLS"


def closure(name: str, next_name: str | None = None) -> str:
    """Return one named static closure from the external-monitor runner."""
    start = RUNNER.index(f"${name}=static function")
    end = RUNNER.index(f"${next_name}=static function", start) if next_name else RUNNER.index("try{", start)
    return RUNNER[start:end]


def option_values(scope: str, option: str) -> list[str]:
    """Extract complete cURL option values from one runner closure."""
    marker = f"{option}=>"
    return [part.split(",", 1)[0].strip() for part in scope.split(marker)[1:]]


PROBE = closure("http", "alert")
WEBHOOK = closure("alert")


class ExternalMonitorTransportSecurityTests(unittest.TestCase):
    def test_probe_restricts_initial_and_redirect_protocols_to_https(self):
        """Probe transport must stay HTTPS-only before and after redirects."""
        self.assertIn(f"{PROTOCOLS_OPTION}=>{HTTPS_PROTOCOL}", PROBE)
        self.assertIn(f"{REDIRECT_PROTOCOLS_OPTION}=>{HTTPS_PROTOCOL}", PROBE)
        self.assertIn("CURLOPT_FOLLOWLOCATION=>$d['max_redirects']>0", PROBE)

    def test_webhook_transport_is_https_only_without_redirects(self):
        """Webhook transport must stay HTTPS-only and never follow redirects."""
        self.assertIn(f"{PROTOCOLS_OPTION}=>{HTTPS_PROTOCOL}", WEBHOOK)
        self.assertIn(f"{REDIRECT_PROTOCOLS_OPTION}=>{HTTPS_PROTOCOL}", WEBHOOK)
        self.assertIn("CURLOPT_FOLLOWLOCATION=>false", WEBHOOK)

    def test_protocol_guard_is_exact_and_regression_safe(self):
        """Every protocol bitmask must equal HTTPS exactly, with no widening."""
        for scope in (PROBE, WEBHOOK):
            self.assertEqual(option_values(scope, PROTOCOLS_OPTION), [HTTPS_PROTOCOL])
            self.assertEqual(option_values(scope, REDIRECT_PROTOCOLS_OPTION), [HTTPS_PROTOCOL])

    def test_streaming_bounds_and_returntransfer_guard_remain_intact(self):
        """Existing bounded streaming and timeout safeguards must remain intact."""
        self.assertIn("CURLOPT_WRITEFUNCTION", PROBE)
        self.assertIn("max_body_bytes", PROBE)
        self.assertIn("CURLOPT_WRITEFUNCTION", WEBHOOK)
        self.assertNotIn("CURLOPT_RETURNTRANSFER", RUNNER)
        self.assertIn("CURLOPT_TIMEOUT_MS", PROBE)
        self.assertIn("CURLOPT_TIMEOUT_MS", WEBHOOK)


if __name__ == "__main__":
    unittest.main()

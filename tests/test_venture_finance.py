import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "venture_finance_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class VentureFinanceTests(unittest.TestCase):
    def test_snapshot_uses_exact_scope_currency_and_integer_minor_units(self):
        data = scenario("base")
        self.assertEqual(data["venture_id"], "condor")
        self.assertEqual(data["period"], "2026-09")
        self.assertEqual(data["currency"], "COP")
        self.assertIsInstance(data["revenue"], int)
        self.assertEqual(data["revenue"], 10_000_000)

    def test_financial_results_are_derived_server_side(self):
        data = scenario("derived")
        self.assertEqual(data["net_revenue"], 9_500_000)
        self.assertEqual(data["gross_profit"], 7_500_000)
        self.assertEqual(data["operating_result"], 5_500_000)
        self.assertIn("campos inválidos", data["client_derived_rejected"])

    def test_revenue_streams_are_aggregate_unique_and_pii_free(self):
        data = scenario("streams")
        self.assertIn("duplicado", data["duplicate"])
        self.assertIn("stream_id inválido", data["pii"])
        self.assertIn("campos inválidos", data["extra_ref"])

    def test_stale_or_unknown_never_becomes_verified(self):
        data = scenario("freshness")
        self.assertIn("no puede ser verified", data["stale_verified"])
        self.assertIn("no puede ser verified", data["unknown_verified"])
        self.assertEqual(data["stale_unknown"]["freshness"], "stale")
        self.assertEqual(data["stale_unknown"]["confidence"], "unknown")

    def test_provenance_and_observed_at_are_required(self):
        data = scenario("provenance")
        self.assertIn("source_ref inválido", data["bad_source"])
        self.assertIn("observed_at inválido", data["bad_time"])

    def test_invalid_or_sensitive_financial_payloads_fail_closed(self):
        data = scenario("invalid")
        self.assertIn("integer minor-units", data["float_money"])
        self.assertIn("integer minor-units", data["negative_money"])
        self.assertIn("ISO-4217", data["bad_currency"])
        self.assertIn("period", data["bad_period"])
        self.assertIn("campos inválidos", data["extra"])

    def test_normalization_is_deterministic(self):
        data = scenario("deterministic")
        self.assertEqual(data["a"], data["b"])
        self.assertEqual(
            [stream["stream_id"] for stream in data["a"]["revenue_streams"]],
            ["addons", "subscriptions"],
        )


if __name__ == "__main__":
    unittest.main()

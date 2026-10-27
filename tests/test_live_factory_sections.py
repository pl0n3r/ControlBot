"""Offline acceptance tests for ControlBot #975 (live factory sections 1–8)."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    result = subprocess.run(
        ["php", str(ROOT / "tests/live_factory_sections_scenarios.php"), name],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError(result.stderr or f"scenario {name} failed")
    return json.loads(result.stdout)


class Cb561SeccionesTests(unittest.TestCase):
    def test_contrato_principal(self):
        result = scenario("full")
        view = result["view"]
        self.assertTrue(result["deterministic"])
        self.assertEqual(view["version"], 1)
        self.assertEqual(view["observed_at"], 1000)
        self.assertEqual(len(view["canonical_fingerprint"]), 64)
        self.assertEqual(list(view["sections"]), [
            "owner_decisions", "batches", "releases", "blockers", "production",
            "quality", "work", "learning",
        ])
        for rows in view["sections"].values():
            self.assertTrue(rows)
            for signal in rows:
                self.assertEqual(signal["freshness"], "current")
                self.assertEqual(signal["age_seconds"], 1000 - signal["observed_at"])
                self.assertTrue(signal["source_ref"])
                self.assertTrue(signal["authority"])
        decision = view["sections"]["owner_decisions"][0]
        self.assertEqual(decision["data"]["issue_ref"],
                         "https://github.com/pl0n3r/ControlBot/issues/561")
        self.assertEqual(view["sections"]["quality"][0]["data"]["quality_gate"], "PASS")

    def test_falla_cerrado(self):
        result = scenario("missing")
        self.assertEqual(result["missing"]["state"], "unknown")
        self.assertEqual(result["missing"]["freshness"], "unknown")
        self.assertIsNone(result["missing"]["source_ref"])
        self.assertIsNone(result["missing"]["observed_at"])
        self.assertTrue(all(result[name] for name in (
            "bad_link", "duplicate", "wrong_source", "future",
        )))

    def test_sin_datos_reales(self):
        result = scenario("privacy")
        self.assertTrue(all(result[name] for name in (
            "secret_rejected", "email_rejected", "token_rejected",
        )))
        source = result["source"].lower()
        for forbidden in ("curl_", "file_get_contents", "file_put_contents",
                          "new pdo", "mysqli", "httpclient", "shell_exec", "proc_open"):
            self.assertNotIn(forbidden, source)
        output = result["serialized"].lower()
        for forbidden in ("password", "api_key", "private_key", "authorization",
                          "bearer ", "@", "user@example.test"):
            self.assertNotIn(forbidden, output)

    def test_estados_unknown_explicitos(self):
        result = scenario("missing")
        stale = result["stale"]
        self.assertEqual((stale["freshness"], stale["state"]), ("stale", "unknown"))
        self.assertEqual(stale["observed_at"], 1)
        self.assertEqual(stale["age_seconds"], 999)
        self.assertIsNone(result["missing"]["age_seconds"])


if __name__ == "__main__":
    unittest.main()

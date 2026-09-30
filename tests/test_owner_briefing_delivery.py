import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class OwnerBriefingDeliveryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        completed = subprocess.run(
            ["php", str(ROOT / "tests" / "owner_briefing_delivery_scenarios.php")],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
        )
        cls.snapshot = json.loads(completed.stdout)

    def test_daily_tick_emits_single_delivery_per_day_and_fingerprint(self):
        first = self.snapshot["first"]
        changed = self.snapshot["changed"]

        self.assertEqual(first["decision"], "deliver")
        self.assertEqual(first["delivery_status"], "pending")
        self.assertIsNotNone(first["delivery_intent"])
        self.assertEqual(first["delivery_intent"]["delivery_ref"], first["delivery_ref"])
        self.assertEqual(first["delivery_intent"]["dedupe_key"], first["dedupe_key"])

        self.assertEqual(changed["decision"], "deliver")
        self.assertNotEqual(changed["briefing_fingerprint"], first["briefing_fingerprint"])
        self.assertNotEqual(changed["delivery_ref"], first["delivery_ref"])
        self.assertNotEqual(changed["dedupe_key"], first["dedupe_key"])

    def test_delivery_uses_same_fail_closed_owner_briefing_contract(self):
        unknown = self.snapshot["unknown"]

        self.assertEqual(unknown["briefing"]["sections"]["today"]["status"], "unknown")
        self.assertEqual(unknown["briefing"]["sections"]["costs"]["status"], "unknown")
        self.assertEqual(unknown["briefing"]["sections"]["delivered"]["status"], "empty")
        self.assertIn(
            unknown["briefing"]["attention_label"],
            ("Necesitas entrar", "No necesitas entrar"),
        )
        self.assertTrue(self.snapshot["invalid_snapshot_rejected"])

    def test_retry_is_idempotent_and_does_not_duplicate_notification(self):
        first = self.snapshot["first"]
        retry = self.snapshot["retry"]

        self.assertEqual(retry["decision"], "suppress")
        self.assertEqual(retry["delivery_status"], "suppressed")
        self.assertEqual(retry["reasons"], ["duplicate"])
        self.assertIsNone(retry["delivery_intent"])
        self.assertEqual(retry["briefing_fingerprint"], first["briefing_fingerprint"])
        self.assertEqual(retry["delivery_ref"], first["delivery_ref"])
        self.assertEqual(retry["dedupe_key"], first["dedupe_key"])
        self.assertEqual(retry["channel"], "push")

    def test_unavailable_channel_or_blocked_policy_fails_closed(self):
        cases = {
            "stale": "scheduler_stale",
            "blocked": "policy_blocked",
            "unavailable": "channel_unavailable",
        }
        for case, reason in cases.items():
            with self.subTest(case=case):
                result = self.snapshot[case]
                self.assertEqual(result["decision"], "suppress")
                self.assertEqual(result["delivery_status"], "suppressed")
                self.assertIn(reason, result["reasons"])
                self.assertIsNone(result["delivery_intent"])

        source = (ROOT / "src" / "OwnerBriefingDelivery.php").read_text(encoding="utf-8").lower()
        forbidden = (
            "curl_",
            "fsockopen",
            "new pdo",
            "mysqli",
            "file_put_contents",
            "shell_exec",
            "proc_open",
            "exec(",
            "system(",
            "mail(",
        )
        self.assertFalse(any(symbol in source for symbol in forbidden))


if __name__ == "__main__":
    unittest.main()

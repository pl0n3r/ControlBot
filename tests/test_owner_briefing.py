import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "owner_briefing_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class OwnerBriefingTests(unittest.TestCase):
    def test_sections_are_fail_closed_and_never_invent_health(self):
        data = scenario("fail-closed")
        sections = data["sections"]
        self.assertEqual(
            list(sections),
            ["delivered", "today", "broken", "decisions", "costs"],
        )
        self.assertEqual(sections["delivered"]["status"], "empty")
        self.assertEqual(sections["broken"]["status"], "unknown")
        self.assertEqual(sections["costs"]["status"], "unknown")
        self.assertTrue(data["needs_owner_attention"])
        self.assertEqual(data["attention_label"], "Necesitas entrar")

    def test_evidence_and_summary_are_strictly_allowlisted(self):
        safe = scenario("safe-evidence")
        self.assertEqual(
            safe["sections"]["delivered"]["items"][0]["evidence"],
            "https://github.com/pl0n3r/ControlBot/pull/101",
        )
        self.assertEqual(
            safe["sections"]["today"]["items"][0]["evidence"],
            "controlbot:work/102",
        )
        self.assertTrue(scenario("bad-evidence")["blocked"])
        self.assertTrue(scenario("bad-path-evidence")["blocked"])
        self.assertTrue(scenario("secret-summary")["blocked"])

        reordered = scenario("reordered-item")
        self.assertEqual(
            reordered["sections"]["today"]["items"][0]["summary"],
            "Orden de claves independiente",
        )

    def test_owner_attention_is_deterministic(self):
        quiet = scenario("quiet")
        self.assertFalse(quiet["needs_owner_attention"])
        self.assertEqual(quiet["attention_label"], "No necesitas entrar")

        decision = scenario("decision")
        self.assertTrue(decision["needs_owner_attention"])
        self.assertEqual(decision["attention_label"], "Necesitas entrar")

    def test_visible_items_are_bounded_with_overflow_count(self):
        data = scenario("overflow")
        delivered = data["sections"]["delivered"]
        self.assertEqual(delivered["status"], "real")
        self.assertEqual(delivered["total"], 5)
        self.assertEqual(len(delivered["items"]), 3)
        self.assertEqual(delivered["overflow_count"], 2)
        self.assertEqual(
            [item["summary"] for item in delivered["items"]],
            ["Entrega 1", "Entrega 2", "Entrega 3"],
        )


if __name__ == "__main__":
    unittest.main()

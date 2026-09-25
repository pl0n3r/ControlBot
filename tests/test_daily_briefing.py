import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run(name: str, check: bool = True):
    result = subprocess.run(
        ["php", str(ROOT / "tests/daily_briefing_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True,
    )
    if check and result.returncode != 0:
        raise AssertionError(result.stderr)
    if result.returncode != 0:
        return result
    return json.loads(result.stdout)


class DailyBriefingTests(unittest.TestCase):
    def test_five_sections_are_always_present(self):
        data = run("empty")["built"]
        self.assertEqual(
            list(data["sections"].keys()),
            ["delivered", "today", "broken", "decisions", "spend"],
        )
        self.assertTrue(all(section["empty"] for section in data["sections"].values()))

    def test_evidence_must_be_allowlisted_https(self):
        for scenario in ["invalid-http", "invalid-host"]:
            result = run(scenario, check=False)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("allowlist", result.stderr)

    def test_attention_uses_structured_flags_only(self):
        urgent_text = run("text-says-urgent")["built"]
        self.assertFalse(urgent_text["attention_required"])
        self.assertEqual(urgent_text["owner_action"], "No necesitas entrar")

        full = run("full")["built"]
        self.assertTrue(full["attention_required"])
        self.assertEqual(full["owner_action"], "Necesitas entrar")

    def test_briefing_is_bounded_for_one_minute_read(self):
        result = run("too-many", check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("excede el límite", result.stderr)

    def test_html_escapes_and_links_every_item(self):
        data = run("escape")
        html = data["html"]
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertNotIn("<script>alert(1)</script>", html)
        self.assertIn('rel="noopener noreferrer"', html)
        self.assertIn(">Ver evidencia</a>", html)

        full_html = run("full")["html"]
        self.assertEqual(full_html.count(">Ver evidencia</a>"), 5)

    def test_explicit_owner_action_sentence(self):
        empty_html = run("empty")["html"]
        full_html = run("full")["html"]
        self.assertIn(">No necesitas entrar<", empty_html)
        self.assertIn(">Necesitas entrar<", full_html)


if __name__ == "__main__":
    unittest.main()

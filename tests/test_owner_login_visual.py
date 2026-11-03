import re
import subprocess
import unittest
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[1] / "src" / "OwnerLoginGate.php"


class OwnerLoginVisualTests(unittest.TestCase):
    def setUp(self):
        self.source = SOURCE.read_text(encoding="utf-8")

    def test_login_page_uses_theme_tokens_and_no_external_assets(self):
        self.assertIn("tokensCss()", self.source)
        self.assertIn("color-scheme:light dark", self.source)
        self.assertNotRegex(self.source, r"https?://(?!www\.w3\.org)")
        self.assertNotIn("<script", self.source)
        self.assertNotRegex(self.source, r"#[0-9a-fA-F]{6}\b")

    def test_login_page_keeps_focus_error_and_reduced_motion_states(self):
        for needle in (":focus-visible", "input:focus", 'role="alert"', "prefers-reduced-motion:reduce"):
            self.assertIn(needle, self.source)
        self.assertIsNone(re.search(r"border-(left|right):\s*[2-9]", self.source))

    def test_source_is_valid_php(self):
        result = subprocess.run(["php", "-l", str(SOURCE)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "lex_jurisdiction_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class LexJurisdictionTests(unittest.TestCase):
    def test_core_has_no_colombia_specific_branching(self):
        core = (ROOT / "src" / "LexCore.php").read_text()
        self.assertNotIn("Colombia", core)
        self.assertNotIn("country:CO", core)
        jurisdiction = (ROOT / "src" / "LexJurisdiction.php").read_text()
        self.assertNotIn("country:CO", jurisdiction)
        self.assertNotIn("Colombia", jurisdiction)

    def test_pack_requires_version_scope_controls_sources_review_and_assumptions(self):
        data = scenario("co")
        self.assertEqual(data["schema_version"], 1)
        self.assertEqual(data["jurisdiction"], "country:CO")
        self.assertEqual(data["pack_version"], "1.0.0")
        self.assertEqual(data["evidence_state"], "current")
        self.assertTrue(data["sources"])
        self.assertTrue(data["controls"])
        self.assertTrue(data["responsible_refs"])
        self.assertIn("compatibility", data)
        invalid = scenario("invalid")
        self.assertIn("fields invalid", invalid["missing_field"])
        self.assertIn("unknown", invalid["unknown_source"])
        self.assertIn("future", invalid["future_review"])
        self.assertIn("uri invalid", invalid["non_https_source"])

    def test_stale_or_unknown_pack_is_not_current(self):
        data = scenario("freshness")
        for name, pack in data.items():
            with self.subTest(name=name):
                self.assertEqual(pack["evidence_state"], "not_current")
                self.assertTrue(pack["reasons"])

    def test_second_fixture_pack_requires_no_core_change(self):
        data = scenario("generic")
        self.assertEqual(data["jurisdiction"], "country:MX")
        self.assertEqual(data["pack_id"], "pack-mx-fixture-v1")
        self.assertEqual(data["evidence_state"], "current")
        self.assertEqual(data["controls"][0]["source_refs"], ["fixture-source"])


if __name__ == "__main__":
    unittest.main()

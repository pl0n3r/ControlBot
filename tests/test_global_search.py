import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "global_search_scenarios.php"), name],
        cwd=ROOT,
        text=True,
        capture_output=True,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError(result.stderr.strip() or f"{name} failed")
    return json.loads(result.stdout)

class GlobalSearchTests(unittest.TestCase):
    def test_issue_number_query_returns_canonical_incident(self):
        data = scenario("number")
        self.assertEqual(data["total"], 1)
        result = data["items"][0]
        self.assertEqual(
            (result["number_or_id"], result["repo"], result["state"], result["source"]),
            (78, "pl0n3r/ControlBot", "closed", "github"),
        )
        self.assertTrue(result["canonical_url"].endswith("/issues/78"))
    def test_cross_repo_results_are_deduplicated_and_ranked_stably(self):
        data = scenario("stable")
        self.assertEqual(data["a"], data["b"])
        self.assertEqual(data["unicode"]["total"], 1)
        urls = [result["canonical_url"] for result in data["a"]["items"]]
        self.assertEqual(len(urls), len(set(urls)))
    def test_filters_preserve_stable_pagination(self):
        data = scenario("filters")
        self.assertEqual(data["a"], data["b"])
        self.assertEqual(data["a"]["page"], 2)
        self.assertEqual(data["typed"]["total"], 1)
        self.assertEqual(data["typed"]["items"][0]["state"], "open")
    def test_stale_source_is_visible_and_never_presented_as_fresh(self):
        rows = scenario("freshness")["items"]
        states = {result["repo"]: result["freshness"] for result in rows}
        self.assertEqual(states["pl0n3r/factory"], "stale")
        self.assertEqual(states["pl0n3r/FactoryRunner"], "unavailable")
    def test_index_never_expands_source_permissions(self):
        data = scenario("permissions")
        identifiers = [result["number_or_id"] for result in data["items"]]
        self.assertNotIn(120, identifiers)
        self.assertNotIn(121, identifiers)
    def test_snippets_are_sanitized_without_losing_navigation_context(self):
        data = scenario("sanitize")
        result = data["search"]["items"][0]
        rendered = (result["title"] + " " + result["snippet"]).lower()
        self.assertNotIn("alice@example.com", rendered)
        self.assertNotIn("hunter two words", rendered)
        self.assertNotIn("jsonabc", rendered)
        self.assertNotIn("bearer xyz", rendered)
        self.assertNotIn("300 123 4567", rendered)
        self.assertIn("redacted", rendered)
        self.assertTrue(result["canonical_url"].endswith("/issues/122"))
        self.assertTrue(data["network_rejected"])
        self.assertTrue(data["dot_rejected"])


if __name__ == "__main__":
    unittest.main()

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "lex_watch_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class LexWatchTests(unittest.TestCase):
    def test_signal_distinguishes_publication_effective_state_and_dates(self):
        data = scenario("publication")
        rows = {
            row["signal_ids"][0]: row
            for row in data["signals"]
        }

        effective = rows["signal-effective"]
        self.assertEqual("published", effective["publication_state"])
        self.assertEqual("effective", effective["effective_state"])
        self.assertEqual(1000, effective["published_at"])
        self.assertEqual(1100, effective["effective_at"])
        self.assertEqual(1050, effective["observed_at"])

        future = rows["signal-future"]
        self.assertEqual("published", future["publication_state"])
        self.assertEqual("not_yet_effective", future["effective_state"])
        self.assertEqual(1400, future["effective_at"])

    def test_rumor_unknown_or_stale_source_fails_closed(self):
        data = scenario("fail_closed")

        for row in data["signals"]:
            self.assertEqual("unknown", row["publication_state"])
            self.assertEqual("unknown", row["effective_state"])
            self.assertFalse(row["review_candidate"])

        self.assertEqual([], data["review_candidates"])
        self.assertIn("future", data["future_observed"])

    def test_equivalent_regulatory_signals_are_deduplicated(self):
        data = scenario("dedupe")

        self.assertEqual(1, len(data["signals"]))
        row = data["signals"][0]
        self.assertEqual(["signal-a", "signal-b"], row["signal_ids"])
        self.assertEqual(
            ["controlbot:lex/source/a", "controlbot:lex/source/b"],
            row["source_refs"],
        )
        self.assertEqual(
            ["controlbot:lex/evidence/a", "controlbot:lex/evidence/b"],
            row["evidence_refs"],
        )
        self.assertEqual(1070, row["observed_at"])
        self.assertEqual(1, len(data["review_candidates"]))

    def test_only_traceable_impact_produces_review_candidate(self):
        data = scenario("impact")

        candidates = data["review_candidates"]
        self.assertEqual(1, len(candidates))
        candidate = candidates[0]
        self.assertEqual("legal_review", candidate["kind"])
        self.assertEqual(
            ["controlbot:venture/condor"],
            candidate["impact_refs"],
        )
        self.assertNotIn("compliant", candidate)
        self.assertNotIn("legal_conclusion", candidate)

        rows = {
            row["signal_ids"][0]: row
            for row in data["signals"]
        }
        self.assertFalse(rows["signal-none"]["review_candidate"])
        self.assertFalse(rows["signal-unknown-impact"]["review_candidate"])
        self.assertIn("invalid", data["sensitive"])


if __name__ == "__main__":
    unittest.main()

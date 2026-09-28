import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str):
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "lex_core_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class LexCoreTests(unittest.TestCase):
    def test_legal_states_are_exact_and_unknown_fails_closed(self):
        data = scenario("states")
        states = {row["obligation_id"]: row["status"] for row in data["obligations"]}
        self.assertEqual(set(states.values()), {"compliant", "gap", "unknown", "not_applicable"})
        self.assertEqual(data["summary"]["counts"], {
            "compliant": 1,
            "gap": 1,
            "unknown": 1,
            "not_applicable": 1,
        })

    def test_stale_or_unknown_evidence_never_becomes_compliant(self):
        data = scenario("freshness")
        rows = {row["obligation_id"]: row for row in data["obligations"]}
        for row in rows.values():
            self.assertEqual(row["status"], "unknown")
            self.assertNotEqual(row["status"], "compliant")
        self.assertEqual(rows["ob-expired"]["freshness"], "stale")
        self.assertEqual(rows["ob-expired"]["reported_freshness"], "fresh")

    def test_registry_preserves_source_evidence_freshness_owner_and_review(self):
        row = scenario("registry")["obligations"][0]
        self.assertEqual(row["market_id"], "market-co")
        self.assertEqual(row["jurisdiction_pack_id"], "pack-co-v1")
        self.assertEqual(row["source_refs"], ["controlbot:lex/source/a", "controlbot:lex/source/b"])
        self.assertEqual(row["evidence_refs"], ["controlbot:lex/evidence/a", "controlbot:lex/evidence/b"])
        self.assertEqual(row["freshness"], "fresh")
        self.assertEqual(row["responsible_ref"], "controlbot:identity/legal-owner")
        self.assertEqual(row["reviewed_at"], 1100)
        self.assertEqual(row["expires_at"], 2000)
        self.assertEqual(row["next_review_at"], 1800)
        self.assertTrue(row["human_review_required"])

    def test_unknown_fields_and_sensitive_references_are_rejected(self):
        data = scenario("invalid")
        self.assertIn("fields invalid", data["extra"])
        self.assertIn("invalid", data["sensitive"])
        self.assertIn("requires evidence", data["compliant_without_evidence"])
        self.assertIn("requires justification", data["not_applicable_without_justification"])

    def test_legal_registry_is_deterministic(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertEqual(data["first"], data["second"])
        self.assertEqual(
            [row["obligation_id"] for row in data["first"]["obligations"]],
            ["ob-alpha", "ob-zeta"],
        )


if __name__ == "__main__":
    unittest.main()

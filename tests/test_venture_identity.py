import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "venture_identity_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class VentureIdentityTests(unittest.TestCase):
    def test_distinct_venture_admins_have_no_implicit_cross_scope(self):
        data = scenario("distinct-admins")
        self.assertEqual(data["scoped"]["condor"], ["identity-condor-admin"])
        self.assertEqual(data["scoped"]["grindflow"], ["identity-grindflow-admin"])
        self.assertEqual(data["scoped"]["brvtal"], ["identity-brvtal-admin"])
        self.assertEqual(data["ventures"]["grindflow"]["responsible_identity_id"], "identity-grindflow-admin")

    def test_owner_and_venture_admin_have_distinct_authority(self):
        data = scenario("authority")
        self.assertEqual(data["group"]["owner_identity_id"], "identity-owner")
        self.assertEqual(data["owner"]["authority_level"], "L4_OWNER")
        self.assertEqual(data["owner"]["scope"], "group:group-pl0n3r")
        self.assertEqual(data["venture_admin"]["authority_level"], "L2_VENTURE_ADMIN")
        self.assertEqual(data["venture_admin"]["scope"], "venture:grindflow")

    def test_identity_kinds_reject_credentials_and_session_semantics(self):
        data = scenario("identity-kinds")
        self.assertEqual(data["kinds"], ["human", "agent", "service"])
        self.assertTrue(all(data["blocked_sensitive"].values()))

    def test_unknown_expired_or_invalid_grants_fail_closed(self):
        data = scenario("invalid-grants")
        self.assertTrue(all(data["blocked"].values()))

    def test_revoke_preserves_identity_history_and_other_scopes(self):
        data = scenario("revoke")
        self.assertEqual(data["identity"]["identity_id"], "identity-admin")
        self.assertEqual([row["grant_id"] for row in data["grants"]], ["grant-condor-view"])
        self.assertEqual([row["event_id"] for row in data["history"]], ["event-grant-grindflow", "event-revoke-grindflow"])
        self.assertEqual(data["history"][-1]["scope"], "venture:grindflow")
        self.assertEqual(data["history"][-1]["identity_id"], "identity-admin")
        self.assertEqual(data["history"][-1]["grant_id"], "grant-grindflow")
        self.assertEqual(data["history"][-1]["actor_identity_id"], "identity-owner")

    def test_grant_events_are_attributable(self):
        data = scenario("events")
        event = data["valid"]
        self.assertEqual(event["actor_identity_id"], "identity-owner")
        self.assertEqual(event["scope"], "venture:grindflow")
        self.assertEqual(event["expires_at"], 3000)
        self.assertGreater(event["occurred_at"], 0)
        self.assertTrue(event["reason"])
        self.assertTrue(data["future_history_blocked"])
        self.assertTrue(data["bad_expiry_blocked"])
        self.assertTrue(data["before_grant_revoke_blocked"])

    def test_optional_budget_and_expiry_default_to_null(self):
        data = scenario("optional-fields")
        self.assertIsNone(data["grant"]["budget_limit"])
        self.assertIsNone(data["grant"]["expires_at"])
        self.assertIsNone(data["event"]["expires_at"])

    def test_history_capacity_fails_before_returning_unusable_state(self):
        self.assertTrue(scenario("history-capacity")["blocked"])

    def test_contract_is_deterministic_and_rejects_extra_fields(self):
        data = scenario("deterministic")
        self.assertTrue(data["same"])
        self.assertTrue(data["extra_blocked"])
        self.assertTrue(data["duplicate_blocked"])


if __name__ == "__main__":
    unittest.main()

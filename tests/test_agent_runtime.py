import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "agent_runtime_scenarios.php"), name],
        cwd=ROOT, check=True, text=True, capture_output=True,
    )
    return json.loads(result.stdout)

class AgentRuntimeTests(unittest.TestCase):
    def test_new_provider_or_account_does_not_change_scheduler_contract(self):
        data = scenario("contract")
        self.assertTrue(data["keys_same"])
        self.assertEqual(set(data["views"][0]), {"account_id", "eligible", "free_capacity", "session_ids"})
        self.assertNotIn("provider_id", data["views"][0])
        self.assertEqual(data["assignment"]["assignment_id"], "work_116")

    def test_account_supports_multiple_sessions_within_declared_capacity(self):
        data = scenario("capacity")
        self.assertEqual(data["one"]["free_capacity"], 1)
        self.assertEqual(data["two"]["free_capacity"], 0)
        self.assertFalse(data["two"]["eligible"])
        self.assertTrue(data["overflow"])
        self.assertTrue(data["duplicate"])

    def test_lost_heartbeat_marks_session_unhealthy_without_losing_assignment(self):
        data = scenario("heartbeat")
        self.assertEqual(data["fresh"]["health"], "healthy")
        self.assertEqual(data["stale"]["health"], "stale")
        for key in ("offline", "missing", "future"):
            self.assertEqual(data[key]["health"], "offline")
        for row in data.values():
            self.assertEqual(row["assignment_id"], "work_116")

    def test_rate_limit_or_login_requirement_removes_account_from_capacity_pool(self):
        data = scenario("availability")
        self.assertTrue(data["active"]["eligible"])
        for state in ("rate_limited", "requires_login", "offline"):
            self.assertFalse(data[state]["eligible"])
            self.assertEqual(data[state]["free_capacity"], 0)
            self.assertEqual(data[state]["session_ids"], ["session_1"])

    def test_handoff_is_sufficient_without_full_chat_transcript(self):
        data = scenario("handoff")
        self.assertTrue(data["no_transcript"])
        self.assertTrue(data["secret"])
        self.assertEqual(data["handoff"]["assignment_id"], "work_116")
        self.assertEqual(data["handoff"]["issue_ref"], "pl0n3r/ControlBot#116")
        self.assertEqual(data["handoff"]["next_action"], "Revisar diff y ejecutar AC exactos")

    def test_runtime_never_persists_web_passwords_or_session_tokens(self):
        data = scenario("privacy")
        self.assertTrue(all(data.values()), data)
        inventory = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        fields = {field for treatment in inventory["treatments"] for field in treatment["fields"]}
        self.assertTrue({"account_alias", "profile_alias", "agent_id", "status", "last_heartbeat_at"}.issubset(fields))
        self.assertTrue({"password", "session_token", "cookie", "token"}.isdisjoint(fields))

if __name__ == "__main__":
    unittest.main()

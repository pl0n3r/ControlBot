import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class SecurityUiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        completed = subprocess.run(
            ["php", str(ROOT / "tests" / "security_event_ui_scenarios.php")],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
        )
        cls.d = json.loads(completed.stdout)

    def test_security_event_card_separates_severity_from_confidence(self):
        card = self.d["unread"]["cards"][0]
        self.assertEqual(card["severity"], "critical")
        self.assertEqual(card["confidence"], "provider_reported")
        self.assertNotEqual(card["severity"], card["confidence"])

    def test_security_event_can_be_acknowledged_with_minimized_context(self):
        card = self.d["inbox"]["cards"][0]
        self.assertEqual(card["state"], "acknowledged")
        self.assertEqual(card["context"]["country"], "CO")
        self.assertNotIn("city", card["context"])
        self.assertNotIn("region", card["context"])
        payload = json.dumps(card).lower()
        self.assertNotIn("pereira", payload)
        self.assertNotIn("risaralda", payload)
        self.assertNotIn("actor", payload)

    def test_acknowledge_projects_idempotent_typed_event(self):
        ack = self.d["ack"]
        self.assertEqual(ack, self.d["ack_replay"])
        self.assertEqual(ack["provider"], "controlbot")
        self.assertEqual(ack["event_type"], "security_alert_acknowledged")
        self.assertTrue(ack["event_identity"].startswith("security-event:provider:"))

    def test_acknowledge_does_not_resolve_or_reclassify_original_event(self):
        original = self.d["critical"]
        ack = self.d["ack"]
        self.assertEqual(original["event_type"], "new_device")
        self.assertEqual(ack["severity"], original["severity"])
        self.assertEqual(ack["confidence"], original["confidence"])
        self.assertNotIn("resolved", ack)
        self.assertNotIn("resolved", original)

    def test_inbox_order_is_deterministic_and_severity_first(self):
        cards = self.d["inbox"]["cards"]
        self.assertEqual(
            [card["event_type"] for card in cards],
            ["new_device", "new_login", "new_login", "passkey_added"],
        )
        self.assertLess(cards[1]["observed_at"], cards[2]["observed_at"])
        self.assertEqual(cards[-1]["state"], "superseded")

    def test_security_actions_are_accessible_on_mobile_and_desktop(self):
        action = self.d["unread"]["cards"][0]["actions"]["acknowledge"]
        self.assertEqual(action["surfaces"], ["mobile", "desktop"])
        self.assertTrue(action["keyboard_focusable"])
        self.assertFalse(action["requires_hover"])
        self.assertFalse(action["requires_drag"])
        self.assertTrue(action["enabled"])
        self.assertTrue(action["label"])

    def test_inbox_projector_has_no_external_io_or_actions(self):
        source = (ROOT / "src" / "SecurityEventInbox.php").read_text(encoding="utf-8").lower()
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

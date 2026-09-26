import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests/decision_question_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return json.loads(result.stdout)


class DecisionQuestionTests(unittest.TestCase):
    def test_question_is_bound_to_visible_gate(self):
        data = scenario("success")

        self.assertFalse(data["blocked"])
        self.assertEqual(data["response"]["repository"], "pl0n3r/factory")
        self.assertEqual(data["response"]["issue"], 66)
        self.assertEqual(data["response"]["status"], "answered")
        self.assertEqual(
            data["stored"]["pl0n3r/factory#66"]["question"],
            "¿Qué cambia si apruebo?",
        )
        self.assertIn("¿Qué cambia si apruebo?", data["render"])
        self.assertIn("Respuesta:", data["render"])

    def test_context_is_rebuilt_server_side(self):
        data = scenario("success")
        context = data["provider_calls"][0]["context"]

        self.assertEqual(
            context,
            {
                "repository": "pl0n3r/factory",
                "issue": 66,
                "title": "¿Aplicamos el cambio visual?",
                "summary": "Es un cambio reversible y de bajo riesgo.",
                "category": "brand",
            },
        )
        self.assertTrue(
            any("/issues?state=open&per_page=100&page=1" in row[1] for row in data["seen"])
        )

        manipulated = scenario("manipulated-context")
        self.assertTrue(manipulated["blocked"])
        self.assertEqual(manipulated["provider_calls"], [])
        self.assertEqual(manipulated["stored"], [])
        self.assertTrue(
            any(
                "/issues?state=open&per_page=100&page=1" in row[1]
                for row in manipulated["seen"]
            )
        )

    def test_question_requires_csrf_reauth_and_bounds(self):
        for name in ("no-csrf", "stale-reauth", "too-long", "unicode-too-long"):
            with self.subTest(name=name):
                data = scenario(name)
                self.assertTrue(data["blocked"])
                self.assertEqual(data["stored"], [])
                self.assertEqual(data["provider_calls"], [])

    def test_question_limit_counts_utf8_characters(self):
        data = scenario("unicode-limit")
        self.assertFalse(data["blocked"])
        self.assertEqual(len(data["response"]["question"]), 500)

    def test_question_and_answer_are_bound_without_secrets(self):
        data = scenario("success")
        entry = data["stored"]["pl0n3r/factory#66"]

        self.assertEqual(
            set(entry),
            {"repository", "issue", "question", "answer", "status", "at"},
        )
        self.assertEqual(entry["repository"], "pl0n3r/factory")
        self.assertEqual(entry["issue"], 66)
        self.assertNotIn("github_token", json.dumps(data))
        self.assertNotIn("fixture-server-secret", json.dumps(data))
        self.assertEqual(
            set(data["provider_calls"][0]["context"]),
            {"repository", "issue", "title", "summary", "category"},
        )

    def test_provider_failure_is_recoverable(self):
        for name in ("no-provider", "provider-failure"):
            with self.subTest(name=name):
                data = scenario(name)
                self.assertFalse(data["blocked"])
                self.assertEqual(data["response"]["status"], "unavailable")
                self.assertIsNone(data["response"]["answer"])
                self.assertIn("Puedes decidir igual", data["render"])
                self.assertIn('action="/approvals/execute"', data["render"])


if __name__ == "__main__":
    unittest.main()

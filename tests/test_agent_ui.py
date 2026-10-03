import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "agent_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return result.stdout


class AgentUiTests(unittest.TestCase):
    def test_agents_render_role_capabilities_and_canonical_sessions_without_parallel_state(self):
        html = render("ready")
        for expected in (
            "agent-builder",
            "ingenieria-software",
            "implementation",
            "php",
            "session-builder",
            "working",
            "account-gpt",
            "primary",
            "agent-reviewer",
            "session-reviewer",
        ):
            self.assertIn(expected, html)
        source = (ROOT / "src" / "AgentUi.php").read_text(encoding="utf-8")
        for expected in ("AgentRuntime::agent", "AgentRuntime::session", "AgentRuntime::assignment", "AgentRuntime::handoff"):
            self.assertIn(expected, source)
        self.assertNotIn("private const SESSION_STATES", source)

    def test_assignment_and_handoff_preserve_refs_without_transcript_or_secrets(self):
        html = render("ready")
        for expected in (
            "assignment-638",
            "project-controlbot",
            "pl0n3r/ControlBot#638",
            "handoff-638",
            "pl0n3r/ControlBot#637",
            "a" * 40,
            "Ejecutar aceptación exact-head",
        ):
            self.assertIn(expected, html)
        self.assertIn("Revisar &lt;b&gt;Agent UI&lt;/b&gt;", html)
        handoff = html.split('data-handoff="handoff-638"', 1)[1].split("</li>", 1)[0]
        for expected in ("assignment-638", "session-builder", "session-reviewer"):
            self.assertIn(expected, handoff)
        lowered = html.lower()
        for forbidden in ("transcript", "chain-of-thought", "cookie=", "password=", "token="):
            self.assertNotIn(forbidden, lowered)
        secret = render("secret")
        self.assertIn("ERROR · Runtime incoherente o inválido.", secret)
        self.assertNotIn("supersecretvalue", secret)

    def test_unknown_or_incoherent_relationships_fail_closed(self):
        html = render("incoherent")
        self.assertIn('data-state="error"', html)
        self.assertIn("ERROR · Runtime incoherente o inválido.", html)
        self.assertNotIn("agent-builder", html)
        self.assertNotIn("assignment-638", html)

        handoff_mismatch = render("handoff_mismatch")
        self.assertIn('data-state="error"', handoff_mismatch)
        self.assertIn("ERROR · Runtime incoherente o inválido.", handoff_mismatch)
        self.assertNotIn("handoff-638", handoff_mismatch)

    def test_loading_empty_error_and_hostile_content_are_explicit_and_escaped(self):
        data = json.loads(render("states"))
        self.assertIn('data-state="loading"', data["loading"])
        self.assertIn("LOADING · Cargando runtime.", data["loading"])
        self.assertIn('data-state="empty"', data["empty"])
        self.assertIn("EMPTY · Sin agentes observados.", data["empty"])
        self.assertIn('data-state="error"', data["error"])
        self.assertNotIn("<script>retry</script>", data["error"])
        self.assertIn("&lt;script&gt;retry&lt;/script&gt;", data["error"])
        ready_empty = render("ready_empty")
        self.assertIn('data-state="empty"', ready_empty)
        self.assertIn('role="status"', ready_empty)
        message_secret = render("message_secret")
        self.assertIn('data-state="error"', message_secret)
        self.assertNotIn("supersecretvalue", message_secret)

    def test_ui_is_mobile_first_accessible_and_read_only(self):
        html = render("ready")
        self.assertIn('name="viewport"', html)
        self.assertIn("grid-template-columns:1fr", html)
        self.assertIn("@media(min-width:760px)", html)
        self.assertIn("prefers-reduced-motion:reduce", html)
        self.assertIn("focus-visible", html)
        lowered = html.lower()
        for forbidden in ("<form", "<button", 'method="post"', "<script"):
            self.assertNotIn(forbidden, lowered)
        source = (ROOT / "src" / "AgentUi.php").read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "file_get_contents(", "mysqli", "pdo(", "dispatchworkflow", "shell_exec(", "proc_open("):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

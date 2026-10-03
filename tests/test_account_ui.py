import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "account_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
        timeout=30,
    )
    return result.stdout


class AccountUiTests(unittest.TestCase):
    def test_accounts_reuse_agent_runtime_provider_and_account_without_parallel_state(self):
        html = render("ready")
        self.assertIn('data-account="account-a"', html)
        self.assertIn('data-account="account-b"', html)
        self.assertLess(html.index('data-account="account-a"'), html.index('data-account="account-b"'))
        for expected in ("chatgpt-web", "claude-web", "autofactory", "Principal", "plus", "rate_limited"):
            self.assertIn(expected, html)
        self.assertIn("Revisión &lt;QA&gt;", html)
        source = (ROOT / "src" / "AccountUi.php").read_text(encoding="utf-8")
        self.assertIn("AgentRuntime::provider", source)
        self.assertIn("AgentRuntime::account", source)
        self.assertNotIn("private const ACCOUNT_STATUSES", source)

    def test_declared_capacity_and_plan_never_render_as_operational_availability(self):
        html = render("ready")
        self.assertIn("Capacidad declarada", html)
        self.assertIn("Plan/configuración", html)
        self.assertIn("La capacidad declarada es configuración.", html)
        lowered = html.lower()
        for forbidden in ("free capacity", "dispatchable", "idle capacity", "healthy sessions", "eligible"):
            self.assertNotIn(forbidden, lowered)

    def test_missing_or_incoherent_provider_fails_closed(self):
        html = render("missing_provider")
        self.assertIn('data-state="error"', html)
        self.assertIn("ERROR · Configuración de cuentas inválida.", html)
        self.assertNotIn("account-b", html)
        self.assertNotIn("missing-provider", html)

    def test_loading_empty_error_and_credential_messages_are_safe(self):
        data = json.loads(render("states"))
        self.assertIn('data-state="loading"', data["loading"])
        self.assertIn("LOADING · Cargando cuentas.", data["loading"])
        self.assertIn('data-state="empty"', data["empty"])
        self.assertIn("EMPTY · Sin cuentas configuradas.", data["empty"])
        self.assertIn('data-state="error"', data["error"])
        self.assertNotIn("<script>retry</script>", data["error"])
        self.assertIn("&lt;script&gt;retry&lt;/script&gt;", data["error"])
        ready_empty = render("ready_empty")
        self.assertIn('data-state="empty"', ready_empty)
        self.assertIn('role="status"', ready_empty)
        self.assertNotIn("chatgpt-web", ready_empty)
        invalid_provider_empty = render("ready_empty_invalid_provider")
        self.assertIn('data-state="error"', invalid_provider_empty)
        secret = render("message_secret")
        self.assertIn('data-state="error"', secret)
        self.assertNotIn("supersecretvalue", secret)

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
        source = (ROOT / "src" / "AccountUi.php").read_text(encoding="utf-8").lower()
        for forbidden in (
            "curl_", "file_get_contents(", "mysqli", "pdo(", "dispatchworkflow",
            "shell_exec(", "proc_open(", "setcookie(", "session_start(",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()

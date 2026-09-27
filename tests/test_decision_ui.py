import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def render(name: str) -> str:
    result = subprocess.run(
        ["php", str(ROOT / "tests/decision_ui_scenarios.php"), name],
        cwd=ROOT,
        check=True,
        text=True,
        capture_output=True,
    )
    return result.stdout


class DecisionUiTests(unittest.TestCase):
    def test_mobile_first_approval_controls(self):
        html = render("ready")
        self.assertIn('name="viewport"', html)
        self.assertIn('content="width=device-width,initial-scale=1,viewport-fit=cover"', html)
        self.assertIn("min-height: 52px", html)
        self.assertIn(":focus-visible", html)
        self.assertIn("@media (prefers-reduced-motion: reduce)", html)
        self.assertIn("@media (min-width: 760px)", html)
        self.assertIn("CONTROLBOT / DECISIONES", html)

    def test_reauth_guard_disables_actions(self):
        html = render("reauth")
        self.assertIn("Reautenticación requerida", html)
        self.assertIn('data-state="reauth-required"', html)
        self.assertGreaterEqual(html.count('disabled aria-disabled="true"'), 2)

    def test_form_targets_approval_endpoint(self):
        html = render("ready")
        self.assertIn('method="post" action="/approvals/execute"', html)
        self.assertIn('name="repository" value="pl0n3r/factory"', html)
        self.assertIn('name="issue" value="114"', html)
        self.assertIn('name="displayed_sha" value="' + ("a" * 40) + '"', html)
        self.assertIn('name="_csrf" value="' + ("c" * 40) + '"', html)
        self.assertIn('name="option" value="A"', html)
        start = html.index('action="/approvals/execute"')
        end = html.index("</form>", start)
        approval_form = html[start:end]
        self.assertNotIn('disabled aria-disabled="true"', approval_form)

    def test_missing_csrf_keeps_actions_disabled(self):
        html = render("no-csrf")
        self.assertIn('data-state="reauth-required"', html)
        self.assertIn('disabled aria-disabled="true"', html)
        self.assertNotIn('name="_csrf"', html)

    def test_empty_state_has_no_fake_decision(self):
        html = render("empty")
        self.assertIn('data-state="empty"', html)
        self.assertIn("Sin decisiones pendientes", html)
        self.assertNotIn("/approvals/execute", html)
        self.assertNotIn("pl0n3r/factory", html)

    def test_low_risk_batch_action_is_mobile_and_explicit(self):
        html = render("batch")
        self.assertIn("Aprobar recomendadas de bajo riesgo", html)
        self.assertIn("pl0n3r/factory #114", html)
        self.assertIn("¿Aplicar cambio seguro?", html)
        self.assertIn(".batch-action:focus-visible", html)
        start = html.index('action="/approvals/batch"')
        end = html.index("</form>", start)
        batch_form = html[start:end]
        self.assertIn('name="_csrf"', batch_form)
        self.assertNotIn('name="repository"', batch_form)
        self.assertNotIn('name="option"', batch_form)

    def test_enriched_card_shows_plain_language_and_impact(self):
        html = render("enriched")
        self.assertIn("¿Publicar la versión 1.0.4?", html)
        self.assertIn("Por qué se recomienda:", html)
        self.assertIn("Si no decides:", html)
        self.assertIn("Trabajo en espera:", html)
        self.assertIn("Riesgo medio", html)
        self.assertIn("Costo: Sin costo adicional", html)
        self.assertIn("Reversible", html)
        self.assertIn("✓ Ventajas", html)
        self.assertIn("✗ Desventajas", html)
        self.assertIn("<summary>Ver detalles técnicos</summary>", html)
        self.assertIn('action="/approvals/execute"', html)

    def test_safe_default_id_uses_friendly_option_label(self):
        html = render("default-id")
        self.assertIn("Si no decides:</strong> Mantener sin publicar", html)
        self.assertNotIn("Si no decides:</strong> B", html)

    def test_zero_optional_copy_is_preserved(self):
        html = render("zero-copy")
        self.assertIn("<h2>0</h2>", html)
        self.assertIn('<p class="context">0</p>', html)

    def test_option_details_are_outside_submit_button(self):
        html = render("enriched")
        button_end = html.index("</button>")
        effect_at = html.index("Publica una versión nueva")
        self.assertLess(button_end, effect_at)
        self.assertIn("overflow-wrap: anywhere", html)

    def test_enriched_fields_escape_untrusted_html(self):
        html = render("enriched-escape")
        self.assertIn("&lt;img src=x onerror=alert(1)&gt;", html)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertNotIn("<script>alert(1)</script>", html)

    def test_invalid_risk_fails_closed(self):
        result = subprocess.run(
            ["php", str(ROOT / "tests/decision_ui_scenarios.php"), "invalid-risk"],
            cwd=ROOT, text=True, capture_output=True,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("onerror=", result.stdout)

    def test_untrusted_copy_is_escaped(self):
        html = render("escape")
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertNotIn("<script>alert(1)</script>", html)

    def test_question_ids_are_unique_across_repositories(self):
        html = render("duplicate-question-ids")
        ids = re.findall(r'id="question-([a-f0-9]{12})"', html)
        labels = re.findall(r'for="question-([a-f0-9]{12})"', html)
        self.assertEqual(len(ids), 2)
        self.assertEqual(len(set(ids)), 2)
        self.assertEqual(sorted(ids), sorted(labels))

    def test_question_content_is_escaped(self):
        result = subprocess.run(
            ["php", str(ROOT / "tests/decision_question_scenarios.php"), "provider-html"],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
        )
        data = json.loads(result.stdout)
        html = data["render"]
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", html)
        self.assertIn("&lt;img src=x onerror=alert(1)&gt; respuesta", html)
        self.assertNotIn("<script>alert(1)</script>", html)
        self.assertNotIn("<img src=x onerror=alert(1)>", html)
        self.assertIn('action="/decisions/question"', html)


if __name__ == "__main__":
    unittest.main()

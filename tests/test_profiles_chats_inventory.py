"""ControlBot #1362: only declarative schema and non-live migration policy."""
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "profiles-chats-inventory.md"
DATA = ROOT / "datos.yml"


def read_doc():
    return DOC.read_text(encoding="utf-8")


def treatment_rows():
    return {t["id"]: t for t in json.loads(DATA.read_text(encoding="utf-8"))["treatments"]}


def reconcile_counts(eligible, inserted, rejected, duplicate):
    if any(not isinstance(v, int) or isinstance(v, bool) or v < 0
           for v in (eligible, inserted, rejected, duplicate)):
        return False
    return eligible == inserted + rejected + duplicate and rejected == duplicate == 0


class ProfilesChatsInventoryTests(unittest.TestCase):
    def test_criterio_principal(self):
        doc = read_doc()
        source = treatment_rows()
        expected = {
            "account_profiles": ("account_alias", "profile_alias", "browser", "plan"),
            "agent_status": ("agent_id", "account_alias", "profile_alias", "tab_id",
                             "status", "last_heartbeat_at", "mode", "repository", "issue_number"),
            "chat_response_opt_in": ("agent_id", "tab_id", "repository", "issue_number",
                                    "question_text", "response_text", "status", "received_at", "opt_in"),
        }
        for category, fields in expected.items():
            with self.subTest(category=category):
                self.assertIn(category, source)
                self.assertTrue(set(fields).issubset(source[category]["fields"]))
                rows = [r for r in doc.splitlines() if r.startswith(f"| `{category}` |")]
                self.assertEqual(len(rows), 1)
                row = rows[0]
                self.assertTrue(all(f"`{name}`" in row for name in fields))
                self.assertIn("`NO_VERIFICADO`", row)
        self.assertIn("**NO_MIGRAR por defecto**", doc)
        self.assertIn("No es una lectura de datos vivos", doc)

    def test_no_confunde_cero_con_desconocido_ni_declara_datos_reales(self):
        doc = read_doc()
        self.assertGreaterEqual(doc.count("NO_VERIFICADO"), 9)
        self.assertIn("nunca significa cero", doc)
        self.assertIn("no se ha copiado ningún mensaje", doc)
        self.assertNotRegex(doc, r"(?im)^\| `(?:account_profiles|agent_status|chat_response_opt_in)` \|[^\n]*\|\s*`?[0-9]+`?\s*\|")
        self.assertNotRegex(doc, r"(?i)gh[pousr]_[A-Za-z0-9]{12,}|sk-[A-Za-z0-9_-]{20,}")
        self.assertNotRegex(doc, r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")

    def test_plan_requiere_autorizacion_backup_y_rollback(self):
        doc = read_doc()
        for item in ("aprobación específica", "read-only", "backup restaurable",
                     "rollback", "NO GO", "reconciliación de filas", "Idempotencia",
                     "consentimiento", "VALIDATED_IN_PRODUCTION", "D-059"):
            with self.subTest(item=item):
                self.assertIn(item, doc)
        self.assertIn("NO EJECUTADO", doc)
        self.assertIn("cero rechazados y duplicados inesperados", doc)

    def test_reconciliacion_sintetica_falla_cerrada(self):
        self.assertTrue(reconcile_counts(3, 3, 0, 0))
        for case in ((3, 2, 0, 0), (3, 2, 1, 0), (3, 2, 0, 1),
                     (-1, 0, 0, 0), (None, 0, 0, 0), (True, 1, 0, 0)):
            with self.subTest(case=case):
                self.assertFalse(reconcile_counts(*case))


if __name__ == "__main__":
    unittest.main()

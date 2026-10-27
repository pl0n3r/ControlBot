"""#973: verificación fail-closed de 49 obligaciones históricas CB-0601..0900."""
from collections import Counter
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/contraste-cb_0601_0900.md"
MATRIX = ROOT / "docs/matriz-cobertura.md"
EXPECTED = frozenset("""CB-0604 CB-0609 CB-0624 CB-0635 CB-0640 CB-0646 CB-0653 CB-0659 CB-0665 CB-0673 CB-0683 CB-0692 CB-0698 CB-0709 CB-0717 CB-0722 CB-0725 CB-0729 CB-0731 CB-0737 CB-0741 CB-0744 CB-0756 CB-0760 CB-0767 CB-0770 CB-0777 CB-0780 CB-0783 CB-0791 CB-0794 CB-0809 CB-0814 CB-0820 CB-0835 CB-0850 CB-0855 CB-0862 CB-0871 CB-0877 CB-0881 CB-0885 CB-0887 CB-0889 CB-0890 CB-0891 CB-0892 CB-0894 CB-0897""".split())
COVERED = {
    "CB-0609": ("src/InfrastructureIntent.php", "tests/infrastructure_intent_scenarios.php", 420, "f13505abd9efb5d66e056bc4771792feaf4b8b7c"),
    "CB-0624": ("src/InfrastructureObservation.php", "tests/infrastructure_observation_scenarios.php", 196, "7d5ac5c268a776b3d0daebed035cd159d6194888"),
    "CB-0635": ("src/InfrastructureCenterUi.php", "tests/infrastructure_ui_scenarios.php", 204, "d96721b764057b824c942fc319785bc3e04d72ae"),
    "CB-0673": ("src/LexWatch.php", "tests/lex_watch_scenarios.php", 180, "b373259659be045e7ea8f0fd76892608c035d846"),
    "CB-0692": ("src/MarketInstitutionSegment.php", "tests/market_institution_segment_scenarios.php", 300, "7009cf3b98bf26fd94cf05f5fccb3952366b7f68"),
    "CB-0709": ("src/MarketScope.php", "tests/market_scope_scenarios.php", 235, "50439b8d04ac4e24db5aa141a5e0d43513511901"),
    "CB-0717": ("src/MarketScopeLifecycle.php", "tests/market_scope_lifecycle_scenarios.php", 239, "fa3be8bc3ab5fda447c25335062d11493114de93"),
    "CB-0725": ("src/MomentumCampaign.php", "tests/momentum_campaign_scenarios.php", 229, "957ccc45be60869a75dabca9b1ef110c173b5f7e"),
    "CB-0737": ("src/MomentumExperiment.php", "tests/momentum_experiment_scenarios.php", 431, "d7ae1360b9ceb4c38eb9d8fd9e0bf8b0edda914f"),
    "CB-0767": ("src/ObservabilityEvent.php", "tests/observability_event_scenarios.php", 361, "9102c3e00bb59c961d8ab482654085ab545a5f91"),
    "CB-0770": ("src/ObservabilityIncident.php", "tests/observability_incident_scenarios.php", 394, "397113d84354dbe2851f25a4eee582b2561d6107"),
    "CB-0780": ("src/ObservabilityProjectStatus.php", "tests/observability_project_status_scenarios.php", 472, "4bc69902a2b1bcfae76a97fd6dd2ccedbc82eaae"),
    "CB-0855": ("src/OwnerInbox.php", "tests/test_owner_inbox.py", 279, "5b04e698f2580de751d6b6c50a91d5f37a80f302"),
    "CB-0862": ("src/PauseControl.php", "tests/pause_control_scenarios.php", 336, "280bd57929c78283743fb392055135072f97cc14"),
    "CB-0871": ("src/PauseProductionGate.php", "tests/pause_production_gate_scenarios.php", 372, "c8e24fd8da3771f16c90be327d1dc0ed6ccaf41b"),
    "CB-0877": ("src/PauseResumePlan.php", "tests/pause_resume_plan_scenarios.php", 387, "be8490e96954528d326bcc7f07189482db2f1a59"),
    "CB-0881": ("src/PauseTransitionAudit.php", "tests/pause_transition_audit_scenarios.php", 376, "c7c8abde3c29721ec8dbf06c130eb584bbefbd01"),
    "CB-0897": ("src/ProductDiscovery.php", "tests/product_discovery_scenarios.php", 301, "fee66e457fad8adf2e4d73803aebcd0c9ce4b34b"),
}

def rows(text, title, columns):
    if text.count(title) != 1:
        raise AssertionError("Sección única de contraste obligatoria")
    section = text.split(title, 1)[1].split("\n## ", 1)[0]
    result = {}
    for line in section.splitlines():
        if not re.match(r"^\| CB-\d{4} \|", line):
            continue
        cells = tuple(c.strip() for c in line.strip("|").split("|"))
        if len(cells) != columns or cells[0] in result:
            raise AssertionError("Fila incompleta o duplicada")
        result[cells[0]] = cells
    return result

def require_missing_issue(row, index):
    if row[3] != "SIN_COBERTURA":
        return
    if not re.search(r"Hoja ejecutable #\d+", row[7]):
        raise AssertionError("Falta hoja canónica")
    if "Hoja ejecutable" not in index[4]:
        raise AssertionError("Falta hoja en índice")
    if re.search(r"/issues/(?:1|45|55|182|561|567|625|750|751|760)\)", index[5]):
        raise AssertionError("Un épico no es hoja ejecutable")

class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.document = DOC.read_text(encoding="utf-8")
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.report_rows = rows(cls.document, "\n## Veredictos\n", 8)
        cls.index = rows(cls.matrix, "\n## Matriz\n", 6)

    def test_cada_fila_tiene_veredicto(self):
        self.assertEqual(set(self.report_rows), EXPECTED)
        self.assertEqual(len(self.report_rows), 49)
        self.assertEqual(Counter(x[3] for x in self.report_rows.values()),
                         {"CUBIERTO": 18, "NO_VERIFICABLE": 31})
        for rid, row in self.report_rows.items():
            with self.subTest(rid=rid):
                self.assertIn(rid, self.index)
                source = re.search(r"(docs/[\w./-]+\.md):L\d+", row[1])
                self.assertIsNotNone(source)
                self.assertTrue((ROOT / source.group(1)).is_file())
                self.assertIn(source.group(1), self.index[rid][1])
                self.assertGreater(len(row[2]), 15)
                self.assertGreater(len(row[7]), 60)
                self.assertIn("UNKNOWN", row[7])
                self.assertEqual(row[3], self.index[rid][3])
        with self.assertRaisesRegex(AssertionError, "duplicada"):
            line = next(x for x in self.document.splitlines() if x.startswith("| CB-0604 |"))
            rows(self.document.replace(line, line + "\n" + line, 1),
                 "\n## Veredictos\n", 8)

    def test_cubierto_enlaza_prueba_existente(self):
        actual = {rid for rid, row in self.report_rows.items() if row[3] == "CUBIERTO"}
        self.assertEqual(actual, set(COVERED))
        for rid, (code, scenario, pr, merge_sha) in COVERED.items():
            with self.subTest(rid=rid):
                row, index = self.report_rows[rid], self.index[rid]
                self.assertTrue((ROOT / code).is_file(), code)
                self.assertTrue((ROOT / scenario).is_file(), scenario)
                self.assertIn(code, row[4])
                self.assertIn(scenario, row[5])
                self.assertIn(f"/pull/{pr}", row[6])
                self.assertIn(merge_sha, row[6])
                self.assertIn(f"Código {code}; prueba {scenario}; PR #{pr} MERGED;", index[4])
                self.assertIn("live=UNKNOWN", index[4])
        for rid, row in self.report_rows.items():
            if rid not in COVERED:
                self.assertEqual(row[3], "NO_VERIFICABLE")
                self.assertEqual(row[6], "—")

    def test_faltante_tiene_issue(self):
        for rid, row in self.report_rows.items():
            require_missing_issue(row, self.index[rid])
        bad = list(self.report_rows["CB-0604"])
        bad[3] = "SIN_COBERTURA"
        with self.assertRaisesRegex(AssertionError, "hoja canónica"):
            require_missing_issue(tuple(bad), self.index["CB-0604"])

    def test_matriz_actualizada(self):
        self.assertIn("Contraste CB-0601..0900 #973", self.matrix)
        self.assertIn("docs/contraste-cb_0601_0900.md", self.matrix)
        self.assertEqual(len(self.index), 215)
        self.assertEqual({rid for rid in self.index if "CB-0601" <= rid <= "CB-0900"}, EXPECTED)
        self.assertIn("Consolidado de 1.194 obligaciones", self.matrix)
        self.assertIn("NO-GO", self.document)
        for rid, row in self.report_rows.items():
            self.assertEqual(row[3], self.index[rid][3])
            if row[3] == "NO_VERIFICABLE":
                self.assertIn("UNKNOWN", self.index[rid][4])

if __name__ == "__main__":
    unittest.main()

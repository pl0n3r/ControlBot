"""CB-0886/0888/0893..0906: 15 contratos offline, sin certificar live.

La matriz permanece NO_VERIFICABLE: solo se comprueban contratos y fixtures
sinteticos. Los textos juridicos no prueban consentimiento ni aprobacion.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php") or "php"
MATRICES = tuple(ROOT / "docs" / name for name in (
    "matriz-cobertura.md", *[f"matriz-cobertura-parte-{i:02}.md" for i in range(1, 5)],
))
IDS = frozenset((
    "CB-0886", "CB-0888", "CB-0893", "CB-0894", "CB-0895", "CB-0896",
    "CB-0898", "CB-0899", "CB-0900", "CB-0901", "CB-0902",
    "CB-0903", "CB-0904", "CB-0905", "CB-0906",
))
UNKNOWN = "UNKNOWN"
LEGAL = {
    "CB-0886": ROOT / "docs/privacidad/aviso-privacidad.md",
    "CB-0888": ROOT / "docs/privacidad/canal-derechos.md",
    "CB-0893": ROOT / "docs/privacidad/terminos-condiciones.md",
}


def rows_from(texts: list[str]) -> dict[str, list[str]]:
    """Exige identidad unica y texto de cada fila; no promueve la matriz."""
    rows: dict[str, list[str]] = {}
    for text in texts:
        for line in text.splitlines():
            if not re.match(r"^\|\s*CB-\d{4}\s*\|", line):
                continue
            fields = [part.strip() for part in line.strip().strip("|").split("|")]
            if fields[0] in IDS:
                if fields[0] in rows:
                    raise AssertionError("fila duplicada: " + fields[0])
                rows[fields[0]] = fields
    return rows


def rows() -> dict[str, list[str]]:
    return rows_from([p.read_text(encoding="utf-8") for p in MATRICES])


def scenario(script: str, case: str, *, env: dict[str, str] | None = None) -> dict:
    result = subprocess.run(
        [PHP, str(ROOT / "tests" / script), case], cwd=ROOT, env=env,
        text=True, capture_output=True, timeout=25, check=False,
    )
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError(f"{script}:{case}: {result.stderr.strip()}")
    return json.loads(result.stdout)


def discovery(case: str) -> dict:
    return scenario("product_discovery_scenarios.php", case)


def assessment(case: str) -> dict:
    return scenario("product_discovery_assessment_scenarios.php", case)


def scope_rejections() -> dict:
    """Reusa fixtures del escenario PHP, cambiando solo el scope esperado."""
    program = r'''
ob_start();
$argv = ['product_discovery_assessment_scenarios.php', 'valid'];
require 'tests/product_discovery_assessment_scenarios.php';
ob_end_clean();
$check = static function (string $venture, string $product): bool {
    try {
        \ControlBot\Business\ProductDiscoveryAssessment::assess(
            assessment(), experiment(), initiative(), hypothesis(), outcome(),
            metric('metric-baseline', 10), metric('metric-variant', 15),
            $venture, $product
        );
        return false;
    } catch (\InvalidArgumentException) { return true; }
};
echo json_encode([
    'venture' => $check('venture-other', 'product-alpha'),
    'product' => $check('venture-alpha', 'product-other'),
], JSON_THROW_ON_ERROR);
'''
    result = subprocess.run([PHP, "-r", program], cwd=ROOT, text=True,
                            capture_output=True, check=False, timeout=25)
    if result.returncode != 0 or result.stderr.strip():
        raise AssertionError(result.stderr.strip())
    return json.loads(result.stdout)


def legal_guard(clause: str, body: str) -> bool:
    """Verifica advertencias obligatorias; jamas equivale a un permiso legal."""
    requirements = {
        "CB-0886": (
            "Estado: borrador técnico generado", "revisión jurídica requerida",
            "casilla no premarcada", "no acredita que exista consentimiento",
            "no autoriza por sí mismo ningún tratamiento", "[COMPLETAR POR EL DUEÑO]",
        ),
        "CB-0888": (
            "Estado: borrador técnico generado", "solicitudes de acceso",
            "rectificación/corrección", "actualización", "portabilidad",
            "supresión/eliminación", "revocación del consentimiento",
            "identidad, legitimación y representación deben verificarse",
            "plazos siguen sujetos a revisión jurídica", "[COMPLETAR POR EL DUEÑO]",
        ),
        "CB-0893": (
            "revisión jurídica requerida antes de publicación",
            "Condiciones comerciales: [COMPLETAR POR EL DUEÑO]",
            "Niveles de servicio (SLA)", "Garantías y limitaciones",
            "Jurisdicción y mecanismo", "no inventa ni presume condiciones",
            "completarse y aprobarse antes de su uso público",
        ),
    }
    return clause in requirements and all(part in body for part in requirements[clause])


class BrechasControlbot58Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: predicado por ID; legal es guard documental, no acreditacion."""
        found = rows()
        self.assertEqual(set(found), IDS)
        notice, rights, terms = (LEGAL[key].read_text(encoding="utf-8") for key in LEGAL)
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        initiative = discovery("initiative")
        hypothesis = discovery("hypothesis")
        freshness = discovery("freshness")
        closed = discovery("closed")
        pure = discovery("pure")
        valid = assessment("valid")
        bindings = assessment("bindings")
        unreliable = assessment("unreliable")
        numeric = assessment("numbers")
        schema = assessment("schema")
        scopes = scope_rejections()
        core = (ROOT / "src/ProductDiscovery.php").read_text(encoding="utf-8").lower()
        assessment_source = (ROOT / "src/ProductDiscoveryAssessment.php").read_text(encoding="utf-8")
        checks = {
            "CB-0886": (legal_guard("CB-0886", notice)
                        and data["phase"] == "construccion"
                        and all(t["consent"] == "review_required" for t in data["treatments"])),
            "CB-0888": legal_guard("CB-0888", rights),
            "CB-0893": legal_guard("CB-0893", terms),
            "CB-0894": (valid["experiment_ref"] == "experiment:" + "c" * 32
                        and valid["outcome_id"] == "outcome-alpha"
                        and numeric["higher"]["comparison"] == "higher"
                        and numeric["higher"]["classification"] == "INVALIDATED"
                        and numeric["lower"]["comparison"] == "lower"
                        and numeric["lower"]["classification"] == "VALIDATED"
                        and "ProductDiscoveryExperiment::plan" in assessment_source
                        and "ProductExperimentOutcome::outcome" in assessment_source),
            "CB-0895": (set(bindings) == {"experiment", "initiative", "hypothesis",
                                                "metric", "outcome", "outcome_experiment", "window"}
                        and all(bindings.values()) and scopes == {"venture": True, "product": True}
                        and valid["evaluation_window"] == {"start_at": 100, "end_at": 200}
                        and valid["venture_id"] == "venture-alpha"
                        and valid["product_id"] == "product-alpha"),
            "CB-0896": (all(unreliable[k] for k in unreliable if k.endswith("_reject"))
                        and all(v == "INCONCLUSIVE" for k, v in unreliable.items()
                                if k.endswith("_ok"))
                        and valid["nature"] == "observed"
                        and valid["freshness"] == "fresh"
                        and valid["confidence"] > 0),
            "CB-0898": (initiative["venture"]["scope"] == "venture"
                        and initiative["venture"]["venture_id"] == "venture-condor"
                        and initiative["group"]["scope"] == "group"
                        and initiative["group"]["venture_id"] is None
                        and initiative["group"]["market_ref"] is None
                        and not initiative["has_repo"]
                        and initiative["bad_group_scope"] and initiative["bad_venture_scope"]
                        and closed["initiative"]["evidence_refs"] == sorted(closed["initiative"]["evidence_refs"])),
            "CB-0899": (hypothesis["good"]["initiative_id"] == "initiative:" + "1" * 32
                        and hypothesis["good"]["expected_outcome_ref"].startswith("outcome:")
                        and hypothesis["good"]["primary_metric_ref"].startswith("metric:")
                        and hypothesis["missing_outcome"] and hypothesis["missing_metric"]
                        and hypothesis["wrong_initiative"]
                        and closed["hypothesis"]["constraint_refs"] == sorted(closed["hypothesis"]["constraint_refs"])
                        and closed["duplicate_constraints"] and closed["extra_hypothesis"]),
            "CB-0900": (freshness["unknown"]["confidence"] == "unknown"
                        and freshness["stale"]["confidence"] == "medium"
                        and "['low','medium','high','unknown']" in core),
            "CB-0901": (freshness["unknown"]["freshness"] == "unknown"
                        and freshness["unknown_high"] and freshness["unknown_medium_hypothesis"]),
            "CB-0902": (freshness["stale"]["freshness"] == "stale"
                        and freshness["stale_high"]),
            "CB-0903": (not initiative["has_repo"] and initiative["group"]["evidence_refs"] == []
                        and bool(hypothesis["good"]["evidence_refs"])
                        and closed["duplicate_evidence"]),
            "CB-0904": (initiative["group"]["market_ref"] is None
                        and initiative["venture"]["market_ref"].startswith("market:")
                        and "marketscopec" not in core
                        and "market_scope" not in core),
            "CB-0905": (pure["methods"] == ["hypothesis", "initiative"]
                        and all(forbidden not in core for forbidden in (
                            "curl_", "http://", "https://", "pdo(", "mysqli", "factoryrunner",
                            "scheduler", "workitem", "queue(", "execute(", "experimentrunner"))),
            "CB-0906": (all((ROOT / "tests" / path).is_file() for path in (
                            "product_discovery_scenarios.php", "test_product_discovery.py"))
                        and all(discovery(case) for case in (
                            "initiative", "hypothesis", "freshness", "closed", "pure"))
                        and len(re.findall(r"(?m)^    def test_", (ROOT / "tests/test_product_discovery.py")
                                               .read_text(encoding="utf-8"))) == 5),
        }
        self.assertEqual(set(checks), IDS)
        for clause, passed in checks.items():
            with self.subTest(clause=clause):
                self.assertTrue(bool(passed), "falta contraste offline: " + clause)
                self.assertEqual(found[clause][3], "NO_VERIFICABLE")

    def test_falla_cerrado(self) -> None:
        """AC-02: falta, tamper, identidad, duplicados y evidencias invalidas."""
        original = [p.read_text(encoding="utf-8") for p in MATRICES]
        found = rows_from(original)
        chosen = "CB-0886"
        target = next(text for text in original if f"| {chosen} |" in text)
        line = next(line for line in target.splitlines() if line.startswith(f"| {chosen} |"))
        self.assertNotEqual(set(rows_from([text.replace(line, "") for text in original])), IDS)
        with self.assertRaisesRegex(AssertionError, "fila duplicada"):
            rows_from(original + [line])
        self.assertEqual(set(found), IDS)
        for key, path in LEGAL.items():
            original_body = path.read_text(encoding="utf-8")
            self.assertTrue(legal_guard(key, original_body))
            self.assertFalse(legal_guard(key, ""))
            self.assertFalse(legal_guard(key, original_body.replace("[COMPLETAR POR EL DUEÑO]", "", 1)
                  if key != "CB-0893" else "revisión jurídica requerida antes de publicación")
        for key in ("bad_group_scope", "bad_venture_scope"):
            self.assertTrue(discovery("initiative")[key])
        for key in ("missing_outcome", "missing_metric", "wrong_initiative"):
            self.assertTrue(discovery("hypothesis")[key])
        for key in ("extra", "bad_ref", "duplicate_evidence", "bad_scope",
                    "duplicate_constraints", "extra_hypothesis"):
            self.assertTrue(discovery("closed")[key])
        for key in ("unknown_class", "empty_rule", "empty_evidence"):
            self.assertTrue(assessment("classification")[key])
        for key in ("duplicate", "extra", "bad_ref", "sensitive_text", "direct_pii_text"):
            self.assertTrue(assessment("schema")[key])

    def test_sin_datos_reales(self) -> None:
        """AC-03: sin red, logs secretos, PII ni llamadas externas desde fixtures."""
        with tempfile.TemporaryDirectory(prefix="controlbot-1065-synthetic-") as directory:
            location = Path(directory)
            bin_path = location / "bin"
            bin_path.mkdir()
            sentinel = location / "external-invoked"
            for command in ("curl", "wget", "ssh", "scp", "git", "gh", "hostinger"):
                fake = bin_path / command
                fake.write_text('#!/bin/sh\necho invoked > "' + str(sentinel) + '"\nexit 95\n', encoding="utf-8")
                fake.chmod(0o700)
            env = os.environ.copy()
            env["PATH"] = str(bin_path) + os.pathsep + env.get("PATH", "")
            env["TEST_SYNTHETIC_SECRET"] = "secret-not-for-output-1065"
            for script, case in (("product_discovery_scenarios.php", "closed"),
                                 ("product_discovery_assessment_scenarios.php", "schema")):
                payload = scenario(script, case, env=env)
                encoded = json.dumps(payload)
                self.assertNotIn("secret-not-for-output-1065", encoded)
                self.assertNotIn("@", encoded)
                self.assertFalse(sentinel.exists())
        self.assertEqual(set(LEGAL), {"CB-0886", "CB-0888", "CB-0893"})

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no certificar consentimiento, validacion juridica ni live."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(data["phase"], "construccion")
        self.assertTrue(data["d063_attestation"]["nothing_live"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])
        for clause, cells in rows().items():
            with self.subTest(clause=clause):
                self.assertEqual(cells[3], "NO_VERIFICABLE")
                self.assertIn(UNKNOWN, cells[4].upper())
        for clause, path in LEGAL.items():
            with self.subTest(clause=clause):
                self.assertTrue(legal_guard(clause, path.read_text(encoding="utf-8")))
        for case in ("stale_ok", "inferred_ok", "unknown_ok", "zero_confidence_ok",
                     "hypothesis_stale_ok", "hypothesis_unknown_ok"):
            self.assertEqual(assessment("unreliable")[case], "INCONCLUSIVE")


if __name__ == "__main__":
    unittest.main()

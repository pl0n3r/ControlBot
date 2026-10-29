"""#1230: pruebas reales PHP observability con datos sintéticos; sin red/live."""
from __future__ import annotations
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIOS = {
    "policy": "observability_policy_router_scenarios.php",
    "projects": "observability_project_status_scenarios.php",
    "ui": "observability_ui_scenarios.php",
}
OFFLINE = (
    "CB-0777", "CB-0778", "CB-0779", "CB-0781", "CB-0782",
    "CB-0783", "CB-0784", "CB-0785", "CB-0786", "CB-0787",
    "CB-0788", "CB-0789", "CB-0790",
)


def php(*args: str) -> str:
    proc = subprocess.run(
        ["php", *args], cwd=ROOT, text=True, capture_output=True,
        timeout=60, check=False,
    )
    if proc.returncode:
        raise AssertionError(
            f"PHP exit={proc.returncode}: {proc.stderr[-900:]}"
        )
    if proc.stderr:
        raise AssertionError(f"Unexpected PHP stderr: {proc.stderr[-900:]}")
    return proc.stdout


def run(source: str, case: str, *, raw: bool = False):
    output = php(str(ROOT / "tests" / SCENARIOS[source]), case)
    return output if raw else json.loads(output)


def invalid_policy_version() -> dict:
    """Usa fixtures PHP existentes y ejecuta ObservabilityPolicyRouter real."""
    fixture = (ROOT / "tests/observability_policy_router_scenarios.php").read_text(
        encoding="utf-8"
    )
    prefix, separator, _ = fixture.partition("$case=$argv[1]??'';")
    if not separator or not prefix.startswith("<?php"):
        raise AssertionError("PHP fixture contract unexpectedly changed")
    prefix = prefix.removeprefix("<?php").replace(
        "__DIR__.'/../src/", "'" + (ROOT / "src").as_posix() + "/"
    )
    script = prefix + """
$out=[
  'bad_version'=>bad(fn()=>project(inc('critical'),policy(['version'=>2]))),
  'wrong_scope'=>bad(fn()=>project(inc('critical'),policy(['project_scope_id'=>'wrong']))),
  'missing_policy_ref'=>bad(fn()=>project(inc('critical'),policy(['policy_ref'=>'']))),
];
echo json_encode($out,JSON_THROW_ON_ERROR);
"""
    return json.loads(php("-r", script))


class CoberturaControlbot47Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: ejecuta contratos PHP; ninguna aserción lee matrices."""
        freeze = run("policy", "freeze")
        blocked = run("policy", "blocked")
        policy_version = invalid_policy_version()
        missing = run("projects", "missing-stale")
        projects = {p["project_id"]: p["sources"] for p in missing["projects"]}
        full = run("ui", "complete", raw=True)
        absent = run("ui", "missing", raw=True)
        orphan = run("ui", "orphan_restore", raw=True)
        invalid_restore = run("ui", "invalid_restore", raw=True)
        alert = run("ui", "alert", raw=True)
        pure = run("ui", "pure")
        redaction = run("ui", "redaction")
        cases = {
            "CB-0777": (
                freeze["freeze"]["state"] == "unknown"
                and freeze["write"]["pause_blocked"] is True
                and freeze["write"]["pause_allows"] is False
                and freeze["read"]["pause_allows"] is True
            ),
            "CB-0778": blocked["stale"] is None and blocked["unknown"] is None,
            "CB-0779": all(policy_version.values()) and blocked["scope_mismatch"],
            "CB-0781": (
                projects["project-controlbot"]["ci"]["freshness"] == "unknown"
                and projects["project-controlbot"]["ci"]["severity"] == "unknown"
                and projects["project-condor"]["health"]["freshness"] == "unknown"
            ),
            "CB-0782": (
                projects["project-controlbot"]["health"]["freshness"] == "stale"
                and projects["project-controlbot"]["health"]["freshness"] != "fresh"
            ),
            "CB-0783": (
                "Vista read-only" in full and "Último probe" in full
                and "Último backup" in full and "Último restore probado" in full
                and pure["hits"] == []
            ),
            "CB-0784": (
                "backup:controlbot-001" in full
                and "1048576 bytes" in full and "kms-managed" in full
            ),
            "CB-0785": (
                "restore:controlbot-001" in full
                and "restore-lab" in full
                and "Restorable</dt><dd>sí" in full
                and "Restorable</dt><dd>no" in invalid_restore
                and "Sin restore drill verificado." in invalid_restore
            ),
            "CB-0786": (
                pure["hits"] == [] and not pure["form"]
                and not pure["button"] and not pure["input"]
            ),
            "CB-0787": (
                "Monitor: healthy" in full and "HTTP</dt><dd>200" in full
                and "Latencia</dt><dd>42 ms" in full
                and "1.4.0" in full and "bbbbbbbbbbbb" in full
                and "backup:controlbot-001" in full
                and "restore:controlbot-001" in full
            ),
            "CB-0788": (
                "Monitor: unknown" in absent
                and "Sin evidencia de probe." in absent
                and "Sin evidencia de backup." in absent
                and "Restorable</dt><dd>no" in absent
                and "Sin evidencia de backup." in orphan
                and "Sin restore drill verificado." in orphan
            ),
            "CB-0789": (
                'name="viewport"' in full
                and ":focus-visible" in full
                and "prefers-reduced-motion" in full
                and "aria-labelledby=" in full
                and not any((pure["form"], pure["button"], pure["input"]))
                and "Requerida</dt><dd>sí" in alert
            ),
            "CB-0790": pure["hits"] == [] and "Vista read-only" in full,
        }
        self.assertEqual(set(cases), set(OFFLINE))
        for row, valid in cases.items():
            with self.subTest(row=row):
                self.assertTrue(valid, f"{row} actual PHP behavior regressed")
        self.assertTrue(all(redaction.values()))

    def test_falla_cerrado(self) -> None:
        """AC-02: scope/version, entrada ambigua, stale y restore inválido."""
        denied = run("policy", "blocked")
        for key in ("stale", "unknown", "disabled", "resolved"):
            with self.subTest(freeze=key):
                self.assertIsNone(denied[key])
        self.assertTrue(denied["bad_provenance"])
        self.assertTrue(denied["scope_mismatch"])
        self.assertTrue(all(invalid_policy_version().values()))
        self.assertTrue(all(run("policy", "invalid").values()))
        self.assertTrue(all(run("projects", "invalid").values()))
        self.assertTrue(run("projects", "ambiguous"))
        no_restore = run("ui", "no_restore", raw=True)
        mismatch = run("ui", "invalid_restore", raw=True)
        unknown = run("ui", "unknown_probe", raw=True)
        for html in (no_restore, mismatch):
            self.assertIn("Restorable</dt><dd>no", html)
            self.assertNotIn("Restorable</dt><dd>sí", html)
        self.assertIn("restore_evidence_invalid", mismatch)
        self.assertIn("Monitor: unknown", unknown)
        self.assertIn("Freshness</dt><dd>unknown", unknown)

    def test_sin_datos_reales(self) -> None:
        """AC-03: sólo PHP puro, fixtures y minimización."""
        conf = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(conf["phase"], "construccion")
        self.assertTrue(conf["d063_attestation"]["nothing_live"])
        self.assertTrue(conf["d063_attestation"]["no_real_customer_data"])
        for source in ("policy", "ui"):
            self.assertEqual(run(source, "pure")["hits"], [])
        self.assertTrue(all(run("ui", "redaction").values()))
        html = run("ui", "complete", raw=True)
        for forbidden in (
            "vault:controlbot/backup-001", "evidence:restore/controlbot-001",
            "a" * 64, "<form", "<button", "<input",
        ):
            self.assertNotIn(forbidden, html)
        self.assertEqual(
            run("projects", "order")["a"], run("projects", "order")["b"]
        )


if __name__ == "__main__":
    unittest.main()

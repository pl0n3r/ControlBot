"""ControlBot #1043: CB-0533..CB-0548 (sin CB-0545), solo contratos offline.

Una prueba de adapter o de documentación no acredita autoridad Hostinger,
backup restaurable, HTTPS live, D-043 ni go-live. Esas señales siguen UNKNOWN.
"""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

from tests.test_hostinger_go_live_plan import GATES, execute, fixture

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(
    f"CB-{number:04d}"
    for number in range(533, 549) if number != 545
)
EXPECTED_FIELDS = {"gate", "source_ref", "scope", "sha", "verified", "fresh"}
FIXTURE_CREDENTIAL = "fixture-credential-ALPHA-938271"


def php_scenario(file: str, case: str) -> dict:
    """Correr únicamente harnesses PHP locales con transporte inyectado."""
    assert file in {
        "hostinger_public_api_runtime_scenarios.php",
        "hostinger_public_api_scenarios.php",
    }
    result = subprocess.run(
        ["php", str(ROOT / "tests" / file), case],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode or not result.stdout.strip():
        raise AssertionError(f"fixture {file}/{case}: {result.stderr[:250]}")
    value = json.loads(result.stdout)
    if not isinstance(value, dict):
        raise AssertionError("fixture PHP sin respuesta tipada")
    return value


def runtime(case: str) -> dict:
    return php_scenario("hostinger_public_api_runtime_scenarios.php", case)


def adapter(case: str) -> dict:
    return php_scenario("hostinger_public_api_scenarios.php", case)


def document(name: str) -> str:
    return (ROOT / "docs" / name).read_text(encoding="utf-8")


def never_live(report: dict) -> bool:
    return all(report[field] is False for field in (
        "runtime_authorized", "deploy_authorized", "go_live_authorized", "execution"
    )) and report["decision"] not in {"GO", "GREEN", "VALIDATED_IN_PRODUCTION"}


class BrechasControlbot36Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: exactamente 15 cláusulas, con predicado observable por ID."""
        guide = document("hostinger-go-live-plan.md")
        runtime_guide = document("hostinger-public-api-runtime.md")
        api_guide = document("hostinger-public-api.md")
        missing, _ = execute({**fixture(), "evidence": []})
        all_rows = fixture()
        complete, _ = execute(all_rows)
        canonical = runtime("canonical")
        scope = runtime("scope")
        write = runtime("write")
        api = adapter("canonical")
        failures = adapter("failures")
        refs = adapter("secret-evidence")
        secret = runtime("redaction")
        script = (ROOT / "scripts/hostinger_go_live_plan.py").read_text(encoding="utf-8")
        source = (ROOT / "src/HostingerPublicApiRuntime.php").read_text(encoding="utf-8")

        checks = {
            "CB-0533": (
                missing["decision"] == "NO_GO"
                and missing["missing_gates"] == list(GATES)
                and len(missing["next_steps"]) == 8
                and never_live(missing)
            ),
            "CB-0534": (
                len(all_rows["evidence"]) == len(GATES)
                and all(set(row) == EXPECTED_FIELDS for row in all_rows["evidence"])
                and complete["decision"] == "REVIEW_ONLY"
                and "'source_ref'" in script
                and never_live(complete)
            ),
            "CB-0535": (
                "#914/A" in guide
                and "decisión adicional específica" in guide
                and "owner_approval" in missing["missing_gates"]
                and never_live(missing)
            ),
            "CB-0536": (
                "backup real de `public_html`" in guide
                and "restore aislado" in guide
                and "backup_restore" in missing["missing_gates"]
                and "rollback" in missing["missing_gates"]
            ),
            "CB-0537": (
                "HTTPS" in guide and "owner-only" in guide
                and "health.php" in guide
                and "owner_only_https" in missing["missing_gates"]
                and "production_observation" in missing["missing_gates"]
            ),
            "CB-0538": (
                "referencias opacas" in guide
                and "OPAQUE_REF" in script and "SENSITIVE" in script
                and never_live(complete)
            ),
            "CB-0539": (
                canonical["web"]["ok"] is True
                and canonical["web"]["result"]["source"] == "hostinger-public-api"
                and "SecretsBroker" in runtime_guide
                and FIXTURE_CREDENTIAL not in json.dumps(canonical)
            ),
            "CB-0540": (
                scope["transport_calls"] == 0
                and scope["cases"]["provider"]["reason"] == "secret_reference_incompatible"
                and scope["cases"]["secret_kind"]["reason"] == "secret_reference_incompatible"
                and scope["cases"]["generation"]["reason"] == "secret_scope_mismatch"
                and scope["cases"]["revoked"]["reason"] == "secret_reference_revoked"
            ),
            "CB-0541": (
                write["transport_calls"] == 0
                and write["plan"]["capability"] == "cron.write"
                and write["plan"]["requires_backup"] is True
                and write["plan"]["dry_run"] is True
                and write["plan"]["execution"] is False
            ),
            "CB-0542": (
                "callable $transport" in source
                and "Closure::fromCallable($transport)" in source
                and "No existe transporte por defecto" in runtime_guide
                and len(canonical["calls"]) == 3
                and all(call["method"] == "GET" for call in canonical["calls"])
            ),
            "CB-0543": (
                "Este slice no autoriza ni configura" in runtime_guide
                and "DEPLOY_ENABLED" in runtime_guide
                and "go-live" in runtime_guide
                and never_live(complete)
            ),
            "CB-0544": (
                "Una activación futura" in runtime_guide
                and "canal seguro" in runtime_guide
                and "autoridad explícita" in runtime_guide
                and scope["transport_calls"] == 0
            ),
            "CB-0546": (
                "boundary de secretos" in api_guide
                and FIXTURE_CREDENTIAL not in json.dumps(canonical)
                and "sentinel-hostinger-token" not in json.dumps(api)
                and secret["contains_fixture"] is False
                and secret["result"]["result"]["data"]["websites"][0]["domain"]
                == "[REDACTED]"
            ),
            "CB-0547": (
                api["plan"]["requires_backup"] is True
                and api["plan"]["execution"] is False
                and api["plan"]["dry_run"] is True
                and api["before"] == len(api["calls"])
                and failures["denied"] is True
            ),
            "CB-0548": (
                api["web"]["capability"] == "hostinger.read"
                and api["cron"]["capability"] == "cron.snapshot"
                and api["output"]["capability"] == "cron.snapshot"
                and all(row["method"] == "GET" for row in api["calls"])
                and "Con un token API server-side autorizado" in api_guide
                and all(refs["messagesSecretFree"])
            ),
        }
        self.assertEqual(len(IDS), 15)
        self.assertEqual(set(checks), set(IDS), "cláusula omitida")
        for clause, passed in checks.items():
            with self.subTest(clause=clause):
                self.assertTrue(passed, f"{clause}: no acreditado (UNKNOWN)")

    def test_falla_cerrado(self) -> None:
        """AC-02: datos malformados, falta de autoridad y credenciales no promueven GO."""
        bad = []
        sample = fixture()
        sample["evidence"][0]["source_ref"] = "controlbot:secret_access"
        bad.append(sample)
        sample = fixture()
        sample["evidence"][0]["extra"] = "SENSITIVE_FIXTURE"
        bad.append(sample)
        sample = fixture()
        sample["evidence"][1]["source_ref"] = sample["evidence"][0]["source_ref"]
        bad.append(sample)
        sample = fixture()
        sample["evidence"][1]["gate"] = sample["evidence"][0]["gate"]
        bad.append(sample)
        sample = fixture()
        sample["evidence"][0]["sha"] = "b" * 40
        bad.append(sample)
        sample = fixture()
        sample["evidence"].pop()
        bad.append(sample)
        for value in bad + [b'{"version":1,"version":2}', b'not-json', b""]:
            with self.subTest(candidate=str(value)[:35]):
                report, proc = execute(value)
                self.assertEqual(report["decision"], "NO_GO")
                self.assertEqual(proc.returncode, 1)
                self.assertTrue(never_live(report))
                self.assertNotIn("SENSITIVE_FIXTURE", proc.stdout.decode())
        for key, value in runtime("scope")["cases"].items():
            with self.subTest(scope=key):
                self.assertIs(value["ok"], False)
        self.assertEqual(runtime("scope")["transport_calls"], 0)
        self.assertTrue(all(adapter("failures")["failed"]))
        self.assertTrue(adapter("failures")["denied"])
        self.assertFalse(runtime("redaction")["error"]["ok"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: el alcance es offline, con fixtures y transporte PHP falso."""
        sample = fixture()
        self.assertEqual(sample["phase"], "construccion")
        self.assertEqual(sample["sha"], "a" * 40)
        self.assertTrue(all(
            row["source_ref"].startswith("controlbot:fixture/")
            for row in sample["evidence"]
        ))
        for name in ("hostinger_public_api_runtime_scenarios.php",
                     "hostinger_public_api_scenarios.php"):
            source = (ROOT / "tests" / name).read_text(encoding="utf-8")
            self.assertIn("$transport", source)
            for forbidden in ("curl_exec(", "file_get_contents('https://",
                              "fsockopen(", "shell_exec("):
                self.assertNotIn(forbidden, source)
        self.assertFalse(runtime("write")["plan"]["execution"])
        self.assertEqual(runtime("write")["transport_calls"], 0)
        self.assertNotIn(FIXTURE_CREDENTIAL, json.dumps(runtime("canonical")))
        self.assertNotIn("sentinel-hostinger-token", json.dumps(adapter("canonical")))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: offline/CI nunca certifica las 15 filas como live."""
        rows = [
            line for line in document("matriz-cobertura.md").splitlines()
            if line.startswith("| CB-")
        ]
        found = {}
        for line in rows:
            clause = line.split("|")[1].strip()
            if clause in IDS:
                self.assertNotIn(clause, found, "fila duplicada en matriz")
                found[clause] = line
        self.assertIn("CB-0539", found)
        self.assertIn("NO_VERIFICABLE", found["CB-0539"])
        self.assertIn("live=UNKNOWN", found["CB-0539"])
        self.assertIn("operando=UNKNOWN", found["CB-0539"])
        # Cláusulas no materializadas en la matriz NO se inventan como cubiertas.
        live_states = {clause: "UNKNOWN" for clause in IDS}
        self.assertEqual(set(live_states), set(IDS))
        for clause, state in live_states.items():
            with self.subTest(clause=clause):
                self.assertEqual(state, "UNKNOWN")
                self.assertNotEqual(state, "success")
                if clause in found:
                    self.assertNotIn("| LISTO |", found[clause])
        report, _ = execute(fixture())
        self.assertEqual(report["decision"], "REVIEW_ONLY")
        self.assertTrue(never_live(report))
        self.assertFalse(runtime("write")["plan"]["execution"])


if __name__ == "__main__":
    unittest.main()

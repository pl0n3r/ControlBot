"""ControlBot #1216: exercise actual offline Hostinger release and API logic.

Live operation, production authorization, DNS and real backups remain UNKNOWN.
No matrix reading and no real network/credentials.
"""
from __future__ import annotations
import json
import subprocess
import unittest
from pathlib import Path
from tests.test_hostinger_go_live_plan import GATES, execute, fixture

ROOT = Path(__file__).resolve().parents[1]
PHP = {
    "runtime": "hostinger_public_api_runtime_scenarios.php",
    "adapter": "hostinger_public_api_scenarios.php",
}
OFFLINE_IDS = (
    "CB-0533", "CB-0534", "CB-0539", "CB-0540",
    "CB-0541", "CB-0542", "CB-0546", "CB-0547",
)


def scenario(source: str, case: str) -> dict:
    """Existing scenario calls real PHP classes with injected fake transport."""
    result = subprocess.run(
        ["php", str(ROOT / "tests" / PHP[source]), case], cwd=ROOT,
        capture_output=True, text=True, check=False, timeout=30,
    )
    if result.returncode:
        raise AssertionError(
            f"PHP {source}/{case} exited {result.returncode}: {result.stderr[:180]}"
        )
    value = json.loads(result.stdout)
    if not isinstance(value, dict):
        raise AssertionError("PHP scenario did not return an object")
    return value


def no_go_live(report: dict) -> bool:
    return (
        report["decision"] in {"NO_GO", "REVIEW_ONLY"}
        and all(report[key] is False for key in (
            "runtime_authorized", "deploy_authorized",
            "go_live_authorized", "execution",
        ))
    )


class CoberturaControlbot33Tests(unittest.TestCase):
    def test_contrato_principal(self):
        """AC-01: eight offline rows call real product code, not documentation."""
        missing, mproc = execute({**fixture(), "evidence": []})
        present, pproc = execute(fixture())
        runtime = scenario("runtime", "canonical")
        scope = scenario("runtime", "scope")
        write = scenario("runtime", "write")
        secret = scenario("runtime", "redaction")
        adapter = scenario("adapter", "canonical")
        errors = scenario("adapter", "failures")
        cases = {
            "CB-0533": (
                mproc.returncode == 1 and missing["decision"] == "NO_GO"
                and missing["missing_gates"] == list(GATES)
                and set(missing["next_steps"]) == set(GATES)
                and no_go_live(missing)
            ),
            "CB-0534": (
                pproc.returncode == 0 and present["decision"] == "REVIEW_ONLY"
                and present["missing_gates"] == []
                and all(s == "PRESENT_FOR_MANUAL_REVIEW"
                        for s in present["gate_status"].values())
                and no_go_live(present)
            ),
            "CB-0539": (
                runtime["web"]["ok"] is True
                and runtime["web"]["result"]["source"] == "hostinger-public-api"
                and runtime["web"]["result"]["data"]["websites"][0]["domain"]
                == "control.example.test"
                and len(runtime["calls"]) == 3
            ),
            "CB-0540": (
                scope["transport_calls"] == 0
                and all(d["ok"] is False for d in scope["cases"].values())
                and scope["cases"]["provider"]["reason"]
                == "secret_reference_incompatible"
                and scope["cases"]["secret_kind"]["reason"]
                == "secret_reference_incompatible"
                and scope["cases"]["revoked"]["reason"]
                == "secret_reference_revoked"
            ),
            "CB-0541": (
                write["transport_calls"] == 0
                and write["plan"]["capability"] == "cron.write"
                and write["plan"]["requires_backup"] is True
                and write["plan"]["dry_run"] is True
                and write["plan"]["execution"] is False
            ),
            "CB-0542": (
                len(runtime["calls"]) == 3
                and all(d["method"] == "GET" and d["authorization_present"]
                        and d["url"].startswith("https://developers.hostinger.com/api/")
                        for d in runtime["calls"])
            ),
            "CB-0546": (
                secret["contains_fixture"] is False
                and secret["result"]["result"]["data"]["websites"][0]["domain"]
                == "[REDACTED]"
                and secret["error"]["ok"] is False
                and "[REDACTED]" in secret["error"]["error"]
            ),
            "CB-0547": (
                adapter["plan"]["requires_backup"] is True
                and adapter["plan"]["execution"] is False
                and adapter["plan"]["dry_run"] is True
                and adapter["before"] == len(adapter["calls"])
                and errors["denied"] is True
            ),
        }
        self.assertEqual(set(cases), set(OFFLINE_IDS))
        for name, passed in cases.items():
            with self.subTest(clause=name):
                self.assertTrue(passed, f"{name} actual behavior regressed")

    def test_falla_cerrado(self):
        """AC-02: missing, invalid, duplicated, unauthorized and secret input."""
        cases = []
        d = fixture()
        d["evidence"].pop()
        cases.append(d)
        d = fixture()
        d["evidence"][0]["source_ref"] = "controlbot:secret_access"
        cases.append(d)
        d = fixture()
        d["evidence"][0]["sha"] = "b" * 40
        cases.append(d)
        d = fixture()
        d["evidence"][1]["gate"] = d["evidence"][0]["gate"]
        cases.append(d)
        d = fixture()
        d["evidence"][0]["unexpected"] = "SYNTHETIC_SHOULD_NOT_ECHO"
        cases.append(d)
        for payload in cases + [b'{"version":1,"version":2}', b'not-json', b'']:
            with self.subTest(type=type(payload).__name__):
                report, proc = execute(payload)
                self.assertEqual(proc.returncode, 1)
                self.assertEqual(report["decision"], "NO_GO")
                self.assertTrue(no_go_live(report))
                self.assertNotIn("SYNTHETIC_SHOULD_NOT_ECHO", proc.stdout.decode())
        scope = scenario("runtime", "scope")
        self.assertEqual(scope["transport_calls"], 0)
        for name in ("capability", "project", "environment", "generation"):
            self.assertEqual(scope["cases"][name]["reason"], "secret_scope_mismatch")
        errors = scenario("adapter", "failures")
        self.assertTrue(all(errors["failed"]))
        self.assertTrue(errors["denied"])

    def test_sin_datos_reales(self):
        """AC-03: synthetic only, sanitized output and zero write transports."""
        f = fixture()
        self.assertEqual(f["phase"], "construccion")
        self.assertEqual(f["sha"], "a" * 40)
        self.assertEqual(len(f["evidence"]), len(GATES))
        self.assertTrue(all(e["source_ref"].startswith("controlbot:fixture/")
                            for e in f["evidence"]))
        self.assertEqual(scenario("runtime", "write")["transport_calls"], 0)
        secret = scenario("runtime", "redaction")
        self.assertFalse(secret["contains_fixture"])
        self.assertNotIn("fixture-credential-ALPHA-938271", json.dumps(secret))
        self.assertNotIn(
            "fixture-credential-ALPHA-938271",
            json.dumps(scenario("runtime", "canonical")),
        )
        self.assertNotIn(
            "sentinel-hostinger-token", json.dumps(scenario("adapter", "canonical"))
        )
        self.assertTrue(no_go_live(execute(fixture())[0]))


if __name__ == "__main__":
    unittest.main()

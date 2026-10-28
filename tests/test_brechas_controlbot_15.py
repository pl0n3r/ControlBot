#!/usr/bin/env python3
"""ControlBot #1022: contraste offline CB-0212..CB-0226 con fixtures sintéticos.

Los escenarios PHP verifican contratos locales, no permisos, go-live ni producción.
La ausencia de evidencia operativa continúa siendo UNKNOWN.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
FIXTURES = {
    "read": "external_api_authenticated_read_scenarios.php",
    "work": "external_api_directed_work_origin_scenarios.php",
    "mobile": "external_api_mobile_state_scenarios.php",
}
IDS = {f"CB-{n:04d}" for n in range(212, 227)}
UNKNOWN = "UNKNOWN"
MATRICES = (
    ROOT / "docs" / "matriz-cobertura.md",
    ROOT / "docs" / "matriz-cobertura-parte-01.md",
)


@lru_cache(maxsize=48)
def scenario(domain: str, name: str) -> dict:
    """Ejecuta exclusivamente el harness PHP local de la allowlist."""
    if domain not in FIXTURES:
        raise AssertionError("Harness no permitido")
    script = ROOT / "tests" / FIXTURES[domain]
    completed = subprocess.run(
        ["php", str(script), name], cwd=ROOT, capture_output=True,
        text=True, timeout=35, check=False,
    )
    if completed.returncode:
        raise AssertionError(
            f"{domain}/{name}: {completed.stderr[:300]} "
            f"(exit={completed.returncode})"
        )
    result = json.loads(completed.stdout)
    if not isinstance(result, dict):
        raise AssertionError(f"{domain}/{name}: salida no estructurada")
    return result


def operational_state(_clause: str, _offline: object = None) -> str:
    """Un test aislado jamás acredita autorización ni estado de producción."""
    return UNKNOWN


def matrix_rows() -> dict[str, list[str]]:
    rows = {clause: [] for clause in IDS}
    for file in MATRICES:
        for row in file.read_text(encoding="utf-8").splitlines():
            for clause in IDS:
                if row.startswith(f"| {clause} |"):
                    rows[clause].append(row)
    return rows


class BrechasControlbot15Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: quince predicados independientes de comportamiento observable."""
        cockpit = scenario("read", "cockpit")
        inbox = scenario("read", "inbox")
        read_invalid = scenario("read", "invalid")
        read_shape = scenario("read", "shape")
        read_pure = scenario("read", "pure")
        client = scenario("work", "client")
        approve = scenario("work", "approve")
        reject = scenario("work", "reject")
        policy = scenario("work", "policy")
        retry = scenario("work", "retry")
        states = scenario("mobile", "states")
        reads = scenario("mobile", "reads")
        mutations = scenario("mobile", "mutation")
        item = approve["work_item"]
        denied = reject["result"]
        required = {
            "work_id", "origin_mode", "origin_system", "group_id",
            "work_type", "requested_capabilities", "required_roles",
            "authority_level", "producer_ref", "priority_class",
            "depends_on", "claims", "policy_ref", "approval_ref",
            "evidence_refs", "idempotency_key", "observed_at",
        }
        verdicts = {
            "CB-0212": (
                cockpit["data"]["ventures"][0]["venture_ref"]
                == "controlbot:venture/alpha"
                and inbox["data"]["entries"][0]["kind"] == "DECISION"
                and set(read_shape["cockpit"]) == {"meta", "data"}
                and set(read_shape["inbox"]) == {"meta", "data"}
            ),
            "CB-0213": (
                read_pure["methods"] == ["cockpit", "ownerInbox"]
                and "ExternalApiReadProjection::cockpit(" in read_pure["source"]
                and "ExternalApiReadProjection::ownerInbox(" in read_pure["source"]
                and set(read_shape["cockpit"]["data"]) == {"ventures"}
                and set(read_shape["inbox"]["data"]) == {"entries"}
            ),
            "CB-0214": (
                all(read_invalid[key] is True for key in
                    ("wrong_scope", "revoked", "wrong_capability"))
                and "ExternalApiRequestGate::authorize(" in read_pure["source"]
                and "operation_id" in read_pure["source"]
                and "mutation" in read_pure["source"]
            ),
            "CB-0215": (
                all(client[key] is True for key in
                    ("authority", "policy", "work"))
                and item["origin_mode"] == "directed"
            ),
            "CB-0216": (
                item["authority_level"] == "l4_owner"
                and item["producer_ref"].startswith("controlbot:identity/")
                and item["requested_capabilities"] == ["php"]
                and item["group_id"] == "pl0n3r-group"
                and item["policy_ref"] == "controlbot:policy/external-owner-v1"
                and client["authority"] and client["policy"]
            ),
            "CB-0217": (
                denied["decision"]["outcome"] == "reject"
                and denied["audit"]["mutation"]["outcome"] == "mutation_rejected"
                and denied["audit"]["factory_handoff"] is None
                and denied["work_item"] is None
                and denied["work_item_ref"] is None
                and reject["with_work"] is True
            ),
            "CB-0218": (
                policy["unverified_policy"] is True
                and item["policy_ref"] == "controlbot:policy/external-owner-v1"
            ),
            "CB-0219": (
                approve["decision"]["approval_ref"]
                == retry["first"]["decision"]["approval_ref"]
                == retry["second"]["decision"]["approval_ref"]
                and retry["first"]["work_item_ref"]
                == retry["second"]["work_item_ref"]
                and retry["first"]["work_item"]["idempotency_key"]
                == retry["second"]["work_item"]["idempotency_key"]
                and retry["first"]["work_item"]["work_id"]
                == retry["second"]["work_item"]["work_id"]
            ),
            "CB-0220": (
                required.issubset(item)
                and item["origin_mode"] == "directed"
                and item["origin_system"] == "human"
                and item["required_roles"] == ["ingenieria-software", "qa"]
                and item["claims"] == ["src/mobile-owner-handoff"]
                and approve["audit"]["factory_handoff"]["outcome"]
                == "factory_handoff"
            ),
            "CB-0221": (
                {key: states[key]["state"] for key in
                 ("fresh", "stale", "offline", "unknown")}
                == {"fresh": "fresh", "stale": "stale",
                    "offline": "offline", "unknown": "unknown"}
                and mutations["unknown"]["freshness_gate"] == "fail"
                and mutations["unknown"]["queueable"] is False
            ),
            "CB-0222": (
                states["stale"]["age_seconds"] == 401
                and states["stale"]["max_age_seconds"] == 300
                and states["stale"]["current"] is False
            ),
            "CB-0223": (
                states["offline"]["state"] == "offline"
                and states["offline"]["age_seconds"] == 200
                and states["offline"]["source_ref"]
                == "controlbot:cockpit/group-health"
                and states["offline"]["current"] is False
            ),
            "CB-0224": (
                states["unknown"]["state"] == "unknown"
                and states["unknown"]["age_seconds"] is None
                and states["unknown"]["current"] is False
                and states["unknown"]["source_ref"] is None
            ),
            "CB-0225": (
                states["partial"] is True
                and all(states["unknown"][key] is None for key in
                        ("observed_at", "received_at", "source_ref"))
            ),
            "CB-0226": (
                all(reads[key]["state"] == key for key in ("stale", "offline"))
                and all(reads[key]["current"] is False for key in
                        ("stale", "offline"))
                and all(reads[key]["observed_at"] == 1000 for key in
                        ("stale", "offline"))
                and all(reads[key]["source_ref"]
                        == "controlbot:cockpit/group-health" for key in
                        ("stale", "offline"))
                and reads["stale"]["age_seconds"] == 401
                and reads["offline"]["age_seconds"] == 200
            ),
        }
        self.assertEqual(set(verdicts), IDS)
        for clause, passed in sorted(verdicts.items()):
            with self.subTest(clause=clause):
                self.assertTrue(passed, f"{clause}: brecha sin evidencia; UNKNOWN")

    def test_falla_cerrado(self) -> None:
        """AC-02: scope, credenciales, policy y freshness no se infieren."""
        invalid = scenario("read", "invalid")
        client = scenario("work", "client")
        work = scenario("work", "authorized")
        policy = scenario("work", "policy")
        states = scenario("mobile", "states")
        mutations = scenario("mobile", "mutation")
        for domain, cases in {
            "read": invalid,
            "client": client,
            "policy": policy,
        }.items():
            for name, denied in cases.items():
                with self.subTest(domain=domain, case=name):
                    self.assertIs(denied, True)
        for key in ("no_step", "wrong_scope", "revoked"):
            with self.subTest(case=key):
                self.assertIs(work[key], True)
        self.assertIs(states["partial"], True)
        for key in ("stale", "offline_stepup", "offline_high", "unknown"):
            with self.subTest(mutation=key):
                self.assertEqual(mutations[key]["freshness_gate"], "fail")
        self.assertFalse(mutations["offline_stepup"]["queueable"])
        self.assertFalse(mutations["offline_high"]["queueable"])
        self.assertFalse(mutations["unknown"]["queueable"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: entradas sintéticas, sin red ni credenciales de clientes."""
        for file in FIXTURES.values():
            self.assertTrue((ROOT / "tests" / file).is_file())
        samples = (
            scenario("read", "shape"),
            scenario("work", "approve"),
            scenario("work", "reject"),
            scenario("mobile", "states"),
            scenario("mobile", "reads"),
        )
        for index, sample in enumerate(samples):
            with self.subTest(sample=index):
                serialized = json.dumps(sample, sort_keys=True).lower()
                for sensitive in (
                    "bearer ", "github_pat_", "ghp_", "sk-live",
                    "password=", "api_key=", "access_token", "refresh_token",
                    "personal_email", "phone_number", "transcript",
                    "customer_name", "external_dispatch", "executed_at",
                ):
                    self.assertNotIn(sensitive, serialized)
        self.assertIsNone(scenario("work", "reject")["result"]["work_item"])
        self.assertIsNone(scenario("mobile", "states")["unknown"]["source_ref"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: matriz íntegra y ausencia de evidencia live = UNKNOWN."""
        rows = matrix_rows()
        self.assertEqual(set(rows), IDS)
        for clause, matches in sorted(rows.items()):
            with self.subTest(clause=clause):
                self.assertEqual(len(matches), 1, f"{clause}: fila ausente o duplicada")
                self.assertTrue(matches[0].startswith(f"| {clause} |"))
                self.assertEqual(operational_state(clause, matches[0]), UNKNOWN)
        unknown = scenario("mobile", "states")["unknown"]
        self.assertEqual(unknown["state"], "unknown")
        self.assertIsNone(unknown["age_seconds"])
        self.assertFalse(unknown["current"])
        self.assertEqual(
            scenario("read", "shape")["cockpit"]["meta"]["freshness"],
            {"state": "unknown", "observed_at": None, "source_ref": None},
        )


if __name__ == "__main__":
    unittest.main()

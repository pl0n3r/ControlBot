"""#1026: regresiones CB-0272..CB-0286 con fixtures PHP sintéticos.

Verifican contratos offline; nunca atestiguan login real, autorización live,
mitigaciones productivas, ejecución remota ni estado de operación.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import subprocess
import unittest

from test_matriz_cobertura import credible_offline_coverage_row

ROOT = Path(__file__).resolve().parents[1]
MATRICES = (
    ROOT / "docs" / "matriz-cobertura.md",
    ROOT / "docs" / "matriz-cobertura-parte-01.md",
)
UNKNOWN = "UNKNOWN"
IDS = tuple(f"CB-{number:04d}" for number in range(272, 287))
FIXTURES = {
    "source": "external_api_session_source_scenarios.php",
    "session": "external_api_session_scenarios.php",
    "access": "external_api_access_scenarios.php",
    "contract": "external_api_contract_scenarios.php",
}


@lru_cache(maxsize=None)
def scenario(fixture: str, mode: str) -> dict:
    """Only existing local PHP fixtures; never network or live providers."""
    if fixture not in FIXTURES:
        raise AssertionError("Fixture no autorizado")
    completed = subprocess.run(
        ["php", str(ROOT / "tests" / FIXTURES[fixture]), mode],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=40,
    )
    if completed.returncode:
        raise AssertionError(
            f"Escenario {fixture}/{mode}: {completed.stderr[:300]}"
        )
    return json.loads(completed.stdout)


def evidence_text() -> str:
    return "\n".join(path.read_text(encoding="utf-8") for path in MATRICES)


def matrix_states(text: str) -> dict[str, str]:
    """A missing, duplicated, malformed or promoted row is UNKNOWN."""
    states = {}
    for clause in IDS:
        rows = [line for line in text.splitlines()
                if line.startswith(f"| {clause} |")]
        state = UNKNOWN
        if len(rows) == 1:
            cells = [cell.strip() for cell in rows[0].strip("|").split("|")]
            if (len(cells) == 6 and cells[0] == clause
                    and credible_offline_coverage_row(cells)):
                state = cells[3]
        states[clause] = state
    return states


def operating_state(_clause: str, _fixture: object = None) -> str:
    """An offline result cannot attest external auth, live or production."""
    return UNKNOWN


class BrechasControlbot19Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: a distinct executable predicate for all fifteen IDs."""
        from_source = scenario("source", "source")
        mint = scenario("source", "mint")
        source_bad = scenario("source", "fail_closed")
        binding = scenario("session", "binding")
        lifetime = scenario("session", "lifetime")
        step = scenario("session", "stepup")
        inventory = scenario("session", "inventory")
        nominal = scenario("access", "nominal")
        owner = scenario("access", "owner_read")
        mismatch = scenario("access", "mismatch")
        mutation = scenario("access", "mutation")
        surface = scenario("access", "surface")
        contract = scenario("contract", "catalog")
        request = scenario("contract", "mutation")
        envelopes = scenario("contract", "envelopes")
        source_summary = from_source["summary"]
        session = binding["session"]
        device = binding["device"]
        safe_inventory = inventory["inventory"]
        access_source = surface["source"]
        verdicts = {
            "CB-0272": (from_source["calls"] == 1
                         and source_summary["freshness"] == "fresh"
                         and source_summary["observed_at"] == 6995
                         and all(source_bad.values())),
            "CB-0273": (mint["constructor_private"] is True
                         and mint["serialize_rejected"] is True
                         and mint["public_methods"] == ["__serialize", "__unserialize",
                             "device", "fromSource", "safeSummary", "session", "stepUp"]
                         and source_summary["identity_id"] == "identity-owner"
                         and source_summary["scope"] == "venture:alpha"
                         and not any(k in source_summary for k in
                             ("decision", "capability", "authority_level", "policy_ref"))),
            "CB-0274": (nominal["raw_rejected"] is True
                         and nominal["valid"]["decision"] == "allow"
                         and scenario("session", "pure")["methods"] ==
                             ["device", "safeInventory", "session", "stepUp"]
                         and "TokenVault" not in access_source),
            "CB-0275": (device["identity_id"] == session["identity_id"]
                         and device["registered_at"] == 1000
                         and session["issued_at"] == 1100
                         and session["expires_at"] == 2000
                         and lifetime["revoked_device"] is True
                         and lifetime["revoked_session"] is True
                         and lifetime["expired"] is True),
            "CB-0276": (session["device_ref"] == device["device_ref"]
                         and session["scope"] == "venture:alpha"
                         and all(binding[key] is True for key in
                             ("wrong_identity", "wrong_scope", "wrong_device"))),
            "CB-0277": (step["valid"]["method"] == "passkey"
                         and step["valid"]["session_ref"] == session["session_ref"]
                         and step["valid"]["device_ref"] == device["device_ref"]
                         and step["valid"]["expires_at"] - step["valid"]["verified_at"] <= 300
                         and all(step[key] is True for key in
                             ("too_long", "expired", "wrong_session", "wrong_device",
                              "wrong_identity", "revoked_device", "revoked_step_up"))),
            "CB-0278": (safe_inventory["identity_id"] == "owner-human"
                         and len(safe_inventory["devices"]) == 2
                         and len(safe_inventory["sessions"]) == 2
                         and all(inventory["bad"].values())
                         and not any(k in json.dumps(safe_inventory).lower() for k in
                             ("access_token", "refresh_token", "cookie", "otp",
                              "secret", "credential", "private_key", "public_key"))),
            "CB-0279": (surface["public"] == ["authorize"]
                         and mutation["decision"] == "step_up_required"
                         and mutation["reasons"] == ["verified_step_up_required"]
                         and "OwnerSessionService" not in access_source),
            "CB-0280": (nominal["raw_rejected"] is True
                         and mismatch["capability"]["decision"] == "deny"
                         and "capability_not_granted" in mismatch["capability"]["reasons"]
                         and all(request["server_fields_rejected"].values())
                         and contract["GET /api/v1/cockpit"]["auth_scope"] ==
                             "owner.cockpit.read"),
            "CB-0281": (mismatch["scope"]["decision"] == "deny"
                         and mismatch["scope"]["reasons"] ==
                             ["resource_scope_mismatch"]),
            "CB-0282": (mismatch["policy_mint_rejected"] is True
                         and mismatch["identity_mint_rejected"] is True
                         and owner["owner"]["decision"] == "allow"
                         and all(owner[k]["decision"] == "deny"
                             for k in ("lower", "non_owner_role", "agent"))),
            "CB-0283": (len(contract) == 4
                         and sum(bool(route["mutation"]) for route in contract.values()) == 1
                         and all(not route["mutation"] for name, route in contract.items()
                             if name.startswith("GET "))
                         and mutation["mutation"] is True
                         and mutation["decision"] == "step_up_required"),
            "CB-0284": (mutation["decision"] == "step_up_required"
                         and nominal["raw_rejected"] is True
                         and "OwnerSessionService" not in access_source
                         and "TokenVault" not in access_source),
            "CB-0285": (envelopes["unknown"]["freshness"] ==
                             {"state": "unknown", "observed_at": None, "source_ref": None}
                         and mutation["decision"] == "step_up_required"
                         and source_bad["stale"] is True
                         and source_bad["unknown"] is True),
            "CB-0286": (surface["public"] == ["authorize"]
                         and all(banned not in access_source for banned in
                             ("FactoryRunner", "WorkItem", "shell_exec", "curl_",
                              "mysqli", "PDO(", "dispatchWorkflow", "ssh2_"))),
        }
        self.assertEqual(set(verdicts), set(IDS))
        states = matrix_states(evidence_text())
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertTrue(verdicts[clause], f"Brecha sin contraste: {clause}")
                self.assertIn(states[clause], ("NO_VERIFICABLE", "CUBIERTO"))
                self.assertEqual(operating_state(clause, verdicts[clause]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: negative PHP paths and evidence anomalies never succeed."""
        for failure, rejected in scenario("source", "fail_closed").items():
            with self.subTest(failure=failure):
                self.assertIs(rejected, True)
        for fixture, mode, keys in (
            ("session", "binding", ("wrong_identity", "wrong_scope", "wrong_device")),
            ("session", "lifetime", ("too_long", "expired", "revoked_session", "revoked_device")),
            ("session", "stepup", ("too_long", "expired", "wrong_session", "wrong_device",
                                    "wrong_identity", "revoked_device", "revoked_step_up")),
        ):
            response = scenario(fixture, mode)
            for key in keys:
                with self.subTest(fixture=fixture, invalid=key):
                    self.assertIs(response[key], True)
        text = evidence_text()
        for clause in IDS:
            row = next(line for line in text.splitlines()
                       if line.startswith(f"| {clause} |"))
            for corrupted in (
                text.replace(row + "\n", "", 1),
                text + "\n" + row,
                text.replace(row, (row.replace("MERGED", "PENDING", 1) if "CUBIERTO" in row
                                 else row.replace("NO_VERIFICABLE", "CUBIERTO", 1)), 1),
            ):
                with self.subTest(clause=clause):
                    self.assertEqual(matrix_states(corrupted)[clause], UNKNOWN)
        self.assertEqual(scenario("access", "mutation")["decision"], "step_up_required")
        self.assertTrue(scenario("contract", "mutation")["missing_idempotency_rejected"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: local, allowlisted scenarios with synthetic IDs and no credentials."""
        for path in FIXTURES.values():
            self.assertTrue((ROOT / "tests" / path).is_file())
        safe = (
            scenario("source", "source")["summary"],
            scenario("source", "secrets")["summary"],
            scenario("session", "inventory")["inventory"],
            scenario("contract", "envelopes"),
        )
        for item in safe:
            payload = json.dumps(item, sort_keys=True).lower()
            for secret in ("password", "access_token", "refresh_token", "cookie",
                           "private_key", "public_key", "otp", "credential", "api_key"):
                with self.subTest(secret=secret):
                    self.assertNotIn(secret, payload)
        self.assertTrue(all(scenario("source", "secrets")["bad"].values()))
        self.assertEqual(scenario("source", "source")["calls"], 1)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no offline run or successful CI implies verified production."""
        states = matrix_states(evidence_text())
        self.assertEqual(set(states), set(IDS))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertIn(states[clause], ("NO_VERIFICABLE", "CUBIERTO"))
                for assertion in (None, {}, True, "success", {"ci": "success"},
                                  {"owner": "approved"}, {"production": "claimed"}):
                    self.assertEqual(operating_state(clause, assertion), UNKNOWN)
        self.assertTrue(scenario("source", "fail_closed")["stale"])
        self.assertTrue(scenario("source", "fail_closed")["unknown"])
        self.assertEqual(scenario("contract", "envelopes")["unknown"]["freshness"],
                         {"state": "unknown", "observed_at": None, "source_ref": None})
        self.assertEqual(scenario("access", "mutation")["decision"], "step_up_required")


if __name__ == "__main__":
    unittest.main()

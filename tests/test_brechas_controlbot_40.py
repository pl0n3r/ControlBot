"""ControlBot #1047: 15 offline contracts, never evidence of live operations.

Exercise existing PHP fixtures using synthetic data. Operational authority,
provider execution, verified backups and deployment remain UNKNOWN.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = (
    "CB-0597", "CB-0598", "CB-0599", "CB-0600", "CB-0601",
    "CB-0602", "CB-0603", "CB-0604", "CB-0605", "CB-0606",
    "CB-0607", "CB-0608", "CB-0610", "CB-0611", "CB-0612",
)
MATRIX_PARTS = (
    "docs/matriz-cobertura-parte-02.md",
    "docs/matriz-cobertura-parte-03.md",
)
FIXTURES = (
    "infrastructure_inventory_scenarios.php",
    "infrastructure_control_center_scenarios.php",
    "infrastructure_intent_scenarios.php",
    "capability_policy_scenarios.php",
)
UNKNOWN = "UNKNOWN"


@lru_cache(maxsize=None)
def scenario(file: str, mode: str | None = None) -> dict:
    """Allow only checked-in, in-memory PHP scenarios; never call real providers."""
    if file not in FIXTURES:
        raise AssertionError("Scenario not allowed")
    command = ["php", str(ROOT / "tests" / file)]
    if mode is not None:
        command.append(mode)
    run = subprocess.run(command, cwd=ROOT, text=True, capture_output=True,
                         check=False, timeout=40)
    if run.returncode:
        raise AssertionError(f"{file}/{mode}: {run.stderr[:250]}")
    return json.loads(run.stdout)


def matrix_states(contents: tuple[str, ...]) -> dict[str, str]:
    """Missing, duplicated and prematurely promoted evidence stays UNKNOWN."""
    rows = [row for content in contents for row in content.splitlines()
            if row.startswith("| CB-")]
    states = {}
    for clause in IDS:
        found = [row for row in rows if row.startswith(f"| {clause} |")]
        state = UNKNOWN
        if len(found) == 1:
            cells = [cell.strip() for cell in found[0].strip("|").split("|")]
            if (len(cells) == 6 and cells[0] == clause
                    and cells[3] == "NO_VERIFICABLE"
                    and "UNKNOWN" in cells[4]):
                state = "NO_VERIFICABLE"
        states[clause] = state
    return states


def operational_state(_clause: str, _offline: object = None) -> str:
    """No unit fixture or local CI result establishes production authority."""
    return UNKNOWN


class BrechasControlbot40Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: one exercised assertion for every exact matrix clause."""
        inv = "infrastructure_inventory_scenarios.php"
        intent = "infrastructure_intent_scenarios.php"
        providers = scenario(inv, "multi-provider")
        resources = scenario(inv, "typed-resources")
        deterministic = scenario(inv, "deterministic")
        e2e = scenario("infrastructure_control_center_scenarios.php")
        safe = scenario(intent, "safe")
        gates = scenario(intent, "gates")
        provenance = scenario(intent, "provenance")
        mutation = scenario(intent, "mutation")
        runner = scenario(intent, "runner")
        cap_restricted = scenario("capability_policy_scenarios.php", "unknown")
        cap_read = scenario("capability_policy_scenarios.php", "read")
        primary = next(row for row in providers["providers"]
                       if row["provider_id"] == "provider-primary")
        database = next(row for row in resources
                        if row["resource_id"] == "database-primary")
        provider_php = (ROOT / "src/InfrastructureProvider.php").read_text()
        capability_block = re.search(r"private const CAPABILITIES = \[(.*?)\];",
                                     provider_php, re.S)
        self.assertIsNotNone(capability_block)
        allowed = set(re.findall(r"'([^']+)'", capability_block.group(1)))
        expected_capabilities = {
            "inventory.read", "health.read", "deploy.read", "backup.read",
            "backup.write", "database.read", "storage.read", "dns.read",
            "dns.write", "certificate.read", "network.read", "cost.read",
        }
        stale = e2e["non_fresh"]
        verdicts = {
            "CB-0597": (
                database["backup_refs"] == ["controlbot:backup/db-primary-2000"]
                and database["cost_ref"] == "controlbot:cost/project-controlbot"
                and "cost_amount" not in database and "backup_content" not in database),
            "CB-0598": (
                primary["kind"] == "hosting" and primary["vendor"] == "vendor-one"
                and primary["adapter_ref"].startswith("controlbot:")
                and primary["capabilities"] == [
                    {"capability": "health.read", "scopes": ["environment", "resource"]},
                    {"capability": "inventory.read", "scopes": ["project", "provider"]},
                ]),
            "CB-0599": allowed == expected_capabilities,
            "CB-0600": all(scenario(inv, mode)["blocked"] for mode in (
                "unknown-provider-kind", "unknown-capability", "unknown-scope",
                "unknown-resource-kind")),
            "CB-0601": all(scenario(inv, mode)["blocked"] for mode in (
                "provider-secret-ref", "secret-ref", "secret-id", "unknown-field",
                "duplicate-provider", "duplicate-resource", "invalid-ref")),
            "CB-0602": (e2e["inventory_owns_resources"] is True
                        and e2e["authority_denied"]["status"] == "denied"
                        and e2e["authority_denied"]["runner_request"] is None),
            "CB-0603": (deterministic["first"] == deterministic["second"]
                        and [r["resource_id"] for r in deterministic["first"]]
                        == ["service-web", "storage-media"]),
            "CB-0604": (e2e["action"]["execution"] is False
                        and e2e["action"]["status"] == "planned"
                        and e2e["plan"]["work_item"] is not None
                        and e2e["plan"]["runner_request"] is not None),
            "CB-0605": (safe["status"] == "planned"
                        and safe["authority"]["decision"] == "allow"
                        and safe["authority"]["policy"]["decision"] == "automatic"
                        and safe["work_item"]["work_id"]
                        == safe["runner_request"]["work_item_id"]),
            "CB-0606": (e2e["completed"]["state"] == "completed"
                        and e2e["completed"]["evidence"]["code"] == "verified"
                        and e2e["order"]["work_item_id"]
                        == e2e["plan"]["work_item"]["work_id"]),
            "CB-0607": (provenance["fabricated_array_rejected"] is True
                        and provenance["copied_object_rejected"] is True
                        and provenance["current_deny_status"] == "denied"
                        and e2e["owner"]["runner_request"] is None),
            "CB-0608": (all(stale[state]["effective"] == "unknown"
                                and stale[state]["route"]["runner_request"] is None
                                for state in ("stale", "unknown"))
                        and "financial_evidence_unknown" in e2e["budget_denied"]["reasons"]
                        and e2e["secret_instruction_rejected"] is True
                        and e2e["secret_evidence_rejected"] is True),
            "CB-0610": (runner["safe"]["work_item"]["work_id"]
                        == runner["safe"]["runner_request"]["work_item_id"]
                        == runner["order"]["work_item_id"]
                        and "runner_id" not in runner["safe"]["runner_request"]
                        and runner["order"]["runner_id"]
                        == "33333333-3333-7333-8333-333333333333"),
            "CB-0611": (provenance["trusted_allow"]["status"] == "planned"
                        and provenance["scope_mismatch"]["status"] == "denied"
                        and provenance["trusted_owner"]["runner_request"] is None
                        and gates["unknown"]["status"] == "denied"),
            "CB-0612": (cap_read["policy"]["decision"] == "automatic"
                        and cap_restricted["restricted"]["decision"] == "owner_required"
                        and cap_restricted["unknown"]["decision"] == "forbidden"
                        and mutation["weak_capability"]["status"] == "denied"
                        and mutation["readonly_write"]["status"] == "denied"
                        and mutation["valid"]["authority"]["policy"]["requires_backup"]
                        and mutation["missing_backup"]["status"] == "denied"),
        }
        self.assertEqual(set(verdicts), set(IDS))
        contents = tuple((ROOT / part).read_text(encoding="utf-8")
                         for part in MATRIX_PARTS)
        states = matrix_states(contents)
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertTrue(verdicts[clause], f"Missing behavior for {clause}")
                self.assertEqual(states[clause], "NO_VERIFICABLE")
                self.assertEqual(operational_state(clause, verdicts[clause]), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: malformed source evidence and unsafe inputs never allow execution."""
        parts = tuple((ROOT / part).read_text(encoding="utf-8")
                      for part in MATRIX_PARTS)
        for clause in IDS:
            index = 0 if int(clause[-4:]) <= 600 else 1
            row = next(line for line in parts[index].splitlines()
                       if line.startswith(f"| {clause} |"))
            for changed in (
                parts[index].replace(row + "\n", "", 1),
                parts[index] + "\n" + row,
                parts[index].replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
            ):
                modified = list(parts)
                modified[index] = changed
                with self.subTest(clause=clause):
                    self.assertEqual(matrix_states(tuple(modified))[clause], UNKNOWN)
        inv = "infrastructure_inventory_scenarios.php"
        for mode in ("unknown-provider-kind", "unknown-capability", "unknown-scope",
                     "unknown-resource-kind", "duplicate-provider", "duplicate-resource",
                     "provider-secret-ref", "secret-ref", "secret-id"):
            self.assertTrue(scenario(inv, mode)["blocked"], mode)
        gates = scenario("infrastructure_intent_scenarios.php", "gates")
        for key in ("unknown", "scope_mismatch", "deny_owner"):
            self.assertEqual(gates[key]["status"], "denied")
            self.assertIsNone(gates[key]["runner_request"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixtures use synthetic IDs, read-only data and no provider calls."""
        for fixture in FIXTURES:
            source = (ROOT / "tests" / fixture).read_text(encoding="utf-8")
            for forbidden in ("curl_init(", "ssh2_", "Guzzle", "Aws\\"):
                self.assertNotIn(forbidden, source)
        e2e = scenario("infrastructure_control_center_scenarios.php")
        self.assertEqual(e2e["plan"]["work_item"]["origin_system"], "controlbot")
        self.assertFalse(e2e["action"]["execution"])
        self.assertTrue(e2e["secret_instruction_rejected"])
        self.assertTrue(e2e["secret_evidence_rejected"])
        serialized = json.dumps(e2e["plan"]).lower()
        for value in ("bearer ", "github_pat_", "private key", "password"):
            self.assertNotIn(value, serialized)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local evidence is not a shortcut around exact-HEAD/live gates."""
        parts = tuple((ROOT / part).read_text(encoding="utf-8")
                      for part in MATRIX_PARTS)
        states = matrix_states(parts)
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(states[clause], "NO_VERIFICABLE")
                for claim in (None, {}, True, "success", "green", {"CI": "success"},
                              {"approval": "granted"}, {"production": "claimed"}):
                    self.assertEqual(operational_state(clause, claim), UNKNOWN)
        e2e = scenario("infrastructure_control_center_scenarios.php")
        for state in ("stale", "unknown"):
            self.assertEqual(e2e["non_fresh"][state]["effective"], "unknown")
            self.assertIsNone(e2e["non_fresh"][state]["route"]["order"])
        self.assertIsNone(e2e["budget_denied"]["runner_request"])


if __name__ == "__main__":
    unittest.main()

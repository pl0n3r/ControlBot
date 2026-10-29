#!/usr/bin/env python3
"""ControlBot #1208: executable offline PHP coverage, CB-0397..CB-0414.

Only authentic PHP behavior can count as coverage. Test-only grants and
presentation fixtures are never runtime GitHub authority. No matrix-state
assertions: reconciliation belongs to a separate issue.
"""
from __future__ import annotations

import base64
import json
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ALL_IDS = {f"CB-{number:04d}" for number in range(397, 415)}
# External attestation and decisions/documentation do not become code coverage.
NON_EXECUTABLE = {
    "CB-0402": ("C", "V1 out-of-scope and forbidden production capabilities"),
    "CB-0403": ("B", "Authenticated server configuration, snapshot provenance and trusted clock"),
    "CB-0410": ("C", "Preview out-of-scope nonimplementation and deployment boundaries"),
    "CB-0411": ("C", "Owner #873/A authorization provenance; a fixture cannot attest its signature"),
    "CB-0414": ("C", "Future #763 owner-gate, V3 and deployment requirements"),
}
OFFLINE = ALL_IDS - set(NON_EXECUTABLE)
ACTIONS = {
    "issue.create": "github.issue.write",
    "issue.update": "github.issue.write",
    "issue.close": "github.issue.write",
    "issue.reserve": "github.issue.write",
    "issue.release": "github.issue.write",
    "pr.review": "github.pr.review",
    "pr.merge": "github.pr.merge",
    "release.approve": "github.release.approve",
    "workflow.dispatch": "github.workflow.dispatch",
    "project.freeze": "github.project.write",
    "project.unfreeze": "github.project.write",
}
FIXTURE_KEY = bytes(range(32, 64)).hex()  # Public deterministic test bytes.


def _php(args: list[str]) -> dict:
    executable = shutil.which("php")
    if not executable:
        raise AssertionError("UNKNOWN: real PHP CLI unavailable")
    run = subprocess.run(
        [executable, "-d", "allow_url_fopen=0", *args],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if run.returncode != 0:
        raise AssertionError("UNKNOWN: PHP CLI failed: " + run.stderr[-400:])
    try:
        result = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP scenario emitted invalid JSON") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: PHP result not an object")
    return result


@lru_cache(maxsize=32)
def scenario(component: str, case: str) -> dict:
    # All paths are a closed catalog, never derived from caller input.
    fixtures = {
        "policy": "github_intent_policy_scenarios.php",
        "preview": "github_intent_preview_scenarios.php",
        "authority": "github_intent_authority_requirements_scenarios.php",
    }
    if component not in fixtures:
        raise AssertionError("Unknown PHP fixture")
    return _php([str(ROOT / "tests" / fixtures[component]), case])


def grant_candidate(action: str = "workflow.dispatch") -> dict:
    if action not in ACTIONS:
        raise AssertionError("Unknown test action")
    scope = "project" if action.startswith("project.") else "repository"
    project = "controlbot:project/controlbot"
    repository = "pl0n3r/ControlBot"
    ref = project if scope == "project" else repository
    return {
        "requirements": {
            "version": 1, "state": "requirements", "type": action,
            "capability": ACTIONS[action], "scope": scope, "scope_ref": ref,
            "scope_parameters": {scope + "_ref": ref},
            "approval_class": "owner_required", "authorized": False,
            "grant_issued": False, "approval_issued": False,
            "approval_verified": False, "execution": False,
        },
        "approval": {
            "origin": "server", "test_only": True, "owner": "pl0n3r",
            "owner_verified": True, "action_type": action, "scope_ref": ref,
            "approved": True, "approved_at": 1040,
            "approval_ref": "fixture:" + "ab" * 8, "revoked": False,
        },
        "trusted": {
            "origin": "server", "mode": "offline_test_only",
            "issuer": "controlbot:authority", "owner": "pl0n3r",
            "project_ref": project, "repository_ref": repository,
            "nonce": "cd" * 16, "ttl_seconds": 120,
        },
        "seen": [], "revoked": [], "now": 1100, "key": FIXTURE_KEY,
    }


def grant(case: dict) -> dict:
    encoded = base64.b64encode(json.dumps(case, sort_keys=True).encode()).decode()
    return _php([str(ROOT / "tests" /
                     "github_intent_test_grant_authority_scenarios.php"), encoded])


class CoberturaControlbot25Tests(unittest.TestCase):
    """Real PHP output, synthetic negative inputs, and explicit limitations."""

    def assert_not_runtime(self, result: dict) -> None:
        self.assertEqual("deny", result["decision"])
        for key in ("execution", "authorized", "grant_issued",
                    "approval_issued", "approval_verified", "runtime_authorized"):
            self.assertIs(result[key], False)

    def test_contrato_principal(self) -> None:
        """AC-01: 13 executable clauses; 5 B/C limits without fake coverage."""
        self.assertEqual(18, len(ALL_IDS))
        self.assertEqual(13, len(OFFLINE))
        self.assertFalse(OFFLINE & set(NON_EXECUTABLE))
        for category, reason in NON_EXECUTABLE.values():
            self.assertIn(category, {"B", "C", "D"})
            self.assertGreater(len(reason), 30)

        proved = set()
        # CB-0397: normalized envelope, offline policy, never GitHub write.
        reuse = scenario("policy", "reuse")
        self.assertEqual({"foreign", "foreign_owner", "missing"}, set(reuse))
        for result in reuse.values():
            self.assertEqual({"decision": "deny",
                              "reasons": ["untrusted_evidence_freshness"],
                              "execution": False}, result)
        proved.add("CB-0397")

        # CB-0398/0399: canonical intent-capability mapping and no authority.
        catalog = scenario("authority", "catalog")
        self.assertEqual(set(ACTIONS), set(catalog))
        for action, capability in ACTIONS.items():
            with self.subTest(action=action):
                requirement = catalog[action]["requirements"]
                self.assertEqual(capability, requirement["capability"])
                self.assertEqual("owner_required", requirement["approval_class"])
                self.assertFalse(requirement["execution"])
                self.assertFalse(requirement["authorized"])
                self.assertFalse(requirement["grant_issued"])
                self.assertFalse(requirement["approval_issued"])
                self.assertEqual("forbidden", catalog[action]["policy"]["decision"])
                self.assertIs(catalog[action]["policy"]["known"], False)
                self.assertIs(catalog[action]["policy"]["auto_grant_allowed"], False)
        proved.update(("CB-0398", "CB-0399"))

        # CB-0400/0401: strict binding, caller TTL never grants; invalid=unknown.
        freshness = scenario("policy", "freshness")
        self.assertEqual(["evidence_mismatch"], freshness["unbound_ref"]["reasons"])
        self.assertEqual(["stale_evidence"], freshness["stale_ref"]["reasons"])
        self.assertEqual(["untrusted_evidence_freshness"], freshness["raw_fresh"]["reasons"])
        self.assertTrue(all(row["execution"] is False for row in freshness.values()))
        invalid = scenario("policy", "invalid_context")
        self.assertEqual("unknown", invalid["execution"]["decision"])
        self.assertEqual(["invalid_intent"], invalid["execution"]["reasons"])
        for key, value in invalid.items():
            if key != "execution":
                self.assertEqual("deny", value["decision"], key)
                self.assertEqual(["invalid_authority"], value["reasons"], key)
        proved.update(("CB-0400", "CB-0401"))

        # CB-0404/0405: V2 uses real requirements + snapshot checks, still deny.
        trusted = scenario("policy", "trusted_valid")
        self.assertEqual({"decision": "deny", "reasons": ["grant_required"],
                          "execution": False}, trusted["current"])
        self.assertEqual(trusted["current"], trusted["last_valid_second"])
        self.assertEqual(["requirements_invalid"], trusted["no_caller_ttl"]["reasons"])
        closed = scenario("policy", "trusted_closed")
        self.assertEqual(10, len(closed))
        for row in closed.values():
            self.assertNotEqual("allow", row["decision"])
            self.assertIs(row["execution"], False)
        proved.update(("CB-0404", "CB-0405"))

        # CB-0406/07/08/09: actual local view-model projects real V1
        # deny (not a grant); owner-required is only a display fixture.
        preview = scenario("preview", "preview")
        live, owner = preview["live"], preview["owner"]
        self.assertEqual("deny", live["policy"]["decision"])
        self.assertEqual(["untrusted_evidence_freshness"], live["policy"]["reasons"])
        self.assertEqual({"required": False, "state": "unavailable"}, live["approval"])
        self.assertEqual("owner_decision_required", owner["policy"]["decision"])
        self.assertEqual({"required": True, "state": "required"}, owner["approval"])
        self.assertEqual({"intent_id", "project_ref", "repository_ref", "type",
                          "params"}, set(live["intent"]))
        for row in preview.values():
            self.assertEqual([], row["mutation_controls"])
            self.assertIs(row["execution"], False)
        safe = scenario("preview", "safe")
        self.assertIsNone(safe["sensitive"]["intent"])
        self.assertEqual([], safe["sensitive"]["evidence_refs"])
        self.assertEqual("unknown", safe["sensitive"]["policy"]["decision"])
        self.assertIsNone(safe["bad_policy"]["intent"])
        proved.update(("CB-0406", "CB-0407", "CB-0408", "CB-0409"))

        # CB-0412/13: real PHP issuer/verifier and signed *offline-only*
        # proof never issue a usable runtime grant, including the whole catalog.
        for action, capability in ACTIONS.items():
            with self.subTest(test_grant=action):
                signed = grant(grant_candidate(action))
                self.assert_not_runtime(signed)
                self.assertEqual("verified_test_only", signed["reason"])
                self.assertIs(signed["test_grant_valid"], True)
                self.assertEqual("owner_required", signed["catalog_class"])
                self.assertEqual(capability, signed["fixture_proof"]["capability"])
                self.assertEqual(64, len(signed["fixture_proof"]["signature"]))
                self.assertNotIn("key", signed)
        proved.update(("CB-0412", "CB-0413"))
        self.assertEqual(OFFLINE, proved)

    def test_falla_cerrado(self) -> None:
        """AC-02: real PHP denies malformed, mismatched or replayed inputs."""
        for key, row in scenario("policy", "trusted_closed").items():
            with self.subTest(policy=key):
                self.assertIs(row["execution"], False)
                self.assertNotEqual("allow", row["decision"])
        for key, row in scenario("preview", "safe").items():
            with self.subTest(preview=key):
                self.assertEqual([], row["mutation_controls"])
                self.assertIs(row["execution"], False)

        changes = [
            ("approval", "approved", False), ("approval", "owner", "other"),
            ("approval", "owner_verified", False),
            ("approval", "origin", "client"),
            ("approval", "scope_ref", "another/Repository"),
            ("trusted", "origin", "client"), ("trusted", "mode", "production"),
            ("trusted", "ttl_seconds", 301),
            ("requirements", "capability", "github.admin"),
        ]
        for section, field, value in changes:
            with self.subTest(section=section, field=field):
                bad = grant_candidate()
                bad[section][field] = value
                denied = grant(bad)
                self.assert_not_runtime(denied)
                self.assertIs(denied["test_grant_valid"], False)
                self.assertIsNone(denied["fixture_proof"])
        for field in ("seen", "revoked"):
            bad = grant_candidate()
            bad[field] = [bad["trusted"]["nonce"]]
            denied = grant(bad)
            self.assert_not_runtime(denied)
            self.assertIs(denied["test_grant_valid"], False)
        invalid = grant_candidate()
        invalid["approval"]["extra"] = "not-accepted"
        denied = grant(invalid)
        self.assert_not_runtime(denied)
        self.assertIs(denied["test_grant_valid"], False)

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic fixture key and read-only PHP, no runtime secrets."""
        info = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", info["phase"])
        self.assertEqual(bytes(range(32, 64)).hex(), FIXTURE_KEY)
        for path in (
            "github_intent_policy_scenarios.php",
            "github_intent_preview_scenarios.php",
            "github_intent_authority_requirements_scenarios.php",
            "github_intent_test_grant_authority_scenarios.php",
        ):
            with self.subTest(php_fixture=path):
                self.assertTrue((ROOT / "tests" / path).is_file())
        valid = grant(grant_candidate())
        self.assert_not_runtime(valid)
        self.assertNotIn("key", valid)
        self.assertNotIn("credential", json.dumps(valid).lower())
        self.assertNotIn("password", json.dumps(valid).lower())
        self.assertFalse(valid.get("runtime_authorized"))

    def test_ci_gate_contract(self) -> None:
        """AC-04: test is wired to CI; GitHub exact-HEAD Validar is separate."""
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("Pruebas de contrato e integración", workflow)
        self.assertIn("python3 scripts/generate_coverage.py", workflow)
        self.assertEqual(ALL_IDS, OFFLINE | set(NON_EXECUTABLE))


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""ControlBot #1193: 17 behavioral checks CB-0124..CB-0140.

Exercise the existing Customer Success and Decision Rights PHP classes using
synthetic fixtures. No coverage-matrix reads, network, secrets or real identities.
"""
from __future__ import annotations

from copy import deepcopy
from functools import lru_cache
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = {
    "core": "customer_success_core_scenarios.php",
    "inbox": "customer_success_owner_inbox_scenarios.php",
    "work": "customer_success_work_origin_scenarios.php",
    "rights": "decision_rights_scenarios.php",
}
POLICY = "controlbot:policy/business-os-v1"
RIGHTS_PROBE = r"""
require 'src/VentureIdentity.php';
require 'src/DecisionRights.php';
$cases=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
$out=[];
foreach($cases as $name=>$case) {
    $out[$name]=\ControlBot\Business\DecisionRights::evaluate(
        $case['context'],$case['grant'],$case['action'],2000
    );
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
"""


def php_binary() -> str:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI missing: cannot verify real behavior")
    return php


def php_run(arguments: list[str], *, payload: dict | None = None) -> dict:
    # Do not forward application credentials or other runner environment values.
    allowed = ("PATH", "LANG", "LC_ALL", "CONTROLBOT_REAL_PHP",
               "CONTROLBOT_PHP_COVERAGE_BOOTSTRAP", "CONTROLBOT_PHP_COVERAGE_DIR",
               "CONTROLBOT_REPO_ROOT", "XDEBUG_MODE")
    env = {key: os.environ[key] for key in allowed if key in os.environ}
    env.setdefault("PATH", "/usr/bin:/bin")
    env.setdefault("LANG", "C")
    result = subprocess.run(
        [php_binary(), "-d", "allow_url_fopen=0", *arguments],
        cwd=ROOT, env=env,
        input=None if payload is None else json.dumps(payload, sort_keys=True),
        capture_output=True, text=True, timeout=25, check=False,
    )
    if result.returncode:
        raise AssertionError("Local PHP behavior failed: " + result.stderr[-350:])
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("PHP behavior did not return JSON") from exc
    if not isinstance(data, dict):
        raise AssertionError("PHP behavior returned non-object data")
    return data


@lru_cache(maxsize=40)
def scenario(domain: str, name: str) -> dict:
    if domain not in SCRIPTS:
        raise AssertionError("Unknown local PHP harness")
    script = ROOT / "tests" / SCRIPTS[domain]
    if not script.is_file():
        raise AssertionError("Real PHP fixture is missing: " + script.name)
    return php_run([str(script), name])


def rights_case() -> dict:
    """Server-derived fixture, never a browser-supplied authorization claim."""
    return {
        "context": {
            "identity": {
                "version": 1, "identity_id": "identity-admin", "kind": "human",
                "display_name": "Synthetic Operator", "state": "active",
                "source_ref": "controlbot:identity/identity-admin", "observed_at": 1900,
            },
            "scope": "venture:grindflow", "active_policy_refs": [POLICY],
        },
        "grant": {
            "version": 1, "grant_id": "grant-main", "identity_id": "identity-admin",
            "role": "venture_admin", "capability": "venture.manage",
            "scope": "venture:grindflow", "authority_level": "L2_VENTURE_ADMIN",
            "policy_ref": POLICY, "budget_limit": 1000.0,
            "granted_at": 1800, "expires_at": 3000,
        },
        "action": {
            "capability": "venture.manage",
            "required_authority_level": "L2_VENTURE_ADMIN", "budget_amount": None,
        },
    }


def rights_cases() -> dict[str, dict]:
    base = rights_case()
    out = {"valid": base}
    mutations = {
        "inactive": ("context", "identity", "state", "suspended"),
        "identity-mismatch": ("context", "identity", "identity_id", "identity-other"),
        "foreign-scope": ("context", "scope", None, "venture:condor"),
        "wrong-capability": ("action", "capability", None, "venture.delete"),
        "inactive-policy": ("context", "active_policy_refs", None, []),
        "over-budget": ("action", "budget_amount", None, 1200.0),
        "low-authority": ("grant", "authority_level", None, "L1_OPERATOR"),
        "owner-action": ("action", "required_authority_level", None, "L4_OWNER"),
        "invalid-scope": ("context", "scope", None, "venture:*"),
        "untrusted-token": ("action", "identity_id", None, "identity-owner"),
        "expired-grant": ("grant", "expires_at", None, 1900),
    }
    for name, (group, field, nested, value) in mutations.items():
        case = deepcopy(base)
        if nested is None:
            case[group][field] = value
        else:
            case[group][field][nested] = value
        if name == "low-authority":
            case["grant"]["role"] = "operator"
        out[name] = case
    return out


class CoberturaControlbot10Tests(unittest.TestCase):
    """All 17 rows have existing executable offline behavior (category A)."""

    def test_contrato_principal(self) -> None:
        core_churn = scenario("core", "churn")
        core_fresh = scenario("core", "freshness")
        core_privacy = scenario("core", "privacy")
        inbox = scenario("inbox", "support")["entry"]
        inbox_closed = scenario("inbox", "closed")
        inbox_compatible = scenario("inbox", "compatible")
        inbox_explicit = scenario("inbox", "explicit")
        inbox_snapshot = scenario("inbox", "snapshot")
        work = scenario("work", "scope")["support"]
        work_explicit = scenario("work", "explicit")
        work_deterministic = scenario("work", "deterministic")
        work_limited = scenario("work", "limited")
        rights = php_run(["-r", RIGHTS_PROBE], payload=rights_cases())

        risk = next(row for row in core_churn["valid"]["dimensions"]
                    if row["name"] == "churn_risk")
        stale = next(row for row in core_fresh["stale"]["dimensions"]
                     if row["name"] == "adoption")
        unknown = next(row for row in core_fresh["unknown_dimension"]["dimensions"]
                       if row["name"] == "usage_recency")
        evidence = work["evidence_refs"]
        clauses = {
            # Core: inferred churn, freshness, and no private support payload.
            "CB-0124": (risk["nature"] == "inferred" and 0 < risk["confidence"] < 1
                        and core_churn["observed_churn_rejected"]),
            "CB-0125": (stale["freshness"] == "stale" and
                        unknown["status"] == "unknown" and
                        unknown["value_ref"] is None and
                        unknown["evidence_ref"] is None and
                        unknown["confidence"] == 0 and
                        core_fresh["unknown_with_evidence_rejected"]),
            "CB-0126": (all(core_privacy.values()) and
                        not {"transcript", "ticket_body", "attachments", "email"}
                        .intersection(scenario("core", "scope")["support"])),
            # Owner Inbox: exact Venture, no priority/authority inference.
            "CB-0127": (inbox["freshness"] == "current" and
                        inbox_closed["stale"]["freshness"] == "stale" and
                        inbox_closed["unknown"]["freshness"] == "unknown" and
                        inbox_closed["unknown"]["source_ref"] is None and
                        inbox_closed["unknown"]["evidence_refs"] == [] and
                        inbox_closed["unknown"]["observed_at"] is None),
            "CB-0128": (inbox["scope"] == {
                            "kind": "venture",
                            "ref": "controlbot:venture/venture-condor",
                        } and inbox_compatible["entry"] == inbox_compatible["again"]),
            "CB-0129": (inbox["class"] == "watch" and
                        inbox_snapshot["dimension"]["nature"] == "inferred" and
                        inbox_snapshot["entry"]["class"] == "fyi" and
                        inbox_explicit["decision"]["class"] == "decision" and
                        inbox_explicit["decision"]["required_authority_level"]
                        == "L4_OWNER"),
            # Work Origin: caller-supplied authority, UTC, opaque provenance.
            "CB-0130": (work_explicit["severity"] == "critical" and
                        work_explicit["priority_class"] == "medium" and
                        work_explicit["work_type"] == "knowledge_documentation" and
                        work_explicit["authority_level"] == "l2_team" and
                        work_explicit["requested_capabilities"] == ["support_analysis"] and
                        work_explicit["required_roles"] == ["contenido"] and
                        work_explicit["policy_ref"] == "factory:customer-success-v1"),
            "CB-0131": (work["observed_at"] == "2026-09-29T19:30:00Z" and
                        work_limited["missing_observed"] and
                        work_limited["unknown"]["observed_at"] == work["observed_at"] and
                        "freshness" not in work),
            "CB-0132": (len(evidence) >= 3 and
                        evidence == sorted(set(evidence)) and
                        any(x.startswith("controlbot:customer-success/support:")
                            for x in evidence) and
                        any(x.startswith("product:") for x in evidence) and
                        any(x.startswith("evidence:") for x in evidence)),
            "CB-0133": (work_deterministic["first"]["idempotency_key"] ==
                        work_deterministic["second"]["idempotency_key"] and
                        work_deterministic["first"]["idempotency_key"] !=
                        work_deterministic["changed"]["idempotency_key"]),
            # Decision Rights: authenticated context and server action only.
            "CB-0134": rights["valid"] == {
                "decision": "allow", "reasons": ["authorized"]},
            "CB-0135": (rights["wrong-capability"]["decision"] == "deny" and
                        rights["over-budget"]["decision"] == "owner_decision_required"),
            "CB-0136": rights["untrusted-token"] == {
                "decision": "deny", "reasons": ["invalid_input"]},
            "CB-0137": (rights["inactive"]["decision"] == "deny" and
                        "identity_inactive" in rights["inactive"]["reasons"] and
                        "identity_mismatch" in rights["identity-mismatch"]["reasons"]),
            "CB-0138": (rights["foreign-scope"]["reasons"] == ["scope_mismatch"] and
                        rights["wrong-capability"]["reasons"] == ["capability_not_granted"]),
            "CB-0139": (rights["low-authority"]["decision"] ==
                        "owner_decision_required" and
                        "authority_escalation_required" in
                        rights["low-authority"]["reasons"] and
                        "budget_approval_required" in rights["over-budget"]["reasons"] and
                        "owner_authority_required" in rights["owner-action"]["reasons"]),
            "CB-0140": (rights["inactive-policy"] == {
                            "decision": "deny", "reasons": ["policy_not_active"]} and
                        rights["wrong-capability"]["decision"] == "deny" and
                        rights["over-budget"]["decision"] != "allow"),
        }
        self.assertEqual({f"CB-{n:04d}" for n in range(124, 141)}, set(clauses))
        for row_id, condition in clauses.items():
            with self.subTest(row=row_id):
                self.assertTrue(condition, row_id + ": PHP behavior unverified")

    def test_falla_cerrado(self) -> None:
        negative = php_run(["-r", RIGHTS_PROBE], payload=rights_cases())
        for name in ("inactive", "identity-mismatch", "foreign-scope",
                     "wrong-capability", "inactive-policy", "invalid-scope",
                     "untrusted-token", "expired-grant"):
            with self.subTest(case=name):
                self.assertEqual("deny", negative[name]["decision"])
        for name in ("over-budget", "low-authority", "owner-action"):
            with self.subTest(case=name):
                self.assertEqual("owner_decision_required", negative[name]["decision"])
        self.assertEqual(negative["invalid-scope"]["reasons"], ["invalid_input"])
        self.assertEqual(negative["expired-grant"]["reasons"], ["invalid_grant"])
        self.assertTrue(all(scenario("core", "privacy").values()))
        self.assertTrue(scenario("inbox", "closed")["cross"])
        self.assertTrue(scenario("inbox", "closed")["extra"])
        self.assertTrue(scenario("inbox", "closed")["missing"])
        self.assertTrue(scenario("work", "scope")["cross"])
        self.assertTrue(scenario("work", "limited")["missing_observed"])
        for name in ("unknown", "stale", "churn"):
            with self.subTest(origin=name):
                item = scenario("work", "limited")[name]
                for forbidden in ("ready", "approved", "authorized", "executor",
                                  "provider", "model", "freshness"):
                    self.assertNotIn(forbidden, item)

    def test_sin_datos_reales(self) -> None:
        for script in SCRIPTS.values():
            with self.subTest(script=script):
                path = ROOT / "tests" / script
                self.assertTrue(path.is_file())
                self.assertEqual(path.parent, ROOT / "tests")
        synthetic = [rights_case(), scenario("core", "scope")["support"],
                     scenario("inbox", "support")["entry"],
                     scenario("work", "scope")["support"]]
        for data in synthetic:
            serialized = json.dumps(data, sort_keys=True).lower()
            for forbidden in ("ticket_body", "transcript", "message_body",
                              "attachment_content", "bearer ", "github_pat_",
                              "person@example.com", "access_token", "api_key"):
                self.assertNotIn(forbidden, serialized)
        self.assertEqual("venture:grindflow", rights_case()["context"]["scope"])
        self.assertEqual("venture-condor",
                         scenario("core", "scope")["support"]["venture_id"])


if __name__ == "__main__":
    unittest.main()

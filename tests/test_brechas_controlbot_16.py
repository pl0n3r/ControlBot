#!/usr/bin/env python3
"""ControlBot #1023: 15 offline, fail-closed coverage contracts CB-0227..0241.

Only synthetic PHP scenarios execute. A local check is not authorization,
retention, a mobile client, production availability or an actual Factory job.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
import os
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"CB-{n:04d}" for n in range(227, 242))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
MATRICES = (
    "docs/matriz-cobertura.md",
    "docs/matriz-cobertura-parte-01.md",
)
SCRIPTS = {
    "mobile": "tests/external_api_mobile_state_scenarios.php",
    "audit": "tests/external_api_mobile_audit_scenarios.php",
    "owner": "tests/external_api_owner_decision_read_scenarios.php",
    "e2e": "tests/external_api_owner_e2e_scenarios.php",
}
SOURCES = (
    "src/ExternalApiMobileState.php",
    "src/ExternalApiMobileAudit.php",
    "src/ExternalApiOwnerDecisionRead.php",
    "src/ExternalApiRequestGate.php",
    "src/ExternalApiContract.php",
)


def matrix_rows(contents: object) -> dict[str, str]:
    if type(contents) is not dict or set(contents) != set(MATRICES):
        raise ValueError(UNKNOWN + ": missing matrix")
    seen: dict[str, str] = {}
    for path in MATRICES:
        raw = contents[path]
        if type(raw) is not str or not raw:
            raise ValueError(UNKNOWN + ": missing matrix contents")
        for line in raw.splitlines():
            match = re.match(r"^\| (CB-0(?:22[7-9]|23[0-9]|24[01])) \|", line)
            if not match:
                continue
            key = match.group(1)
            cells = [part.strip() for part in line.strip("|").split("|")]
            if (key in seen or len(cells) != 6 or cells[0] != key
                    or "docs/" not in cells[1] or not cells[2]
                    or cells[3] != "NO_VERIFICABLE"
                    or "UNKNOWN" not in cells[4]
                    or not cells[5].startswith("[#")):
                raise ValueError(UNKNOWN + ": untrusted matrix row " + key)
            seen[key] = line
    if set(seen) != set(IDS):
        raise ValueError(UNKNOWN + ": missing/duplicated row")
    return seen


def read_files() -> dict[str, str]:
    return {p: (ROOT / p).read_text(encoding="utf-8")
            for p in (*MATRICES, *SOURCES)}


@lru_cache(maxsize=32)
def scenario(domain: str, case: str) -> dict:
    """Only execute the repository's audited offline PHP fixture allowlist."""
    if domain not in SCRIPTS or type(case) is not str or not case:
        raise ValueError(UNKNOWN + ": unknown synthetic fixture")
    cmd = ["php", str(ROOT / SCRIPTS[domain]), case]
    completed = subprocess.run(
        cmd, cwd=ROOT, capture_output=True, text=True,
        env={"PATH": os.defpath, "HOME": "/nonexistent", "LANG": "C"},
        timeout=35, check=False,
    )
    if completed.returncode or completed.stderr.strip():
        raise AssertionError(
            UNKNOWN + ": rejected PHP scenario " + domain + "/" + case
            + " stderr=" + completed.stderr[-220:])
    try:
        value = json.loads(completed.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError(UNKNOWN + ": malformed scenario") from exc
    if type(value) is not dict:
        raise AssertionError(UNKNOWN + ": non-object scenario")
    return value


def evidence(files: object, observations: object) -> dict[str, str]:
    """No source, incomplete witness or malformed values can imply success."""
    result = dict.fromkeys(IDS, UNKNOWN)
    if (type(files) is not dict or type(observations) is not dict
            or set(files) != set((*MATRICES, *SOURCES))
            or any(type(v) is not str or not v for v in files.values())):
        return result
    try:
        matrix_rows({p: files[p] for p in MATRICES})
    except ValueError:
        return result
    if set(observations) != {"mutation", "queue", "cache", "audit_derived",
                             "audit_trace", "audit_authz", "audit_refs",
                             "audit_sensitive", "owner_contract", "owner_invalid",
                             "owner_mismatch", "e2e_flow", "e2e_invalid"}:
        return result

    mutation = observations["mutation"]
    queue = observations["queue"]
    cache = observations["cache"]
    derived = observations["audit_derived"]
    trace = observations["audit_trace"]
    authz = observations["audit_authz"]
    refs = observations["audit_refs"]
    sensitive = observations["audit_sensitive"]
    owner = observations["owner_contract"]
    invalid = observations["owner_invalid"]
    mismatch = observations["owner_mismatch"]
    flow = observations["e2e_flow"]
    e2e_invalid = observations["e2e_invalid"]
    if any(type(x) is not dict for x in observations.values()):
        return result
    mobile_src = files["src/ExternalApiMobileState.php"]
    audit_src = files["src/ExternalApiMobileAudit.php"]
    decision_src = files["src/ExternalApiOwnerDecisionRead.php"]
    gate_src = files["src/ExternalApiRequestGate.php"]
    contract_src = files["src/ExternalApiContract.php"]

    def approved(value: object) -> bool:
        return value is True

    checks = {
        "CB-0227": (
            mutation.get("fresh", {}).get("freshness_gate") == "pass"
            and mutation.get("fresh", {}).get("queueable") is False
            and "function mutationPolicy(" in mobile_src
            and "self::snapshot($raw,$connected,$now)" in mobile_src
        ),
        "CB-0228": all(
            mutation.get(k, {}).get("freshness_gate") == "fail"
            and "snapshot_not_fresh" in mutation.get(k, {}).get("reasons", [])
            for k in ("stale", "unknown", "offline_stepup", "offline_high")
        ),
        "CB-0229": all(
            mutation.get(k, {}).get("queueable") is False
            and reason in mutation.get(k, {}).get("reasons", [])
            for k, reason in (("offline_stepup", "step_up_required"),
                              ("offline_high", "high_authority"))
        ),
        "CB-0230": (
            queue.get("queueable", {}).get("queueable") is True
            and queue.get("queueable", {}).get("freshness_gate") == "fail"
            and all(queue.get(k, {}).get("queueable") is False for k in
                    ("not_listed", "not_idempotent", "fresh"))
            and "in_array($operation,$allowlist,true)" in mobile_src
        ),
        "CB-0231": (
            cache.get("public", {}).get("persistent_cache_allowed") is True
            and cache.get("conf_plain", {}).get("persistent_cache_allowed") is False
            and cache.get("conf_encrypted", {}).get("persistent_cache_allowed") is True
            and cache.get("restricted", {}).get("persistent_cache_allowed") is False
            and approved(cache.get("sensitive_ref"))
            and approved(cache.get("extra_sensitive"))
        ),
        "CB-0232": (
            derived.get("identity_id") == "identity-owner"
            and derived.get("authority_level") == "L4_OWNER"
            and derived.get("authorization_decision") == "allow"
            and derived.get("scope") == "venture:alpha"
            and "VerifiedAccessContext" in audit_src
        ),
        "CB-0233": (
            trace.get("request_id") == "a" * 32
            and trace.get("correlation_id") == "b" * 32
            and isinstance(trace.get("audit_ref"), str)
            and trace["audit_ref"].startswith("controlbot:audit/mobile/")
            and flow.get("request_ids", {}).get("request_id") == "a" * 32
            and flow.get("request_ids", {}).get("correlation_id") == "b" * 32
        ),
        "CB-0234": (
            all(isinstance(authz.get(k), str) and bool(authz[k])
                for k in ("horizontal", "vertical"))
            and approved(sensitive.get("extra"))
            and approved(sensitive.get("secret_ref"))
            and all(approved(e2e_invalid.get(k)) for k in ("no_step", "revoked"))
        ),
        "CB-0235": (
            all(approved(invalid.get(k)) for k in
                ("wrong_scope", "revoked", "wrong_capability"))
            and "ExternalApiRequestGate::authorize(" in decision_src
            and "VerifiedAccessContext" in decision_src
        ),
        "CB-0236": (
            all(isinstance(authz.get(k), str) and bool(authz[k])
                for k in ("horizontal", "vertical"))
            and "ExternalApiRequestGate::authorize(" in audit_src
            and "VerifiedAccessContext" in audit_src
            and "VerifiedExternalSessionContext" in audit_src
        ),
        "CB-0237": (
            refs.get("handoff", {}).get("work_item_ref")
                == "controlbot:work/item-abc"
            and refs.get("handoff", {}).get("result_ref") is None
            and flow.get("approved", {}).get("work_item_ref")
                == flow.get("verification", {}).get("work_item_ref")
        ),
        "CB-0238": (
            refs.get("handoff", {}).get("outcome") == "factory_handoff"
            and refs.get("handoff", {}).get("result_ref") is None
            and refs.get("verified", {}).get("outcome") == "verification_succeeded"
            and approved(refs.get("handoff_with_result"))
            and "new PDO(" not in audit_src
            and "->prepare(" not in audit_src
            and "->execute(" not in audit_src
        ),
        "CB-0239": (
            owner.get("valid", {}).get("data", {}).get("decision_id")
                == "decision-alpha"
            and "ExternalApiRequestGate::authorize(" in decision_src
            and "'GET','/api/v1/owner-decisions/{decision_id}'" in decision_src
        ),
        "CB-0240": (
            all(approved(invalid.get(k)) for k in
                ("wrong_scope", "revoked", "wrong_capability"))
            and "'owner.decision.read'" in contract_src
            and "ExternalApiAccess::authorize(" in gate_src
            and "'mutation'" in gate_src
            and "self::assertAuthorization(" in decision_src
        ),
        "CB-0241": (
            approved(mismatch.get("requested"))
            and approved(mismatch.get("server"))
            and "hash_equals($requestedDecisionId,$decisionId)" in decision_src
        ),
    }
    if set(checks) != set(IDS):
        return result
    for key, ok in checks.items():
        if type(ok) is bool and ok:
            result[key] = LOCAL
    return result


def observed() -> dict[str, dict]:
    names = {
        "mutation": ("mobile", "mutation"),
        "queue": ("mobile", "queue"),
        "cache": ("mobile", "cache"),
        "audit_derived": ("audit", "derived"),
        "audit_trace": ("audit", "trace"),
        "audit_authz": ("audit", "authz"),
        "audit_refs": ("audit", "refs"),
        "audit_sensitive": ("audit", "sensitive"),
        "owner_contract": ("owner", "contract"),
        "owner_invalid": ("owner", "invalid_auth"),
        "owner_mismatch": ("owner", "mismatch"),
        "e2e_flow": ("e2e", "flow"),
        "e2e_invalid": ("e2e", "invalid_state"),
    }
    return {key: scenario(*value) for key, value in names.items()}


def operational(_id: object, _claims: object = None) -> str:
    return UNKNOWN


class BrechasControlbot16Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = read_files()
        cls.probes = observed()

    def test_contrato_principal(self) -> None:
        states = evidence(self.files, self.probes)
        self.assertEqual(set(IDS), set(matrix_rows({
            p: self.files[p] for p in MATRICES})))
        self.assertEqual(set(IDS), set(states))
        for key in IDS:
            with self.subTest(id=key):
                self.assertEqual(LOCAL, states[key])
                self.assertEqual(UNKNOWN, operational(key, self.probes))

    def test_falla_cerrado(self) -> None:
        for key in IDS:
            origin = next(p for p in MATRICES if any(
                line.startswith("| " + key + " |")
                for line in self.files[p].splitlines()))
            original = next(line for line in self.files[origin].splitlines()
                            if line.startswith("| " + key + " |"))
            for corrupted in (
                self.files[origin].replace(original, "", 1),
                self.files[origin] + "\n" + original,
                self.files[origin].replace(original, original.replace(
                    "NO_VERIFICABLE", "CUBIERTO", 1), 1),
            ):
                altered = dict(self.files)
                altered[origin] = corrupted
                with self.subTest(id=key), self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_rows({p: altered[p] for p in MATRICES})
                self.assertEqual({UNKNOWN}, set(evidence(altered, self.probes).values()))
        for source in (*MATRICES, *SOURCES):
            altered = dict(self.files)
            altered.pop(source)
            self.assertEqual({UNKNOWN}, set(evidence(altered, self.probes).values()))
        for name in self.probes:
            altered = dict(self.probes)
            altered[name] = {}
            self.assertEqual(UNKNOWN, evidence(self.files, altered).get({
                "mutation": "CB-0228", "queue": "CB-0230", "cache": "CB-0231",
                "audit_derived": "CB-0232", "audit_trace": "CB-0233",
                "audit_authz": "CB-0234", "audit_refs": "CB-0237",
                "audit_sensitive": "CB-0234", "owner_contract": "CB-0239",
                "owner_invalid": "CB-0235", "owner_mismatch": "CB-0241",
                "e2e_flow": "CB-0233", "e2e_invalid": "CB-0234",
            }[name]))
        self.assertEqual(UNKNOWN, operational("CB-0240", {"approval": True}))

    def test_sin_datos_reales(self) -> None:
        src = Path(__file__).read_text(encoding="utf-8")
        imports = set()
        for node in ast.walk(ast.parse(src)):
            if isinstance(node, ast.Import):
                imports.update(x.name.split(".")[0] for x in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "urllib", "socket",
                                     "httpx", "playwright", "selenium"})
        self.assertEqual(set(MATRICES) | set(SOURCES), set(self.files))
        self.assertNotIn("BEGIN " + "PRIVATE KEY", src)
        self.assertNotIn("https://", str(SCRIPTS))
        self.assertNotIn("DB_PASSWORD", str(SCRIPTS))
        self.assertEqual("UNKNOWN", operational("CB-0236", self.probes))
        self.assertEqual([p for p in MATRICES if (ROOT / p).is_file()],
                         list(MATRICES))

    def test_estados_unknown_explicitos(self) -> None:
        states = evidence(self.files, self.probes)
        self.assertEqual(set(IDS), set(states))
        self.assertEqual({LOCAL}, set(states.values()))
        for key in IDS:
            self.assertEqual(UNKNOWN, operational(key, {
                "mobile_app": "installed", "github": "green", "live": True}))
        self.assertEqual({UNKNOWN}, set(evidence(self.files, {}).values()))
        self.assertEqual({UNKNOWN}, set(evidence({}, self.probes).values()))
        self.assertNotIn("VALIDATED_IN_PRODUCTION", states.values())


if __name__ == "__main__":
    unittest.main()
